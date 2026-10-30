<?php

namespace App\Social;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Repository\SocialIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Transforme une identité Google/Apple validée en compte GoBuy : retrouve, relie ou crée.
 *
 * Ordre de recherche :
 *   1. identité déjà reliée (fournisseur + sub)            → c'est elle ;
 *   2. compte existant avec le même e-mail (certifié)       → on relie l'identité à ce compte ;
 *   3. sinon                                                 → création d'un compte CLIENT sans mot de passe.
 *
 * Règles de sécurité :
 *   - SEULS les comptes CLIENT peuvent utiliser la connexion rapide. Un administrateur ou un fournisseur
 *     (accès sensibles) doit passer par mot de passe + vérifications renforcées.
 *   - On ne relie par e-mail que si le fournisseur CERTIFIE l'adresse (email_verified) : sinon n'importe qui
 *     pourrait prendre le compte d'un autre en créant un compte Google avec son adresse.
 *   - « Pré-piratage » : si le compte existant n'avait jamais vérifié son e-mail, il a pu être créé par un tiers avec
 *     l'adresse de la victime et SON mot de passe. On supprime donc ce mot de passe lors de la liaison.
 *   - La 2FA reste exigée (LoginFlow) même pour une connexion sociale.
 */
final class SocialAuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SocialIdentityRepository $identities,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string      $locale   Langue de la session (pour un nouveau compte)
     * @param string|null $fallbackName Nom fourni séparément par Apple à la toute première autorisation
     *
     * @throws SocialAuthException
     */
    public function resolveUser(SocialClaims $claims, string $locale, ?string $fallbackName = null): User
    {
        $now = $this->clock->now();

        // 1) Identité déjà connue : on retrouve le compte sans même regarder l'e-mail (il a pu changer chez le fournisseur).
        $identity = $this->identities->findOneByProviderAndSubject($claims->provider, $claims->subject);
        if (null !== $identity) {
            $user = $identity->getUser();
            $this->assertClient($user);
            $identity->recordLogin($now);
            $this->em->flush();
            $this->audit->record(AuditAction::SOCIAL_LOGIN, $user, 'user', $user->getId()->toRfc4122(), ['provider' => $claims->provider->value]);

            return $user;
        }

        // Première connexion avec cette identité : une adresse certifiée est indispensable pour l'associer à un compte.
        if (null === $claims->email) {
            throw new SocialAuthException('no_email');
        }
        if (!$claims->emailVerified) {
            throw new SocialAuthException('email_unverified');
        }

        $user = $this->users->findOneByEmail($claims->email);
        $created = false;

        if (null !== $user) {
            // 2) Un compte existe avec cette adresse : on relie (clients uniquement).
            $this->assertClient($user);
            if (!$user->isEmailVerified()) {
                // Voir « pré-piratage » ci-dessus.
                $user->removePassword($now);
            }
            $user->markEmailVerified($now); // le fournisseur a certifié l'adresse
        } else {
            // 3) Nouveau compte client, sans mot de passe.
            $name = $claims->name ?? $fallbackName;
            $user = User::registerClient($claims->email, null !== $name && '' !== $name ? $name : $this->nameFromEmail($claims->email), null, $locale, $now);
            $user->markEmailVerified($now);
            $this->em->persist($user);
            $created = true;
        }

        $this->em->persist(new SocialIdentity($user, $claims->provider, $claims->subject, $claims->email, $now));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course : deux retours simultanés ont tenté de créer le même compte. La base (index uniques) tranche : on réessaie à froid.
            $this->em->clear();

            return $this->resolveUser($claims, $locale, $fallbackName);
        }

        $this->audit->record(
            $created ? AuditAction::SOCIAL_REGISTER : AuditAction::SOCIAL_LOGIN,
            $user,
            'user',
            $user->getId()->toRfc4122(),
            ['provider' => $claims->provider->value, 'linked' => !$created],
        );

        return $user;
    }

    private function assertClient(User $user): void
    {
        if (!$user->isClient()) {
            throw new SocialAuthException('client_only');
        }
    }

    /** « jean.dupont@gmail.com » → « Jean Dupont » (nom provisoire modifiable dans « Mon compte »). */
    private function nameFromEmail(string $email): string
    {
        $local = (string) strstr($email, '@', true);
        $words = preg_split('/[._\-+\d]+/', $local, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return [] === $words ? 'Client GoBuy' : implode(' ', array_map(static fn (string $w): string => mb_convert_case($w, \MB_CASE_TITLE), $words));
    }
}
