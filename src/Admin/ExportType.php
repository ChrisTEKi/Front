<?php

namespace App\Admin;

use App\Enum\Permission;

/**
 * Les quatre exports CSV de l'espace administrateur, et le droit exigé pour chacun.
 * Le « slug » est celui de l'URL (même vocabulaire que les boutons de la maquette).
 */
enum ExportType: string
{
    case Clients = 'clients';
    case Suppliers = 'fournisseurs';
    case Admins = 'admins';
    case Orders = 'commandes';

    /** Droit nécessaire. Les admins.* sont réservés au Super Admin ; les commandes demandent le droit « finance.view ». */
    public function permission(): Permission
    {
        return match ($this) {
            self::Clients => Permission::UsersExport,
            self::Suppliers => Permission::SuppliersExport,
            self::Admins => Permission::AdminsExport,
            self::Orders => Permission::FinanceView,
        };
    }
}
