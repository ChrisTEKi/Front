<?php

namespace App\Recruitment;

/**
 * Une action de recrutement est refusée pour une raison prévue (champ invalide, offre fermée, entretien en doublon…).
 * L'API la traduit en message clair (domaine « recruitment », préfixe « error. »), voir ModerationResponder.
 */
final class RecruitmentException extends \RuntimeException
{
    /**
     * @param string               $reason clé de traduction (ex. « job_offer_closed »)
     * @param array<string, mixed> $params valeurs à insérer dans le message
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct('Action refusée : '.$reason);
    }
}
