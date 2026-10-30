<?php

namespace App\Auth;

/**
 * Résultat d'une action « envoyer un code » ou « vérifier un code » hors du parcours de connexion
 * (vérification du téléphone avant la 1re commande, vérification d'un numéro Mobile Money…).
 *
 * Objet de transfert immuable : le contrôleur le convertit en JSON et traduit `error` dans le domaine « auth ».
 * Les trois fabriques statiques disent clairement de quel cas il s'agit.
 */
final class VerificationResult
{
    /**
     * @param array<string, scalar|null> $data Informations supplémentaires pour l'interface (opérateur, numéro masqué…)
     */
    private function __construct(
        public readonly bool $ok,
        /** « sent » : un code vient de partir ; « verified » : c'est validé ; null en cas d'erreur. */
        public readonly ?string $state,
        /** Clé d'erreur (domaine « auth », préfixe « error. »), ou null si tout va bien. */
        public readonly ?string $error = null,
        /** Destination masquée du code (« 034 •• ••• 67 »). */
        public readonly ?string $maskedTarget = null,
        /** Secondes avant de pouvoir demander un nouveau code. */
        public readonly int $resendIn = 0,
        /** Secondes à attendre après un refus (quota, trop de tentatives). */
        public readonly int $retryAfter = 0,
        /** Essais restants sur le code courant. */
        public readonly ?int $attemptsLeft = null,
        public readonly array $data = [],
        /** Clé de traduction précise du message d'erreur, quand elle diffère de « error.<error> » (ex. règles Mobile Money). */
        public readonly ?string $messageKey = null,
    ) {
    }

    /**
     * Un code vient d'être envoyé.
     *
     * @param array<string, scalar|null> $data
     */
    public static function sent(?string $maskedTarget, int $resendIn, array $data = []): self
    {
        return new self(true, 'sent', null, $maskedTarget, $resendIn, data: $data);
    }

    /**
     * La vérification est acquise (code juste, ou numéro déjà vérifié auparavant).
     *
     * @param array<string, scalar|null> $data
     */
    public static function verified(array $data = []): self
    {
        return new self(true, 'verified', data: $data);
    }

    /**
     * Échec.
     *
     * @param array<string, scalar|null> $data
     */
    public static function failed(
        string $error,
        ?string $maskedTarget = null,
        int $resendIn = 0,
        int $retryAfter = 0,
        ?int $attemptsLeft = null,
        array $data = [],
        ?string $messageKey = null,
    ): self {
        return new self(false, null, $error, $maskedTarget, $resendIn, $retryAfter, $attemptsLeft, $data, $messageKey);
    }
}
