<?php

namespace App\Enum;

/**
 * État d'une offre d'emploi : brouillon (invisible), ouverte (visible, on peut postuler) ou clôturée (visible nulle part,
 * les candidatures déjà reçues restent consultables par l'équipe).
 */
enum JobOfferStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
