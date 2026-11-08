<?php

namespace App\Command;

use App\Currency\ExchangeRates;
use App\Currency\ExchangeRateUnavailableException;
use App\Currency\RateRefreshReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Met à jour automatiquement les taux de change (euro et dollar contre l'Ariary).
 *
 * À planifier (cron), par exemple toutes les 6 heures :   0 0,6,12,18 * * *  php bin/console app:rates:refresh
 * Le service gratuit ne change ses taux qu'une fois par jour : inutile d'aller plus vite. Sans danger si lancée souvent :
 * un taux inchangé n'est pas réenregistré. Un taux saisi à la main par un administrateur n'est jamais écrasé.
 *
 * Code de sortie : 0 = tout va bien ; 1 = service injoignable ou valeur refusée (à surveiller dans votre supervision).
 */
#[AsCommand(name: 'app:rates:refresh', description: 'Met à jour les taux de change (EUR, USD → Ariary).')]
final class RefreshExchangeRatesCommand extends Command
{
    private const LABELS = [
        RateRefreshReport::UPDATED => 'enregistré',
        RateRefreshReport::UNCHANGED => 'inchangé',
        RateRefreshReport::LOCKED => 'taux manuel en vigueur : non modifié',
        RateRefreshReport::SUSPICIOUS => 'REFUSÉ : écart anormal avec le taux précédent',
        RateRefreshReport::MISSING => 'ABSENT de la réponse du service',
    ];

    public function __construct(private readonly ExchangeRates $rates)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $report = $this->rates->refresh();
        } catch (ExchangeRateUnavailableException $e) {
            $io->error('Taux de change indisponibles : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Devise', 'Taux (Ar pour 1 unité)', 'Résultat'], array_map(
            static fn (string $code, array $row): array => [$code, $row['rate'] ?? '—', self::LABELS[$row['status']] ?? $row['status']],
            array_keys($report->rows),
            array_values($report->rows),
        ));

        if ($report->hasProblem()) {
            $io->warning('Au moins une valeur a été refusée ou manque : vérifiez l\'écran « Devises » de l\'administration.');

            return Command::FAILURE;
        }
        $io->success('Taux à jour.');

        return Command::SUCCESS;
    }
}
