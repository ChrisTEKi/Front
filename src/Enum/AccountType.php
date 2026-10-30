<?php

namespace App\Enum;

/**
 * Type de compte = ce que EST l'utilisateur dans le métier GoBuy.
 *
 * Stocké dans la colonne `users.account_type` (texte). Les rôles de sécurité Symfony
 * (ROLE_CLIENT, ROLE_SUPPLIER…) sont dérivés de ce type par l'entité User.
 */
enum AccountType: string
{
    /** Acheteur : parcourt le catalogue, commande, suit ses colis. */
    case Client = 'client';

    /** Fournisseur / vendeur : publie des produits (après approbation d'un admin). */
    case Supplier = 'supplier';

    /** Membre de l'équipe GoBuy (Super Admin, admin finance, admin compta…). */
    case Admin = 'admin';
}
