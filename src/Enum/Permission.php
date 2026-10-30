<?php

namespace App\Enum;

/**
 * Catalogue des droits fins de l'espace administrateur.
 *
 * Un droit est une chaîne "ressource.action". Les droits effectifs d'un admin =
 *   droits de son PROFIL (ex. « Finance »)  +  droits ajoutés individuellement  −  droits retirés (restrictions).
 * Le Super Admin possède tous les droits implicitement (voir PermissionVoter).
 *
 * Utilisation dans un contrôleur :  #[IsGranted('suppliers.approve')]
 */
enum Permission: string
{
    // ---- Clients ----
    case UsersView = 'users.view';
    case UsersSuspend = 'users.suspend';
    case UsersExport = 'users.export';

    // ---- Fournisseurs ----
    case SuppliersView = 'suppliers.view';
    case SuppliersApprove = 'suppliers.approve';
    case SuppliersExport = 'suppliers.export';

    // ---- Commandes ----
    case OrdersView = 'orders.view';
    case OrdersUpdateTracking = 'orders.update_tracking';

    // ---- Finance ----
    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';

    // ---- Journal d'audit ----
    case AuditView = 'audit.view';

    // ---- Gestion des administrateurs : RÉSERVÉ AU SUPER ADMIN (jamais attribuable) ----
    case AdminsView = 'admins.view';
    case AdminsManage = 'admins.manage';
    case AdminsExport = 'admins.export';

    /**
     * Vrai si ce droit peut être donné à un admin « normal ».
     * Faux = exclusivement réservé au Super Admin (créer des admins, les voir, les exporter).
     */
    public function isAssignable(): bool
    {
        return !\in_array($this, [self::AdminsView, self::AdminsManage, self::AdminsExport], true);
    }

    /** Regroupement d'affichage dans le formulaire de création d'admin. */
    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * Tous les droits attribuables à un admin normal (le formulaire n'en propose pas d'autres).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p) => $p->isAssignable()));
    }

    /**
     * Valeurs texte de tous les droits (utile pour valider une saisie).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
