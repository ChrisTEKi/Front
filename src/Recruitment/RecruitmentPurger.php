<?php

namespace App\Recruitment;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Repository\JobApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Effacement automatique des candidatures anciennes (données personnelles : on ne les garde pas indéfiniment).
 *
 * Durée de conservation : RETENTION_MONTHS mois après la dernière activité (la décision si elle existe, sinon le dépôt). Passé ce délai,
 * la candidature, ses pièces et ses entretiens sont supprimés. La durée (24 mois) est un choix PAR DÉFAUT à faire valider par votre
 * conseil juridique, et à indiquer dans votre politique de confidentialité.
 *
 * À planifier chaque jour (cron) :   30 3 * * *  php bin/console app:recruitment:purge
 */
final class RecruitmentPurger
{
    public const RETENTION_MONTHS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobApplicationRepository $applications,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{due: int, purged: int}
     */
    public function purgeExpired(int $limit, bool $dryRun): array
    {
        $before = $this->clock->now()->modify('-'.self::RETENTION_MONTHS.' months');
        $expired = $this->applications->findExpired($before, $limit);
        if ($dryRun || [] === $expired) {
            return ['due' => \count($expired), 'purged' => 0];
        }

        foreach ($expired as $application) {
            $this->em->remove($application);
        }
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_APPLICATIONS_PURGED, null, null, null, ['count' => \count($expired), 'retention_months' => self::RETENTION_MONTHS]);

        return ['due' => \count($expired), 'purged' => \count($expired)];
    }
}
