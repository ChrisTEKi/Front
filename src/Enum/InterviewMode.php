<?php

namespace App\Enum;

/** Façon de passer un entretien : par téléphone (l'équipe appelle le candidat) ou sur place (adresse communiquée). */
enum InterviewMode: string
{
    case Phone = 'phone';
    case InPerson = 'in_person';
}
