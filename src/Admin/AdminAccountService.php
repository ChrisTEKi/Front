<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Auth\Exception\OtpDeliveryFailedException;
use App\Auth\Exception\OtpSendRefusedException;
use App\Auth\OtpService;
use App\Auth\PasswordPolicy;
use App\Entity\AdminProfile;
use App\Entity\AdminRole;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use App\Enum\Permission;
use App\Notification\SecurityNotifier;
use App\Repository\AdminProfileRepository;
use App\Repository\AdminRoleRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Cycle de vie des comptes ADMINISTRATEUR.
 *
 *   Super Admin  : créé UNIQUEMENT en ligne de commande (app:admin:create-super), jamais depuis le site web.
 *   Autres admins: invités par le Super Admin. Le Super Admin choisit un PROFIL (Finance, Compta…) et peut ajouter
 *                  ou retirer des droits un par un. L'invité reçoit un lien (valable 48 h, usage unique) pour choisir
 *                  lui-même son mot de passe : personne d'autre ne connaît jamais ce mot de passe.
 *
 * Règles de sécurité appliquées ICI (pas seulement dans le contrôleur) :
 *   - seul un Super Admin peut inviter, modifier, suspendre ;
 *   - un admin ne peut jamais recevoir le profil « Super Admin » ni les droits admins.* ;
 *   - on ne modifie ni ne suspend un Super Admin, et personne ne se suspend soi-même ;
 *   - le mot de passe d'un admin respecte la règle renforcée (14 caractères, 3 familles) ;
 *   - chaque opération est écrite dans le journal d'audit.
 *
 * La confirmation du mot de passe du Super Admin (StepUpService) est exigée par le contrôleur avant d'appeler ce service.
 */
