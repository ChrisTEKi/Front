<?php

namespace App\Command;

use App\Recruitment\RecruitmentPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Efface les candidatures dont la dernière activité date de plus de 24 mois (CV, lettres et entretiens compris).
 *
 * À planifier chaque jour (cron) :   30 3 * * *  php bin/console app:recruitment:purge
 * Sans danger si lancée plusieurs fois.
 *
 *   php bin/console app:recruitment:purge --dry-run   → compte les candidatures concernées sans rien modifier
 *   php bin/console app:recruitment:purge --limit=500 → traite jusqu'à 500 candidatures (200 par défaut)
 */
#[AsCommand(name: 'app:recruitment:purge', description: 'Efface les candidatures dont la durée de conservation (24 mois) est dépassée.')]
final class PurgeJobApplicationsCommand extends Command
{
    public function __construct(private readonly RecruitmentPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne rien modifier : afficher seulement le nombre de candidatures concernées.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de candidatures traitées par passage.', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->purger->purgeExpired(max(1, (int) $input->getOption('limit')), $dryRun);

        $io->success($dryRun
            ? \sprintf('Simulation : %d candidature(s) seraient effacées. Rien n\'a été modifié.', $report['due'])
            : \sprintf('%d candidature(s) effacée(s) définitivement.', $report['purged']));

        return Command::SUCCESS;
    }
}
