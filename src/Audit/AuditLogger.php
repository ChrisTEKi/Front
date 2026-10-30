<?php

namespace App\Audit;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Écrit dans le journal d'audit (table `audit_logs`) : « qui a fait quoi, quand, depuis quelle IP ».
 *
 * Pourquoi : en cas d'incident (compte piraté, export suspect, droit accordé par erreur), le journal permet de
 * reconstituer les faits. Il est consultable par les admins ayant la permission « audit.view » et il est
 * IMMUABLE côté application (aucune méthode de modification ni de suppression).
 *
 * RÈGLE : ne JAMAIS mettre de secret dans $metadata (mot de passe, code OTP, jeton, numéro de carte).
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param string               $action     Une constante de AuditAction
     * @param User|null            $actor      Qui agit (null = visiteur anonyme ou système/console)
     * @param string|null          $targetType Type d'objet concerné (« user », « order », « role »…)
     * @param string|null          $targetId   Identifiant de l'objet concerné
     * @param array<string, mixed> $metadata   Détails utiles et non sensibles
     * @param string|null          $actorEmail Pour les actions d'un visiteur non identifié (ex. tentative de connexion ratée sur cet e-mail)
     */
    public function record(
        string $action,
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?string $actorEmail = null,
    ): void {
        // IP et navigateur de la requête en cours (absents en ligne de commande).
        $request = $this->requestStack->getCurrentRequest();

        $this->em->persist(new AuditLog(
            $this->clock->now(),
            $actor?->getId(),
            $actor?->getEmail() ?? $actorEmail,
            $action,
            $targetType,
            $targetId,
            $metadata,
            $request?->getClientIp(),
            $request?->headers->get('User-Agent'),
        ));
        $this->em->flush();
    }
}
