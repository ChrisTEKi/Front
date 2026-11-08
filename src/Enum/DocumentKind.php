<?php

namespace App\Enum;

/** Nature d'une pièce jointe à une candidature : un seul CV et une seule lettre de motivation par candidature. */
enum DocumentKind: string
{
    case Cv = 'cv';
    case CoverLetter = 'cover_letter';
}
