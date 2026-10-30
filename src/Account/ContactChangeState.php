<?php

namespace App\Account;

/**
 * Où en est un changement d'e-mail ou de téléphone EN COURS pour la personne connectée.
 *
 * Il est gardé dans la SESSION du serveur (jamais dans la page ni dans un cookie lisible) : le navigateur ne peut donc
 * ni sauter l'étape « code reçu à l'ancienne adresse », ni changer la valeur demandée entre deux étapes.
 * Il expire au bout de 30 minutes (voir ContactChangeService::TTL_SECONDS).
 */
final class ContactChangeState
{
    public const STEP_OLD = 'old';   // en attente du code envoyé à l'ANCIENNE adresse e-mail
    public const STEP_NEW = 'new';   // en attente du code envoyé à la NOUVELLE adresse (ou au nouveau numéro)

    public function __construct(
        /** Compte concerné (sécurité : un état ne sert jamais pour un autre compte). */
        public readonly string $userId,
        /** « email » ou « phone ». */
        public string $kind,
        /** E-mail (minuscules) ou numéro E.164 demandé ; chaîne vide = retrait du numéro. */
        public string $target,
        /** Adresse e-mail du compte au départ : si elle a changé entre-temps (autre appareil), la demande est périmée. */
        public readonly string $fromEmail,
        public string $step,
        public int $startedAt,
        /** Vrai si la nouvelle adresse appartient déjà à un autre compte : aucun code n'est alors envoyé (voir « leurre »). */
        public bool $decoy = false,
        /** Essais « fictifs » restants quand la nouvelle adresse est un leurre. */
        public int $decoyAttempts = 5,
        /** Envois « fictifs » déjà faits et date du dernier (pour imiter le délai entre deux envois). */
        public int $decoySends = 0,
        public int $decoyLastSentAt = 0,
    ) {
    }

    /** @return array<string, scalar> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromSession(mixed $raw): ?self
    {
        if (!\is_array($raw)) {
            return null;
        }
        foreach (['userId', 'kind', 'target', 'fromEmail', 'step'] as $key) {
            if (!\is_string($raw[$key] ?? null)) {
                return null;
            }
        }
        if (!\in_array($raw['kind'], ['email', 'phone'], true) || !\in_array($raw['step'], [self::STEP_OLD, self::STEP_NEW], true)) {
            return null;
        }

        return new self(
            $raw['userId'],
            $raw['kind'],
            $raw['target'],
            $raw['fromEmail'],
            $raw['step'],
            (int) ($raw['startedAt'] ?? 0),
            true === ($raw['decoy'] ?? false),
            (int) ($raw['decoyAttempts'] ?? 0),
            (int) ($raw['decoySends'] ?? 0),
            (int) ($raw['decoyLastSentAt'] ?? 0),
        );
    }

    public function isRemoval(): bool
    {
        return 'phone' === $this->kind && '' === $this->target;
    }
}
