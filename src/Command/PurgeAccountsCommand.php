<?php

namespace App\Command;

use App\Account\AccountPurger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime DÉFINITIVEMENT les comptes clients dont le délai de grâce de 30 jours est écoulé.
 *
 * À planifier chaque jour (cron) :   15 3 * * *  php bin/console app:accounts:purge
 * Sans danger si lancée plusieurs fois : un compte déjà effacé n'est jamais retraité.
 *
 *   php bin/console app:accounts:purge --dry-run     → compte les comptes concernés sans rien modifier
 *   php bin/console app:accounts:purge --limit=500   → traite jusqu'à 500 comptes (200 par défaut)
 */
#[AsCommand(name: 'app:accounts:purge', description: 'Supprime définitivement les comptes clients dont le délai de 30 jours est écoulé.')]
final class PurgeAccountsCommand extends Command
{
    public function __construct(
        private readonly AccountPurger $purger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne rien modifier : afficher seulement le nombre de comptes concernés.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de comptes traités par passage.', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(1, (int) $input->getOption('limit'));

        $report = $this->purger->purgeDue($this->clock->now(), $limit, $dryRun);

        if ($dryRun) {
            $io->success(\sprintf('Simulation : %d compte(s) seraient supprimés définitivement. Rien n\'a été modifié.', $report['due']));

            return Command::SUCCESS;
        }
        if ($report['failed'] > 0) {
            $io->error(\sprintf('%d compte(s) supprimé(s), %d en échec (voir les journaux de l\'application ; réessai au prochain passage).', $report['purged'], $report['failed']));

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d compte(s) supprimé(s) définitivement.', $report['purged']));

        return Command::SUCCESS;
    }
}
