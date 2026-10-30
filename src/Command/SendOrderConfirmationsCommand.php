<?php

namespace App\Command;

use App\Order\OrderConfirmationMailer;
use App\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renvoie les e-mails de confirmation de commande qui n'ont pas pu partir (serveur d'e-mails momentanément indisponible).
 *
 * À planifier toutes les 10 minutes (cron) :   0,10,20,30,40,50 * * * *  php bin/console app:orders:send-confirmations
 * Sans danger si lancée souvent : une commande dont l'e-mail est parti n'est jamais retraitée.
 */
#[AsCommand(name: 'app:orders:send-confirmations', description: 'Renvoie les e-mails de confirmation de commande restés en attente.')]
final class SendOrderConfirmationsCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderConfirmationMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sent = 0;
        $failed = 0;

        foreach ($this->orders->findWithoutConfirmationEmail() as $order) {
            $this->mailer->send($order) ? ++$sent : ++$failed;
        }

        $io->success(\sprintf('%d e-mail(s) envoyé(s), %d en échec (réessai au prochain passage).', $sent, $failed));

        return Command::SUCCESS;
    }
}
