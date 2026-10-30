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
}
