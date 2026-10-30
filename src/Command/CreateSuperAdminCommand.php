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
 * Crée LE compte Super Admin. C'est la SEULE façon d'en créer un : aucune page web ne le permet.
 *
 *     php bin/console app:admin:create-super --email=direction@exemple.mg --name="Nom Prénom"
 *
 * Le mot de passe est demandé en saisie MASQUÉE (jamais en argument : il resterait dans l'historique du terminal et
 * dans la liste des processus). Pour un déploiement automatisé, on peut le fournir par la variable d'environnement
 * GOBUY_SUPER_ADMIN_PASSWORD, à retirer juste après.
 *
 * Refuse de s'exécuter si un Super Admin existe déjà.
 * Règle de mot de passe : 14 caractères minimum, 3 familles de caractères, absent des fuites publiques connues.
 */
#[AsCommand(name: 'app:admin:create-super', description: 'Crée le compte Super Admin (seul moyen d\'en créer un).')]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly AdminAccountService $accounts,
        private readonly PasswordPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du Super Admin')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom complet')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Langue (fr, en, es)', 'fr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) ($input->getOption('email') ?? $io->ask('Adresse e-mail du Super Admin'));
        $name = (string) ($input->getOption('name') ?? $io->ask('Nom complet'));

        $password = getenv('GOBUY_SUPER_ADMIN_PASSWORD') ?: '';
        if ('' === $password) {
            $password = (string) $io->askHidden('Mot de passe (14 caractères minimum, saisie masquée)');
            $confirm = (string) $io->askHidden('Confirmez le mot de passe');
            if ($password !== $confirm) {
                $io->error('Les deux mots de passe ne correspondent pas.');

                return Command::FAILURE;
            }
        }

        $errors = $this->policy->errors($password, $email, true);
        if ([] !== $errors) {
            $io->error(array_merge(['Mot de passe refusé :'], $errors));

            return Command::FAILURE;
        }

        try {
            $user = $this->accounts->createSuperAdmin($email, $name, $password, (string) $input->getOption('locale'));
        } catch (AdminActionException $e) {
            $io->error(match ($e->reason) {
                'super_admin_exists' => 'Un Super Admin existe déjà. Pour changer son mot de passe : app:admin:reset-super-password.',
                'email_taken' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
                default => 'Création impossible : '.$e->reason,
            });

            return Command::FAILURE;
        }

        $io->success(\sprintf('Super Admin créé : %s. À la première connexion, un code reçu par e-mail sera demandé (double authentification obligatoire).', $user->getEmail()));

        return Command::SUCCESS;
    }
}
