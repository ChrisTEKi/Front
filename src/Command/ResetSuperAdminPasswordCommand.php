<?php

namespace App\Command;

use App\Admin\AdminAccountService;
use App\Admin\AdminActionException;
use App\Auth\PasswordPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Change le mot de passe du Super Admin depuis le serveur.
 *
 * Le Super Admin n'a volontairement PAS de « mot de passe oublié » sur le site : un attaquant qui contrôlerait sa boîte
 * e-mail pourrait sinon prendre le compte le plus puissant. Seul quelqu'un qui a un accès au serveur peut le réinitialiser.
 * Toutes les sessions ouvertes de ce compte sont déconnectées, et le titulaire reçoit un e-mail d'avis.
 */
#[AsCommand(name: 'app:admin:reset-super-password', description: 'Change le mot de passe du Super Admin (accès serveur requis).')]
final class ResetSuperAdminPasswordCommand extends Command
{
    public function __construct(
        private readonly AdminAccountService $accounts,
        private readonly PasswordPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du Super Admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) ($input->getOption('email') ?? $io->ask('Adresse e-mail du Super Admin'));

        $password = (string) $io->askHidden('Nouveau mot de passe (14 caractères minimum, saisie masquée)');
        if ($password !== (string) $io->askHidden('Confirmez le mot de passe')) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::FAILURE;
        }
        $errors = $this->policy->errors($password, $email, true);
        if ([] !== $errors) {
            $io->error(array_merge(['Mot de passe refusé :'], $errors));

            return Command::FAILURE;
        }

        try {
            $this->accounts->resetSuperAdminPassword($email, $password);
        } catch (AdminActionException) {
            $io->error('Aucun Super Admin avec cette adresse.');

            return Command::FAILURE;
        }

        $io->success('Mot de passe changé. Toutes les sessions de ce compte sont déconnectées.');

        return Command::SUCCESS;
    }
}
