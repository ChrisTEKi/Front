<?php

namespace App\Auth;

/**
 * État du parcours de connexion d'UNE personne, conservé dans sa session PHP (côté serveur).
 *
 * Il mémorise « qui » (identifiant, jamais le mot de passe) et « ce qui est déjà validé ». Il ne contient
 * AUCUNE décision de sécurité modifiable par le client : le navigateur n'a que le cookie de session.
 * Stocké sous forme de simple tableau (pas d'objet sérialisé en session : plus sûr et plus simple à migrer).
 */
final class FlowState
{
    /** Clé dans la session. */
    public const SESSION_KEY = 'gobuy.auth_flow';

    /** Durée de vie maximale d'un parcours : 30 min. Au-delà il faut recommencer. */
    public const MAX_AGE_SECONDS = 1800;

    /** Inactivité maximale entre deux actions : 15 min. */
    public const IDLE_SECONDS = 900;

    public function __construct(
        /** UUID de l'utilisateur dont l'identité est en cours de vérification. */
        public string $userId,
        /** Comment l'identité a été prouvée : « password », « registration », « google », « apple ». */
        public string $method,
        public int $startedAt,
        public int $touchedAt,
        /** La 2FA a été franchie. */
        public bool $twoFactorDone = false,
        /** L'utilisateur a choisi « Plus tard » pour la vérification facultative du téléphone. */
        public bool $phoneSkipped = false,
        /** L'utilisateur a confirmé vouloir restaurer son compte en cours de suppression. */
        public bool $restoreConfirmed = false,
        /** Canal choisi pour la 2FA : « email » ou « sms » (null = premier canal disponible). */
        public ?string $channel = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Reconstruit l'état depuis la session ; null si le contenu est absent ou corrompu.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !isset($data['userId'], $data['method'], $data['startedAt'], $data['touchedAt'])) {
            return null;
        }

        return new self(
            (string) $data['userId'],
            (string) $data['method'],
            (int) $data['startedAt'],
            (int) $data['touchedAt'],
            (bool) ($data['twoFactorDone'] ?? false),
            (bool) ($data['phoneSkipped'] ?? false),
            (bool) ($data['restoreConfirmed'] ?? false),
            isset($data['channel']) ? (string) $data['channel'] : null,
        );
    }
}
