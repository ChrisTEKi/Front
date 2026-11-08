<?php

namespace App\Tests\Recruitment;

use App\Entity\ApplicationDocument;
use App\Entity\JobApplication;
use App\Recruitment\RecruitmentPurger;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\ModerationHelpers;
use App\Tests\Support\RecruitmentFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Conservation des candidatures : effacement automatique 24 mois après la dernière activité (décision, sinon dépôt).
 */
final class RecruitmentPurgeTest extends AppTestCase
{
    use ModerationHelpers;
    use RecruitmentFixtures;

    private function purger(): RecruitmentPurger
    {
        return static::getContainer()->get(RecruitmentPurger::class);
    }

    private function emails(): array
    {
        $this->em()->clear();

        return array_map(static fn (JobApplication $a): string => $a->getEmail(), $this->em()->getRepository(JobApplication::class)->findBy([], ['email' => 'ASC']));
    }

    public function testOnlyApplicationsOlderThanTwentyFourMonthsAreErased(): void
    {
        $offer = $this->offer();
        $this->candidate($offer, 'Très Ancien', 'ancien@example.com', '-30 months');
        $this->candidate($offer, 'Juste Avant', 'avant@example.com', '-23 months');
        $this->candidate($offer, 'Récent', 'recent@example.com', '-2 days');
        // Déposée il y a 30 mois mais décidée hier : la dernière activité est récente, on la garde.
        $decided = $this->candidate($offer, 'Décidé Récemment', 'decide@example.com', '-30 months');
        $this->em()->getConnection()->executeStatement("UPDATE job_applications SET decided_at = NOW() - INTERVAL '1 day', status = 'refused' WHERE id = :id", ['id' => $decided->getId()->toRfc4122()]);

        $dry = $this->purger()->purgeExpired(200, true);
        self::assertSame(['due' => 1, 'purged' => 0], $dry);
        self::assertCount(4, $this->emails(), 'Un essai à blanc n\'efface rien.');

        $result = $this->purger()->purgeExpired(200, false);

        self::assertSame(['due' => 1, 'purged' => 1], $result);
        self::assertSame(['avant@example.com', 'decide@example.com', 'recent@example.com'], $this->emails());
        self::assertContains('job.applications.purged', $this->actions('job.applications'));
    }

    public function testErasingAnApplicationErasesItsDocuments(): void
    {
        $this->candidate($this->offer(), 'Très Ancien', 'ancien@example.com', '-30 months', 'fr', true);
        self::assertSame(2, $this->em()->getRepository(ApplicationDocument::class)->count([]));

        $this->purger()->purgeExpired(200, false);

        self::assertSame(0, $this->em()->getRepository(ApplicationDocument::class)->count([]));
    }

    public function testTheCommandIsSafeToRunTwiceAndHonoursTheDryRun(): void
    {
        $this->candidate($this->offer(), 'Très Ancien', 'ancien@example.com', '-30 months');
        $application = new Application(static::bootKernel());
        $tester = new CommandTester($application->find('app:recruitment:purge'));

        $tester->execute(['--dry-run' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $this->emails());

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->emails());

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode(), 'Relancée sans rien à faire : aucune erreur.');
    }
}