final class AdminAccountService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AdminProfileRepository $profiles,
        private readonly AdminRoleRepository $roles,
        private readonly OtpService $otp,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AuditLogger $audit,
        private readonly SecurityNotifier $notifier,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactoryInterface $otpVerifyIpLimiter,
    ) {
    }

    // =========================================================================
    // SUPER ADMIN (ligne de commande uniquement)
    // =========================================================================

    /**
     * Crée le compte Super Admin. Appelé par la commande console, jamais par une route web.
     *
     * @param list<string> $passwordErrors rempli avec les erreurs de mot de passe (si refus)
     *
     * @throws AdminActionException
     */
    public function createSuperAdmin(string $email, string $fullName, string $password, string $locale = 'fr'): User
    {
        if ($this->users->superAdminExists()) {
            throw new AdminActionException('super_admin_exists');
        }
        $email = mb_strtolower(trim($email));
        $this->assertEmailFree($email);
        $this->assertPassword($password, $email);

        $now = $this->clock->now();
        $user = User::createAdmin($email, $fullName, $locale, true, $now);
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
        $user->markEmailVerified($now);
        $this->em->persist($user);
        // Une ligne « profil » avec le rôle système Super Admin : le compte apparaît ainsi dans la liste des administrateurs.
        $role = $this->roles->findOneByCode(AdminRole::CODE_SUPER_ADMIN) ?? throw new AdminActionException('not_found');
        $this->em->persist(new AdminProfile($user, $role, [], [], null, $now));
        $this->em->flush();

        $this->audit->record(AuditAction::SUPER_ADMIN_CREATED, null, 'user', $user->getId()->toRfc4122(), ['channel' => 'cli'], $email);

        return $user;
    }

    /**
     * Réinitialise le mot de passe du Super Admin (commande console réservée aux serveurs : pas de « mot de passe oublié » web).
     *
     * @throws AdminActionException
     */
    public function resetSuperAdminPassword(string $email, string $password): User
    {
        $user = $this->users->findOneByEmail($email);
        if (null === $user || !$user->isSuperAdmin()) {
            throw new AdminActionException('not_found');
        }
        $this->assertPassword($password, $user->getEmail());

        $now = $this->clock->now();
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
        $user->touch($now);
        $this->em->flush();
        $this->audit->record(AuditAction::PASSWORD_CHANGED, null, 'user', $user->getId()->toRfc4122(), ['channel' => 'cli'], $user->getEmail());
        $this->notifier->passwordChanged($user);

        return $user;
    }

    // =========================================================================
    // INVITATION D'UN ADMINISTRATEUR
    // =========================================================================

    /**
     * Crée un administrateur et lui envoie son lien d'activation.
     *
     * @param list<Permission> $extra  droits ajoutés au profil
     * @param list<Permission> $denied droits retirés (restrictions)
     *
     * @throws AdminActionException
     */
    public function invite(User $actor, string $email, string $fullName, AdminRole $role, array $extra, array $denied, string $locale): User
    {
        $this->assertSuperAdmin($actor);
        $email = mb_strtolower(trim($email));
        $fullName = trim($fullName);
        if (1 !== preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) || \strlen($email) > 180) {
            throw new AdminActionException('invalid_email');
        }
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            throw new AdminActionException('invalid_name');
        }
        $this->assertAssignableRole($role);
        $this->assertEmailFree($email);

        $now = $this->clock->now();
        $user = User::createAdmin($email, $fullName, $this->normalizeLocale($locale), false, $now);
        $profile = new AdminProfile($user, $role, $extra, $denied, $actor, $now);
        $this->em->persist($user);
        $this->em->persist($profile);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Course : un autre compte s'est inscrit avec cette adresse à l'instant. L'EntityManager est fermé : on remonte l'erreur.
            throw new AdminActionException('email_taken');
        }

        $this->audit->record(AuditAction::ADMIN_INVITED, $actor, 'user', $user->getId()->toRfc4122(), [
            'email' => $email,
            'role' => $role->getCode(),
            'extra' => array_map(static fn (Permission $p): string => $p->value, $extra),
            'denied' => array_map(static fn (Permission $p): string => $p->value, $denied),
        ]);

        $this->sendInvitation($user);

        return $user;
    }

    /**
     * Renvoie le lien d'activation d'un administrateur qui n'a pas encore choisi son mot de passe.
     *
     * @throws AdminActionException
     */
    public function resendInvitation(User $actor, User $target): void
    {
        $this->assertSuperAdmin($actor);
        if (!$target->isAdmin() || $target->hasPassword()) {
            throw new AdminActionException('already_activated');
        }
        $this->sendInvitation($target);
    }

    /**
     * Réinitialise l'accès d'un administrateur déjà activé (mot de passe oublié, poste perdu, doute sur la sécurité) :
     * son mot de passe est supprimé (toutes ses sessions s'arrêtent) et un nouveau lien d'activation lui est envoyé.
     *
     * Les administrateurs n'ont pas de « mot de passe oublié » en libre-service : leur boîte e-mail reçoit déjà leur code de
     * connexion (2FA), donc quiconque contrôlerait la boîte obtiendrait les deux facteurs. Ici, un humain (le Super Admin,
     * qui confirme avec son mot de passe) décide de rendre l'accès.
     *
     * @throws AdminActionException
     */
    public function resetAccess(User $actor, User $target): void
    {
        $this->assertSuperAdmin($actor);
        $this->managedProfile($target); // refuse le Super Admin lui-même et tout compte qui n'est pas un administrateur géré
        if (!$target->hasPassword()) {
            throw new AdminActionException('not_activated'); // déjà en attente d'activation : utiliser « Renvoyer l'invitation »
        }
        if (!$target->isActive()) {
            throw new AdminActionException('account_not_active');
        }

        // D'abord le lien (si l'envoi échoue, rien n'a changé), ensuite la suppression du mot de passe.
        $this->sendInvitation($target);
        $target->removePassword($this->clock->now());
        $target->touch($this->clock->now());
        $this->em->flush();

        $this->audit->record(AuditAction::ADMIN_ACCESS_RESET, $actor, 'user', $target->getId()->toRfc4122());
    }

    /**
     * Active le compte : l'invité choisit son mot de passe grâce au lien reçu par e-mail.
     *
     * @return list<string> erreurs de mot de passe à afficher (liste vide = activation réussie)
     *
     * @throws AdminActionException « invalid_link » (lien faux, périmé ou déjà utilisé), « too_many_attempts »
     */
    public function acceptInvitation(string $userId, string $token, string $password): array
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
        $limit = $this->otpVerifyIpLimiter->create($ip)->consume();
        if (!$limit->isAccepted()) {
            throw new AdminActionException('too_many_attempts');
        }

        $user = $this->users->findOneByUuid($userId);
        // Réponse unique pour « compte inconnu », « lien faux », « lien périmé », « déjà activé » : aucune information.
        if (null === $user || !$user->isAdmin() || $user->hasPassword() || $user->isSuperAdmin() || !$user->isActive()) {
            throw new AdminActionException('invalid_link');
        }

        // Le mot de passe est contrôlé AVANT de consommer le lien : une faute de frappe ne « brûle » pas l'invitation.
        $errors = $this->passwordPolicy->errors($password, $user->getEmail(), true);
        if ([] !== $errors) {
            return $errors;
        }

        $check = $this->otp->verify($user, OtpPurpose::AdminInvitation, $user->getEmail(), $token);
        if (!$check->isValid()) {
            throw new AdminActionException('invalid_link');
        }

        $now = $this->clock->now();
        $user->setPasswordHash($this->hasher->hashPassword($user, $password), $now);
        // Avoir reçu le lien PROUVE la possession de la boîte mail : l'adresse devient vérifiée.
        $user->markEmailVerified($now);
        $user->touch($now);
        $this->em->flush();

        $this->audit->record(AuditAction::ADMIN_ACTIVATED, $user, 'user', $user->getId()->toRfc4122());

        return [];
    }

    // =========================================================================
    // MODIFICATION / SUSPENSION
    // =========================================================================

    /**
     * Change le profil et les droits individuels d'un administrateur.
     *
     * @param list<Permission> $extra
     * @param list<Permission> $denied
     *
     * @throws AdminActionException
     */
    public function updateAccess(User $actor, User $target, AdminRole $role, array $extra, array $denied): void
    {
        $this->assertSuperAdmin($actor);
        $profile = $this->managedProfile($target);
        $this->assertAssignableRole($role);

        $before = ['role' => $profile->getRole()->getCode(), 'extra' => $profile->getExtraPermissions(), 'denied' => $profile->getDeniedPermissions()];
        $now = $this->clock->now();
        $profile->changeRole($role, $now);
        $profile->setOverrides($extra, $denied, $now);
        $this->em->flush();

        $this->audit->record(AuditAction::ADMIN_UPDATED, $actor, 'user', $target->getId()->toRfc4122(), [
            'before' => $before,
            'after' => ['role' => $role->getCode(), 'extra' => $profile->getExtraPermissions(), 'denied' => $profile->getDeniedPermissions()],
        ]);
    }

    /** @throws AdminActionException */
    public function suspend(User $actor, User $target): void
    {
        $this->assertSuperAdmin($actor);
        $this->managedProfile($target);
        if ($actor->getId()->equals($target->getId())) {
            throw new AdminActionException('cannot_suspend_self');
        }

        $target->suspend();
        $target->touch($this->clock->now());
        $this->em->flush();
        // Changer le statut invalide immédiatement la session de l'admin suspendu (voir User::isEqualTo).
        $this->audit->record(AuditAction::ADMIN_SUSPENDED, $actor, 'user', $target->getId()->toRfc4122());
    }

    /** @throws AdminActionException */
    public function reactivate(User $actor, User $target): void
    {
        $this->assertSuperAdmin($actor);
        $this->managedProfile($target);

        $target->reactivate();
        $target->touch($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::ADMIN_REACTIVATED, $actor, 'user', $target->getId()->toRfc4122());
    }

    // =========================================================================
    // OUTILS INTERNES
    // =========================================================================

    private function sendInvitation(User $user): void
    {
        try {
            $this->otp->issue($user, OtpPurpose::AdminInvitation, OtpChannel::Email, $user->getEmail());
        } catch (OtpSendRefusedException $e) {
            throw new AdminActionException('too_soon', ['%seconds%' => $e->retryAfterSeconds]);
        } catch (OtpDeliveryFailedException) {
            // Le compte existe : le Super Admin peut cliquer « Renvoyer l'invitation » quand le serveur d'e-mails répond.
            throw new AdminActionException('delivery_failed');
        }
    }

    /** Le Super Admin lui-même ne se gère pas par l'interface (CLI) ; un compte non-admin non plus. */
    private function managedProfile(User $target): AdminProfile
    {
        $profile = $target->isAdmin() ? $this->profiles->findOneByUser($target) : null;
        if (null === $profile) {
            throw new AdminActionException('not_found');
        }
        if ($target->isSuperAdmin()) {
            throw new AdminActionException('cannot_modify_super_admin');
        }

        return $profile;
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (!$actor->isSuperAdmin() || !$actor->isActive()) {
            throw new AdminActionException('forbidden');
        }
    }

    private function assertAssignableRole(AdminRole $role): void
    {
        if ($role->isSuperAdminRole()) {
            throw new AdminActionException('role_not_assignable');
        }
    }

    private function assertEmailFree(string $email): void
    {
        if (null !== $this->users->findOneByEmail($email)) {
            throw new AdminActionException('email_taken');
        }
    }

    /** @throws AdminActionException */
    private function assertPassword(string $password, string $email): void
    {
        $errors = $this->passwordPolicy->errors($password, $email, true);
        if ([] !== $errors) {
            throw new AdminActionException('weak_password', ['%details%' => implode(' ', $errors)]);
        }
    }

    private function normalizeLocale(string $locale): string
    {
        return \in_array($locale, ['fr', 'en', 'es'], true) ? $locale : 'fr';
    }

    /** Type de compte attendu pour les administrateurs (utile aux tests et aux vérifications croisées). */
    public static function accountType(): AccountType
    {
        return AccountType::Admin;
    }
}
