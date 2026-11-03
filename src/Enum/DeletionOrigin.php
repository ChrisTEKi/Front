<?php

namespace App\Enum;

/**
 * Qui a demandé la suppression d'un compte (colonne `users.deletion_origin`).
 */
enum DeletionOrigin: string
{
    /** Le titulaire lui-même (délai de grâce de 30 jours, il peut restaurer son compte en se reconnectant). */
    case Owner = 'owner';

    /** L'équipe GoBuy (délai choisi par l'administrateur) : le titulaire ne peut PAS restaurer son compte, seul un administrateur peut annuler. */
    case Admin = 'admin';
}
