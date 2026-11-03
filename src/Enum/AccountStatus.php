<?php

namespace App\Enum;

/**
 * État de vie d'un compte (colonne `users.status`).
 */
enum AccountStatus: string
{
    /** Compte normal. */
    case Active = 'active';

    /** Compte bloqué par un administrateur : connexion impossible. */
    case Suspended = 'suspended';

    /**
     * Le client a demandé la suppression de son compte.
     * Le compte est gelé pendant 30 jours (période de grâce) puis effacé définitivement
     * par la commande `app:accounts:purge`, sauf s'il se reconnecte et choisit de le restaurer.
     */
    case PendingDeletion = 'pending_deletion';

    /**
     * Compte BANNI DÉFINITIVEMENT par l'équipe GoBuy (clôture immédiate) : connexion impossible, aucune restauration en libre-service.
     * Le compte reste en base (l'e-mail et le téléphone restent occupés : on ne peut pas se réinscrire avec) ;
     * seul le Super Admin peut lever un bannissement.
     */
    case Banned = 'banned';
}
