<?php

namespace App\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Dto\RegisterClientData;
use App\Auth\Dto\RegisterSupplierData;
use App\Entity\Address;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Notification\SecurityNotifier;
use App\Phone\PhoneNumberParser;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création des comptes CLIENT et FOURNISSEUR (les données sont déjà validées par le contrôleur).
 *
 * ANTI-ÉNUMÉRATION : si l'e-mail est DÉJÀ inscrit, on ne le dit pas à l'écran (sinon n'importe qui pourrait tester
 * des adresses pour savoir qui est client GoBuy). On affiche le même écran « saisissez le code reçu » et on envoie
 * au VRAI titulaire un e-mail « vous avez déjà un compte » (voir LoginFlow::startDecoy).
 */
final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly PhoneNumberParser $phones,
        private readonly LoginFlow $flow,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $registerIpLimiter,
    ) {
    }

    public function registerClient(RegisterClientData $data, string $locale): FlowOutcome
    {
        if (null !== $blocked = $this->rateLimited()) {
            return $blocked;
        }

        $now = $this->clock->now();
        $phone = $this->phones->parse($data->phone);
        $user = User::registerClient($data->email, $data->fullName, $phone?->e164, $locale, $now);

        // Adresse de livraison saisie à l'inscription (facultative) : elle devient l'adresse PAR DÉFAUT du nouveau compte.
        // Le destinataire est la personne qui s'inscrit ; son téléphone est repris s'il a été saisi.
        $address = $data->hasAddress()
            ? new Address($user, $data->fullName, $phone?->e164, $data->street, $data->city, '' !== $data->postalCode ? $data->postalCode : null, null, $now, null, true)
            : null;

        return $this->persistOrDecoy($user, $data->password, null, $address);
    }

    public function registerSupplier(RegisterSupplierData $data, string $locale): FlowOutcome
    {
        if (null !== $blocked = $this->rateLimited()) {
            return $blocked;
        }

        $phone = $this->phones->parse($data->phone);
        if (null === $phone) {
            // Ne devrait pas arriver (la validation l'a déjà refusé) : défense en profondeur.
            throw new \InvalidArgumentException('Téléphone fournisseur invalide.');
        }

        $now = $this->clock->now();
        $user = User::registerSupplier($data->email, $data->fullName, $phone->e164, $locale, $now);
        // Dossier entreprise : statut « en attente » tant qu'un administrateur n'a pas approuvé la demande.
        $profile = new SupplierProfile($user, $data->companyName, $data->companyAddress, '' !== $data->companyDescription ? $data->companyDescription : null, $now, $data->application);

        return $this->persistOrDecoy($user, $data->password, $profile);
    }

    /**
     * Enregistre le compte, ou déclenche le scénario « leurre » si l'adresse existe déjà.
     */
    private function persistOrDecoy(User $user, string $plainPassword, ?SupplierProfile $profile, ?Address $address = null): FlowOutcome
    {
        $existing = $this->users->findOneByEmail($user->getEmail());
        if (null !== $existing) {
            return $this->decoy($existing, $user, $plainPassword);
        }

        // Hachage Argon2id du mot de passe (le clair n'est jamais conservé).
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());

        try {
            $this->em->persist($user);
            if (null !== $profile) {
                $this->em->persist($profile);
            }
            if (null !== $address) {
                $this->em->persist($address);
            }
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Course : une inscription simultanée avec la même adresse a gagné. L'index unique de la base tranche.
            $this->em->clear();
            $existing = $this->users->findOneByEmail($user->getEmail());

            return null !== $existing ? $this->decoy($existing, $user, $plainPassword) : new FlowOutcome(FlowStep::Login, 'try_again');
        }

        $this->audit->record(AuditAction::REGISTER, $user, 'user', $user->getId()->toRfc4122(), ['type' => $user->getAccountType()->value]);

        // Le compte existe : on ouvre le parcours (vérification de l'e-mail, etc.).
        return $this->flow->startAfterRegistration($user);
    }

    private function decoy(User $existing, User $submitted, string $plainPassword): FlowOutcome
    {
        // Même durée de traitement que pour une vraie inscription (hachage Argon2) : le temps de réponse ne trahit rien.
        try {
            $this->hasherFactory->getPasswordHasher(User::class)->hash($plainPassword);
        } catch (InvalidPasswordException) {
        }

        $this->notifier->alreadyRegistered($existing);

        // Le leurre répond avec ce que le visiteur a SAISI (nom, e-mail), jamais avec les données du compte existant.
        return $this->flow->startDecoy($submitted->getEmail(), $submitted->getFullName());
    }

    /** Quota d'inscriptions par IP (10 / heure) : freine la création en masse de faux comptes. */
    private function rateLimited(): ?FlowOutcome
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->registerIpLimiter->create($ip)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return new FlowOutcome(FlowStep::Login, 'too_many_attempts', retryAfter: max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
    }
}
