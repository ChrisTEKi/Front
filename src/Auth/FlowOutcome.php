<?php

namespace App\Auth;

/**
 * Ce que LoginFlow répond à chaque action : « voici l'étape suivante, et éventuellement une erreur ».
 * Objet de transfert immuable, converti en JSON par AuthApiController pour le JavaScript de l'interface.
 */
final class FlowOutcome
{
    /**
     * @param list<string> $channels Canaux de 2FA proposables (« email », « sms »)
     */
    public function __construct(
        public readonly FlowStep $step,
        /** Clé d'erreur (domaine de traduction « auth », préfixe « error. »), ou null si tout va bien. */
        public readonly ?string $error = null,
        /** Destination masquée du code envoyé (« j•••@gmail.com », « 034 •• ••• 67 »). */
        public readonly ?string $maskedTarget = null,
        /** Canal du code courant : « email » ou « sms ». */
        public readonly ?string $channel = null,
        public readonly array $channels = [],
        /** Secondes avant de pouvoir demander un nouveau code. */
        public readonly int $resendIn = 0,
        /** Secondes à attendre après un refus pour cause de trop nombreuses tentatives. */
        public readonly int $retryAfter = 0,
        /** Essais restants sur le code courant (après une saisie fausse). */
        public readonly ?int $attemptsLeft = null,
        /** L'étape peut être ignorée (« Plus tard »). */
        public readonly bool $skippable = false,
        /** URL de redirection quand l'étape est « done ». */
        public readonly ?string $redirect = null,
        /** @var array<string, string> Données d'affichage de la personne (nom, e-mail). */
        public readonly array $user = [],
    ) {
    }

    public function isOk(): bool
    {
        return null === $this->error;
    }

    /** Copie avec une erreur (utile pour « même étape, mais le code était faux »). */
    public function withError(string $error, ?int $attemptsLeft = null, ?int $retryAfter = null): self
    {
        return new self(
            $this->step,
            $error,
            $this->maskedTarget,
            $this->channel,
            $this->channels,
            $this->resendIn,
            $retryAfter ?? $this->retryAfter,
            $attemptsLeft,
            $this->skippable,
            $this->redirect,
            $this->user,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->isOk(),
            'step' => $this->step->value,
            'error' => $this->error,
            'maskedTarget' => $this->maskedTarget,
            'channel' => $this->channel,
            'channels' => $this->channels,
            'resendIn' => $this->resendIn,
            'retryAfter' => $this->retryAfter,
            'attemptsLeft' => $this->attemptsLeft,
            'skippable' => $this->skippable,
            'redirect' => $this->redirect,
            'user' => $this->user,
        ];
    }
}
