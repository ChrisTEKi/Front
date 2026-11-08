<?php

namespace App\Enum;

/**
 * Où en est la candidature d'une personne à une offre.
 *
 * « Retenu » est un simple repère interne : aucun e-mail n'est envoyé automatiquement (l'équipe contacte la personne
 * elle-même). Le refus, lui, envoie toujours un e-mail au nom du candidat.
 */
enum ApplicationStatus: string
{
    /** Reçue, pas encore regardée. */
    case New = 'new';

    /** En cours d'examen. */
    case Reviewing = 'reviewing';

    /** Au moins un entretien a été fixé. */
    case Interview = 'interview';

    /** Refusée (e-mail envoyé). */
    case Refused = 'refused';

    /** Retenue (repère interne). */
    case Hired = 'hired';

    /** Une décision définitive a été prise : on ne la défait pas par une nouvelle convocation. */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Refused, self::Hired], true);
    }
}
