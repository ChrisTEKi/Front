<?php

namespace App\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Dto\RegisterClientData;
use App\Auth\Dto\RegisterSupplierData;
use App\Auth\Exception\RegistrationBlockedException;
use App\Auth\Exception\RegistrationConflictException;
use App\Entity\Address;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Moderation\BlacklistService;
use App\Notification\SecurityNotifier;
use App\Phone\PhoneNumberParser;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Création des comptes CLIENT et FOURNISSEUR (les données sont déjà validées par le contrôleur).
 *
 * UNICITÉ : une adresse e-mail et un numéro de téléphone ne servent qu'à UN seul compte. Si l'un des deux appartient déjà à
 * un autre compte (vérifié ou non, actif ou en cours de suppression), l'inscription est REFUSÉE avec un message clair sur le
 * champ concerné (RegistrationConflictException). Conséquence assumée : le formulaire révèle qu'une adresse ou un numéro a un
 * compte. Les garde-fous contre le « sondage » en masse sont le quota d'inscriptions par IP (10 / heure) et la limite de
 * 3 avis par jour envoyés au titulaire d'une adresse sondée.
 */
final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PhoneNumberParser $phones,
        private readonly LoginFlow $flow,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly BlacklistService $blacklist,
        private readonly RateLimiterFactoryInterface $registerIpLimiter,
        private readonly RateLimiterFactoryInterface $contactChangeNoticeLimiter,
    ) {
    }

    /**
     * @throws RegistrationConflictException e-mail et/ou téléphone déjà utilisés par un autre compte
     * @throws RegistrationBlockedException   une donnée saisie figure sur la liste noire
     */
    public function registerClient(RegisterClientData $data, string $locale): FlowOutcome
    {
        if (null !== $blocked = $this->rateLimited()) {
            return $blocked;
        }

        $now = $this->clock->now();
        $phone = $this->phones->parse($data->phone);
        // Liste noire : contrôlée avant toute écriture, sur l'e-mail, le téléphone, le nom et l'adresse IP.
        $this->blacklist->assertAllowed($data->email, $phone?->e164, $data->fullName);
        $user = User::registerClient($data->email, $data->fullName, $phone?->e164, $locale, $now);

        // Adresse de livraison saisie à l'inscription (facultative) : elle devient l'adresse PAR DÉFAUT du nouveau compte.
        // Le destinataire est la personne qui s'inscrit ; son téléphone est repris s'il a été saisi.
        $address = $data->hasAddress()
            ? new Address($user, $data->fullName, $phone?->e164, $data->street, $data->city, '' !== $data->postalCode ? $data->postalCode : null, null, $now, null, true)
            : null;

        return $this->persistOrRefuse($user, $data->password, null, $address);
    }

    /**
     * @throws RegistrationConflictException e-mail et/ou téléphone déjà utilisés par un autre compte
     * @throws RegistrationBlockedException   une donnée saisie figure sur la liste noire
     */
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
        $this->blacklist->assertAllowed($data->email, $phone->e164, $data->fullName, $data->companyName);
        $user = User::registerSupplier($data->email, $data->fullName, $phone->e164, $locale, $now);
        // Dossier entreprise : statut « en attente » tant qu'un administrateur n'a pas approuvé la demande.
        $profile = new SupplierProfile($user, $data->companyName, $data->companyAddress, '' !== $data->companyDescription ? $data->companyDescription : null, $now, $data->application);

        return $this->persistOrRefuse($user, $data->password, $profile);
    }

    /**
     * Enregistre le compte, ou refuse si l'e-mail / le téléphone appartiennent déjà à quelqu'un d'autre.
     */
    private function persistOrRefuse(User $user, string $plainPassword, ?SupplierProfile $profile, ?Address $address = null): FlowOutcome
    {
        // 1) Contrôle rapide, SANS le coût du hachage : le cas courant (adresse déjà inscrite) ne mobilise pas Argon2.
        $this->refuseIfTaken($this->conflictsFor($user));

        // Hachage Argon2id du mot de passe (le clair n'est jamais conservé). Fait AVANT la transaction : il est lent et ne
        // doit pas garder les verrous plus longtemps que nécessaire.
        $user->setPasswordHash($this->hasher->hashPassword($user, $plainPassword), $this->clock->now());

        // 2) Contrôle définitif + création dans UNE transaction, sous verrou : deux inscriptions simultanées avec la même
        // adresse ou le même numéro se suivent au lieu de se croiser. (Le refus est levé APRÈS la transaction : une exception
        // qui traverse wrapInTransaction fermerait l'EntityManager.)
        try {
            $conflicts = $this->em->wrapInTransaction(function () use ($user, $profile, $address): array {
                $this->lock($user);
                $conflicts = $this->conflictsFor($user);
                if ([] !== $conflicts) {
                    return $conflicts;
                }

                $this->em->persist($user);
                if (null !== $profile) {
                    $this->em->persist($profile);
                }
                if (null !== $address) {
                    $this->em->persist($address);
                }
                $this->em->flush();

                return [];
            });
        } catch (UniqueConstraintViolationException) {
            // Ceinture et bretelles : l'index unique de la base (e-mail) a tranché malgré le verrou (autre chemin de création).
            throw new RegistrationConflictException(['email']);
        }
        $this->refuseIfTaken($conflicts);

        $this->audit->record(AuditAction::REGISTER, $user, 'user', $user->getId()->toRfc4122(), ['type' => $user->getAccountType()->value]);

        // Le compte existe : on ouvre le parcours (vérification de l'e-mail, etc.).
        return $this->flow->startAfterRegistration($user);
    }

    /**
     * Comptes qui possèdent déjà l'e-mail et/ou le téléphone du compte à créer.
     *
     * @return array<string, User> champ en conflit (« email », « phone ») => compte existant
     */
    private function conflictsFor(User $user): array
    {
        $conflicts = [];
        if (null !== $byEmail = $this->users->findOneByEmail($user->getEmail())) {
            $conflicts['email'] = $byEmail;
        }
        if (null !== $user->getPhone() && null !== $byPhone = $this->users->findOneByPhone($user->getPhone())) {
            $conflicts['phone'] = $byPhone;
        }

        return $conflicts;
    }

    /**
     * @param array<string, User> $conflicts
     *
     * @throws RegistrationConflictException
     */
    private function refuseIfTaken(array $conflicts): void
    {
        if ([] === $conflicts) {
            return;
        }
        // Le titulaire de l'adresse sondée est prévenu (3 avis par jour au plus, quel que soit le nombre d'essais).
        if (isset($conflicts['email'])) {
            $owner = $conflicts['email'];
            if ($this->contactChangeNoticeLimiter->create($owner->getId()->toRfc4122())->consume()->isAccepted()) {
                $this->notifier->alreadyRegistered($owner);
            }
        }

        throw new RegistrationConflictException(array_keys($conflicts));
    }

    /**
     * Verrous PostgreSQL (libérés à la fin de la transaction). L'e-mail est TOUJOURS verrouillé avant le téléphone :
     * un ordre constant évite qu'une inscription n'attende l'autre en boucle (interblocage).
     */
    private function lock(User $user): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'register|email|'.$user->getEmail()]);
        if (null !== $user->getPhone()) {
            $connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'register|phone|'.$user->getPhone()]);
        }
    }

    /** Quota d'inscriptions par IP (10 / heure) : freine la création en masse de faux comptes ET le sondage d'adresses. */
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
