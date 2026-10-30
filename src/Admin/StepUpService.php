<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * « Réauthentification » avant une action SENSIBLE : l'administrateur doit retaper SON mot de passe.
 *
 * Pourquoi : une session ouverte peut être détournée (ordinateur laissé déverrouillé, vol de cookie). Créer un
 * administrateur, modifier des droits ou suspendre un compte ne doit pas être possible avec une simple session :
 * il faut prouver, à cet instant, qu'on connaît le mot de passe.
 *
 * Protections : 5 essais RATÉS / 15 min par administrateur (au-delà, blocage temporaire même avec le bon mot de passe ;
 * un mot de passe juste remet le compteur à zéro),
 * chaque échec est journalisé (AuditAction::STEP_UP_FAILED).
 */
final class StepUpService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $accountSensitiveLimiter,
    ) {
    }

    /**
     * @throws AdminActionException « step_up_locked » (trop d'essais) ou « step_up_failed » (mot de passe faux)
     */
    public function require(User $admin, string $password): void
    {
        $limiter = $this->accountSensitiveLimiter->create($admin->getId()->toRfc4122());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $wait = max(60, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new AdminActionException('step_up_locked', ['%minutes%' => (int) ceil($wait / 60)]);
        }

        // Un mot de passe vide ou démesuré n'est même pas haché (évite un déni de service par Argon2 sur un texte géant).
        if ('' === $password || \strlen($password) > 1024 || !$this->hasher->isPasswordValid($admin, $password)) {
            $this->audit->record(AuditAction::STEP_UP_FAILED, $admin, 'user', $admin->getId()->toRfc4122());

            throw new AdminActionException('step_up_failed');
        }

        // Mot de passe juste : le compteur d'essais repart de zéro. Il ne limite ainsi que les ESSAIS RATÉS (devinette de
        // mot de passe depuis une session volée), pas le travail légitime d'un Super Admin qui invite plusieurs personnes.
        $limiter->reset();
    }
}
