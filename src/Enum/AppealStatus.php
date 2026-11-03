<?php

namespace App\Enum;

/**
 * Issue d'une contestation déposée par un fournisseur contre un refus (ou un retrait d'approbation) de son dossier.
 */
enum AppealStatus: string
{
    /** Déposée, en attente d'examen par l'équipe. */
    case Open = 'open';

    /** Acceptée : le dossier a été re-validé. */
    case Accepted = 'accepted';

    /** Rejetée : la décision est maintenue (un message explique pourquoi). */
    case Dismissed = 'dismissed';
}
