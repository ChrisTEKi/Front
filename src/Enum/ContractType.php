<?php

namespace App\Enum;

/** Type de contrat d'une offre d'emploi (libellés : translations/recruitment.<langue>.yaml, clé « contract.<valeur> »). */
enum ContractType: string
{
    case Permanent = 'permanent';
    case FixedTerm = 'fixed_term';
    case Internship = 'internship';
    case Apprenticeship = 'apprenticeship';
    case Freelance = 'freelance';
    case PartTime = 'part_time';
}
