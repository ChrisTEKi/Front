<?php

namespace App\Admin;

use App\Entity\AdminProfile;
use App\Entity\AdminRole;
use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\OrderStatus;
use App\Enum\Permission;
use App\Enum\SupplierStatus;
use App\Order\OrderPresenter;
use App\Phone\PhoneNumber;
use App\Repository\AdminProfileRepository;
use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\SupplierProfileRepository;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Prépare TOUTES les données affichées par le tableau de bord administrateur.
 *
 * Pourquoi une classe dédiée : le contrôleur reste court, et la règle « chaque onglet n'est rempli que si
 * l'administrateur en a le DROIT » est écrite UNE seule fois ici. Un admin « Compta » qui n'a pas le droit
 * `users.view` ne reçoit donc même pas la liste des clients dans la page (pas seulement un onglet caché : la donnée
 * n'est jamais lue en base ni envoyée au navigateur).
 *
 * Le résultat est un simple tableau de scalaires/dates (jamais d'entités Doctrine) : Twig n'a ainsi accès qu'aux
 * champs qu'on a décidé d'exposer (pas de hash de mot de passe, pas de relations cachées).
 */
final class AdminDashboardProvider
{
    /** Taille d'une page de la liste des utilisateurs. */
    public const USERS_PER_PAGE = 25;

    /** Nombre de commandes / candidatures / lignes d'audit affichées. */
    private const ORDERS_LIMIT = 100;
    private const SUPPLIERS_LIMIT = 100;
    public const AUDIT_PER_PAGE = 50;

    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly UserRepository $users,
        private readonly SupplierProfileRepository $suppliers,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
        private readonly AdminProfileRepository $adminProfiles,
        private readonly AdminRoleRepository $roles,
        private readonly AuditLogRepository $auditLogs,
        private readonly OrderPresenter $orderPresenter,
    ) {
    }

    /**
     * @param string      $usersType type de comptes listés dans « Base Utilisateurs » : client | supplier | admin
     * @param string|null $search    texte de recherche (nom ou e-mail)
     *
     * @return array<string, mixed>
     */
    public function build(string $usersType = 'client', ?string $search = null, int $usersPage = 1, int $auditPage = 1): array
    {
        // Droits de l'admin connecté : un booléen par droit (clé = valeur de l'enum, ex. « users.view »).
        $can = [];
        foreach (Permission::cases() as $permission) {
            $can[$permission->value] = $this->auth->isGranted($permission->value);
        }

        $data = ['can' => $can, 'canManageAdmins' => $can[Permission::AdminsManage->value]];

        // ---- Compteurs de la vue d'ensemble : chacun suit le droit de lecture correspondant ----
        $data['counts'] = [
            'clients' => $can[Permission::UsersView->value] ? $this->users->countByType(AccountType::Client) : null,
            'suppliersApproved' => $can[Permission::SuppliersView->value] ? $this->suppliers->countByStatus(SupplierStatus::Approved) : null,
            'suppliersPending' => $can[Permission::SuppliersView->value] ? $this->suppliers->countByStatus(SupplierStatus::Pending) : null,
            'orders' => $can[Permission::OrdersView->value] ? $this->orders->countAll() : null,
        ];

        // ---- Fournisseurs : file d'attente et liste complète ----
        $data['pendingSuppliers'] = [];
        $data['supplierApplications'] = [];
        if ($can[Permission::SuppliersView->value]) {
            $sales = $can[Permission::FinanceView->value] ? $this->orderItems->salesTotalsBySupplier() : [];
            $data['pendingSuppliers'] = array_map(fn (SupplierProfile $p): array => $this->supplierRow($p, $sales), $this->suppliers->findByStatus(SupplierStatus::Pending));
            $data['supplierApplications'] = array_map(fn (SupplierProfile $p): array => $this->supplierRow($p, $sales), $this->suppliers->findRecent(self::SUPPLIERS_LIMIT));
        }

        // ---- Finance : total des ventes (somme réelle des lignes de commande non annulées) ----
        $data['salesTotal'] = $can[Permission::FinanceView->value] ? array_sum($this->orderItems->salesTotalsBySupplier()) : null;

        // ---- Utilisateurs : liste paginée filtrée ----
        $data['usersList'] = null;
        $type = AccountType::tryFrom($usersType) ?? AccountType::Client;
        // Les administrateurs ne se listent qu'avec le droit réservé au Super Admin ; clients et fournisseurs avec `users.view`.
        $allowed = AccountType::Admin === $type ? $can[Permission::AdminsView->value] : $can[Permission::UsersView->value];
        if ($allowed) {
            $usersPage = max(1, $usersPage);
            $total = $this->users->countSearch($type, $search);
            $data['usersList'] = [
                'type' => $type->value,
                'search' => (string) $search,
                'page' => $usersPage,
                'pages' => max(1, (int) ceil($total / self::USERS_PER_PAGE)),
                'total' => $total,
                'rows' => array_map(fn (User $u): array => $this->userRow($u), $this->users->search($type, $search, $usersPage, self::USERS_PER_PAGE)),
            ];
        }

        // ---- Commandes ----
        $data['orders'] = [];
        if ($can[Permission::OrdersView->value]) {
            $data['orders'] = array_map(fn (Order $o): array => $this->orderRow($o), $this->orders->findRecent(self::ORDERS_LIMIT));
        }

        // ---- Administrateurs, profils de droits, journal d'audit ----
        $data['admins'] = [];
        $data['roles'] = [];
        if ($can[Permission::AdminsView->value]) {
            $roleRows = $this->roles->findAllOrdered();
            $data['admins'] = array_map(fn (AdminProfile $p): array => $this->adminRow($p), $this->adminProfiles->findAllWithDetails());
            $data['roles'] = array_map(fn (AdminRole $r): array => $this->roleRow($r), $roleRows);
            $data['assignablePermissions'] = array_map(static fn (Permission $p): array => ['value' => $p->value, 'group' => $p->group()], Permission::assignable());
        }

        $data['audit'] = null;
        if ($can[Permission::AuditView->value]) {
            $auditPage = max(1, $auditPage);
            $data['audit'] = [
                'page' => $auditPage,
                'perPage' => self::AUDIT_PER_PAGE,
                'rows' => array_map(fn (AuditLog $a): array => $this->auditRow($a), $this->auditLogs->findRecent($auditPage, self::AUDIT_PER_PAGE)),
            ];
        }

        // Statuts de commande proposés dans la fenêtre de suivi.
        $data['orderStatuses'] = array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases());

        return $data;
    }

    // ------------------------------------------------------------------ Lignes

    /**
     * @param array<string, int> $sales ventes cumulées par fournisseur (vide si pas de droit finance)
     *
     * @return array<string, mixed>
     */
    private function supplierRow(SupplierProfile $profile, array $sales): array
    {
        $user = $profile->getUser();

        return [
            'id' => $user->getId()->toRfc4122(),
            'companyName' => $profile->getCompanyName(),
            'companyAddress' => $profile->getCompanyAddress(),
            'description' => $profile->getCompanyDescription(),
            // Réponses détaillées de l'assistant « Devenir partenaire » (null pour un ancien dossier) : codes, traduits à l'affichage.
            'application' => $profile->getApplication(),
            'managerName' => $user->getFullName(),
            'email' => $user->getEmail(),
            'emailVerified' => $user->isEmailVerified(),
            'phone' => $this->maskedPhone($user),
            'phoneVerified' => $user->isPhoneVerified(),
            'status' => $profile->getStatus()->value,
            'submittedAt' => $profile->getSubmittedAt(),
            'rejectionReason' => $profile->getRejectionReason(),
            'sales' => $sales[$user->getId()->toRfc4122()] ?? null,
            // Un dossier ne peut être approuvé que si e-mail ET téléphone sont vérifiés (règle appliquée aussi par le service).
            'approvable' => $user->isEmailVerified() && $user->isPhoneVerified(),
        ];
    }

    /** @return array<string, mixed> */
    private function userRow(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'name' => $user->getFullName(),
            'email' => $user->getEmail(),
            'type' => $user->getAccountType()->value,
            'status' => $user->getStatus()->value,
            'emailVerified' => $user->isEmailVerified(),
            'phone' => $this->maskedPhone($user),
            'phoneVerified' => $user->isPhoneVerified(),
            'twoFactor' => $user->isTwoFactorEnabled(),
            'createdAt' => $user->getCreatedAt(),
            'lastLoginAt' => $user->getLastLoginAt(),
            'deletionScheduledAt' => $user->getDeletionScheduledAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function orderRow(Order $order): array
    {
        return $this->orderPresenter->summary($order) + [
            'customerName' => $order->getCustomerName(),
            'courierName' => $order->getCourierName(),
            'paymentStatus' => $order->getPaymentStatus()->value,
            'closed' => \in_array($order->getStatus(), [OrderStatus::Delivered, OrderStatus::Cancelled], true),
        ];
    }

    /** @return array<string, mixed> */
    private function adminRow(AdminProfile $profile): array
    {
        $user = $profile->getUser();

        return [
            'id' => $user->getId()->toRfc4122(),
            'name' => $user->getFullName(),
            'email' => $user->getEmail(),
            'status' => $user->getStatus()->value,
            'isSuper' => $user->isSuperAdmin(),
            // Invité mais n'a pas encore choisi son mot de passe via le lien reçu par e-mail.
            'invited' => !$user->hasPassword(),
            'roleId' => $profile->getRole()->getId()->toRfc4122(),
            'roleLabel' => $profile->getRole()->getLabel(),
            'extra' => $profile->getExtraPermissions(),
            'denied' => $profile->getDeniedPermissions(),
            'effective' => $profile->effectivePermissions(),
            'lastLoginAt' => $user->getLastLoginAt(),
            'createdAt' => $profile->getCreatedAt(),
        ];
    }

    /** @return array<string, mixed> */
    private function roleRow(AdminRole $role): array
    {
        return [
            'id' => $role->getId()->toRfc4122(),
            'code' => $role->getCode(),
            'label' => $role->getLabel(),
            'description' => $role->getDescription(),
            'permissions' => $role->getPermissions(),
            'isSystem' => $role->isSystem(),
            'isSuper' => $role->isSuperAdminRole(),
            'members' => $this->adminProfiles->countByRole($role),
        ];
    }

    /** @return array<string, mixed> */
    private function auditRow(AuditLog $log): array
    {
        return [
            'at' => $log->getCreatedAt(),
            'actor' => $log->getActorEmail(),
            'action' => $log->getAction(),
            'targetType' => $log->getTargetType(),
            'targetId' => $log->getTargetId(),
            'ip' => $log->getIpAddress(),
            // Métadonnées en JSON compact (échappé par Twig à l'affichage) : lisible sans exposer d'objet.
            'details' => [] === $log->getMetadata() ? '' : (string) json_encode($log->getMetadata(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ];
    }

    /** Numéro masqué (« 034 •• ••• 67 ») : l'écran n'a pas besoin du numéro complet (il figure dans l'export CSV, tracé). */
    private function maskedPhone(User $user): ?string
    {
        $phone = $user->getPhone();

        return null === $phone ? null : PhoneNumber::fromE164($phone)->masked();
    }
}
