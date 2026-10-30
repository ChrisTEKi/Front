<?php

namespace App\Auth;

use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Entity\OneTimeCode;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Notification\OtpDeliveryInterface;
use App\Repository\OneTimeCodeRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Cœur de la sécurité des codes à usage unique (OTP).
 *
 * Responsabilités :
 *   issue()  : fabriquer un code, le stocker HACHÉ, annuler les précédents, l'envoyer (avec délai et quotas).
 *   verify() : contrôler un code saisi (expiration, plafond d'essais, usage unique, comparaison à temps constant).
 *
 * Choix de sécurité (chacun répond à une attaque précise) :
 *   - code aléatoire cryptographique (random_int)            → imprévisible ;
 *   - stocké sous forme d'HMAC-SHA256 avec le secret serveur → une fuite de la base ne révèle pas les codes ;
 *   - HMAC lié à (finalité, utilisateur, destination)       → un code ne sert ni ailleurs ni pour un autre numéro ;
 *   - 5 essais max par code, expiration 10 min               → 10^6 combinaisons impossibles à deviner ;
 *   - verrou de ligne PostgreSQL pendant la vérification     → deux requêtes simultanées ne peuvent ni contourner
 *                                                               le plafond d'essais ni consommer le code deux fois ;
 *   - délai entre envois + quotas par utilisateur / par numéro → pas de spam ni de « SMS pumping » (facture SMS).
 */
final class OtpService
{
    /** Durée de validité d'un lien d'invitation administrateur : 48 h (au lieu de 10 min pour un code saisi à la main). */
    public const INVITATION_TTL_SECONDS = 172800;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OneTimeCodeRepository $codes,
        private readonly OtpDeliveryInterface $delivery,
        private readonly ClockInterface $clock,
        // Secret de l'application (APP_SECRET) : clé de l'HMAC.
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%app.otp.length%')]
        private readonly int $length,
        #[Autowire('%app.otp.ttl_seconds%')]
        private readonly int $ttlSeconds,
        #[Autowire('%app.otp.max_attempts%')]
        private readonly int $maxAttempts,
        #[Autowire('%app.otp.resend_cooldown%')]
        private readonly int $resendCooldown,
        // Limiteurs de débit définis dans config/packages/rate_limiter.yaml.
        private readonly RateLimiterFactoryInterface $otpSendUserLimiter,
        private readonly RateLimiterFactoryInterface $smsDailyPhoneLimiter,
        private readonly RateLimiterFactoryInterface $smsSendIpLimiter,
        private readonly RateLimiterFactoryInterface $smsDailyUserLimiter,
        private readonly RateLimiterFactoryInterface $otpFailUserLimiter,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Génère un code pour (utilisateur, finalité, destination), l'enregistre et l'envoie.
     *
     * @param string $target Adresse e-mail ou numéro E.164 qui recevra le code
     *
     * @throws OtpSendRefusedException    trop tôt / quota dépassé
     * @throws OtpDeliveryFailedException l'e-mail ou le SMS n'a pas pu partir (le code est alors annulé)
     */
    public function issue(User $user, OtpPurpose $purpose, OtpChannel $channel, string $target): OneTimeCode
    {
        $now = $this->clock->now();

        // 1) Délai minimal entre deux envois (ex. 30 s) : évite les clics répétés et le harcèlement.
        $latest = $this->codes->findLatest($user, $purpose);
        if (null !== $latest) {
            $elapsed = $now->getTimestamp() - $latest->getCreatedAt()->getTimestamp();
            if ($elapsed < $this->resendCooldown) {
                throw new OtpSendRefusedException($this->resendCooldown - $elapsed, 'cooldown');
            }
        }

        // 2) Quota par utilisateur et finalité (3 envois / 10 min).
        $limit = $this->otpSendUserLimiter->create($purpose->value.'|'.$user->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new OtpSendRefusedException($this->secondsUntil($limit->getRetryAfter(), $now), 'quota');
        }

        // 3) Quotas propres aux SMS (chaque SMS coûte de l'argent et peut harceler un tiers : « SMS pumping »).
        //    Un numéro peut être saisi librement (téléphone du compte, numéro Mobile Money) : sans ces plafonds, un compte jetable
        //    pourrait déclencher des centaines de SMS par jour vers des numéros qui ne sont pas les siens.
        if (OtpChannel::Sms === $channel) {
            //  a) par numéro de téléphone : 8 SMS / jour, même si ce sont des comptes ou des finalités différents ;
            //  b) par compte : 10 SMS / jour toutes finalités confondues ;
            //  c) par adresse IP : 15 SMS / heure (les comptes jetables créés depuis une même origine partagent ce plafond).
            $limiters = [
                $this->smsDailyPhoneLimiter->create($target),
                $this->smsDailyUserLimiter->create($user->getId()->toRfc4122()),
            ];
            if (null !== $ip = $this->requestStack->getCurrentRequest()?->getClientIp()) {
                $limiters[] = $this->smsSendIpLimiter->create($ip);
            }
            foreach ($limiters as $limiter) {
                $limit = $limiter->consume();
                if (!$limit->isAccepted()) {
                    throw new OtpSendRefusedException($this->secondsUntil($limit->getRetryAfter(), $now), 'quota');
                }
            }
        }

        // 4) Fabrique le code en clair (sera envoyé) et son empreinte (sera stockée).
        $plain = $this->generate($purpose);
        $ttl = $purpose->isLongToken() ? self::INVITATION_TTL_SECONDS : $this->ttlSeconds;
        $entity = new OneTimeCode($user, $purpose, $channel, $target, $this->hash($plain, $purpose, $user, $target), $now, $ttl, $this->maxAttempts);

        // 5) Annule les anciens codes puis enregistre le nouveau, ensemble (transaction) :
        //    il n'existe jamais deux codes valides en même temps pour la même finalité.
        $this->em->wrapInTransaction(function () use ($user, $purpose, $now, $entity): void {
            $this->codes->invalidateActive($user, $purpose, $now);
            $this->em->persist($entity);
        });

        // 6) Envoi APRÈS validation de la transaction. Si l'envoi échoue, on retire le code pour ne pas
        //    imposer à l'utilisateur un délai d'attente pour un code qu'il n'a jamais reçu.
        try {
            $this->delivery->deliver($user, $purpose, $channel, $target, $plain, $entity->getExpiresAt());
        } catch (\Throwable $e) {
            $this->em->remove($entity);
            $this->em->flush();

            throw $e instanceof OtpDeliveryFailedException ? $e : new OtpDeliveryFailedException('Échec d\'envoi du code.', 0, $e);
        }

        return $entity;
    }

    /**
     * Vérifie un code saisi. En cas de succès, le code est consommé (usage unique).
     *
     * @param string $target Destination attendue (e-mail / numéro). Doit être celle à laquelle le code a été envoyé.
     */
    public function verify(User $user, OtpPurpose $purpose, string $target, string $submitted): OtpCheck
    {
        // Tolère « 123 456 » ou « 123-456 » : on ne garde que les caractères utiles.
        $submitted = $purpose->isLongToken()
            ? trim($submitted)
            : (string) preg_replace('/\D+/', '', $submitted);

        // Plafond d'échecs CUMULÉS (tous codes confondus) pour ce compte et cette finalité : 15 par fenêtre de 6 h.
        // Le plafond « 5 essais » ci-dessous protège UN code ; mais redemander un code en crée un neuf avec 5 essais de plus.
        // Ce deuxième compteur empêche d'enchaîner les codes pour deviner à force (6 chiffres = 1 chance sur 1 000 000).
        // consume(0) = « regarder sans compter » ; plus aucun jeton restant = bloqué, même avec le bon code.
        $failures = $this->otpFailUserLimiter->create($user->getId()->toRfc4122().'|'.$purpose->value);
        if ($failures->consume(0)->getRemainingTokens() < 1) {
            return new OtpCheck(OtpResult::Locked);
        }

        // Toute la vérification se déroule dans UNE transaction avec verrou de ligne (voir plus bas).
        return $this->em->wrapInTransaction(function () use ($user, $purpose, $target, $submitted, $failures): OtpCheck {
            $now = $this->clock->now();

            $code = $this->codes->findActive($user, $purpose);
            if (null === $code || $code->getTarget() !== $target) {
                // Pas de code, ou code destiné à une autre adresse/un autre numéro (ex. numéro modifié depuis).
                return new OtpCheck(OtpResult::Expired);
            }

            // Verrou pessimiste (SELECT … FOR UPDATE) + relecture : tant que cette requête n'a pas fini,
            // une requête parallèle sur le MÊME code attend. Sans cela, 100 requêtes simultanées pourraient
            // toutes lire « attempts = 0 » et contourner le plafond d'essais.
            $this->em->refresh($code, LockMode::PESSIMISTIC_WRITE);

            if ($code->isConsumed() || $code->isExpired($now)) {
                return new OtpCheck(OtpResult::Expired);
            }
            if ($code->isLocked()) {
                return new OtpCheck(OtpResult::Locked);
            }

            // Comparaison à TEMPS CONSTANT (hash_equals) : ne révèle pas « combien de caractères sont corrects ».
            if (!hash_equals($code->getCodeHash(), $this->hash($submitted, $purpose, $user, $target))) {
                $code->registerFailedAttempt();
                $failures->consume(1); // un échec de plus sur ce compte (voir le plafond cumulé en tête de méthode)
                // Le flush est fait par wrapInTransaction : l'essai raté est bien enregistré.
                return $code->isLocked()
                    ? new OtpCheck(OtpResult::Locked)
                    : new OtpCheck(OtpResult::Invalid, $this->maxAttempts - $code->getAttempts());
            }

            // Succès : le code ne servira plus jamais, et les échecs précédents (fautes de frappe) sont oubliés.
            $code->consume($now);
            $failures->reset();

            return new OtpCheck(OtpResult::Valid);
        });
    }

    /** Un code actif (utilisable) existe-t-il ? Sert à ne pas renvoyer de code inutilement à l'entrée d'une étape. */
    public function hasActiveCode(User $user, OtpPurpose $purpose, string $target): bool
    {
        $code = $this->codes->findActive($user, $purpose);

        return null !== $code && $code->getTarget() === $target && $code->isUsable($this->clock->now());
    }

    /** Secondes restantes avant le prochain envoi autorisé (0 si on peut renvoyer tout de suite). */
    public function secondsBeforeResend(User $user, OtpPurpose $purpose): int
    {
        $latest = $this->codes->findLatest($user, $purpose);
        if (null === $latest) {
            return 0;
        }

        return max(0, $this->resendCooldown - ($this->clock->now()->getTimestamp() - $latest->getCreatedAt()->getTimestamp()));
    }

    // ------------------------------------------------------------------ Outils internes

    /**
     * Code en clair : N chiffres aléatoires (avec zéros devant), ou long jeton URL-safe pour une invitation.
     * random_int()/random_bytes() utilisent le générateur cryptographique du système d'exploitation.
     */
    private function generate(OtpPurpose $purpose): string
    {
        if ($purpose->isLongToken()) {
            // 32 octets aléatoires = 256 bits, encodés en base64 « URL-safe » (43 caractères, sans « = »).
            return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        return str_pad((string) random_int(0, 10 ** $this->length - 1), $this->length, '0', STR_PAD_LEFT);
    }

    /**
     * Empreinte HMAC-SHA256 stockée en base. Les trois valeurs liées au code (finalité, utilisateur, destination)
     * sont dans le message signé : changer l'une d'elles change l'empreinte, donc le code ne valide plus.
     */
    private function hash(string $plain, OtpPurpose $purpose, User $user, string $target): string
    {
        return hash_hmac('sha256', implode('|', [$purpose->value, $user->getId()->toRfc4122(), $target, $plain]), $this->secret);
    }

    /** Convertit la date de réessai du limiteur en secondes d'attente (au moins 1). */
    private function secondsUntil(\DateTimeImmutable $retryAt, \DateTimeImmutable $now): int
    {
        return max(1, $retryAt->getTimestamp() - $now->getTimestamp());
    }
}
