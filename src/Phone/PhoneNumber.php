<?php

namespace App\Phone;

use App\Enum\MobileMoneyCarrier;

/**
 * Numéro de téléphone mobile NORMALISÉ (objet valeur immuable).
 *
 * Représentation interne unique : format international E.164 (ex. +261341234567).
 * C'est ce format qui est stocké en base et envoyé aux passerelles SMS ; l'affichage « 034 12 345 67 »
 * est dérivé à la demande.
 */
final class PhoneNumber
{
    /** Indicatif téléphonique de Madagascar. */
    public const MADAGASCAR_COUNTRY_CODE = '261';

    private function __construct(
        /** Numéro au format E.164 : « + » suivi de 8 à 15 chiffres. */
        public readonly string $e164,
    ) {
    }

    /** Fabrique : ne vérifie QUE la forme E.164 ; passer par PhoneNumberParser pour analyser une saisie libre. */
    public static function fromE164(string $e164): self
    {
        if (1 !== preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            throw new \InvalidArgumentException('Numéro E.164 invalide : '.$e164);
        }

        return new self($e164);
    }

    /** Est-ce un numéro malgache (+261…) ? */
    public function isMalagasy(): bool
    {
        return str_starts_with($this->e164, '+'.self::MADAGASCAR_COUNTRY_CODE);
    }

    /**
     * Format national malgache à 10 chiffres (« 0341234567 »), ou null pour un numéro étranger.
     * C'est le format utilisé par la maquette pour valider les numéros Mobile Money.
     */
    public function national(): ?string
    {
        if (!$this->isMalagasy()) {
            return null;
        }

        return '0'.substr($this->e164, 4);
    }

    /** Préfixe national à 3 chiffres (« 034 »), ou null si numéro étranger. */
    public function nationalPrefix(): ?string
    {
        $national = $this->national();

        return null !== $national ? substr($national, 0, 3) : null;
    }

    /** Opérateur Mobile Money déduit du préfixe, ou null (numéro étranger / préfixe non Mobile Money). */
    public function mobileMoneyCarrier(): ?MobileMoneyCarrier
    {
        $prefix = $this->nationalPrefix();

        return null !== $prefix && 10 === \strlen((string) $this->national()) ? MobileMoneyCarrier::fromPrefix($prefix) : null;
    }

    /** Affichage lisible : « 034 12 345 67 » pour un numéro malgache, sinon le E.164 tel quel. */
    public function display(): string
    {
        $national = $this->national();
        if (null === $national) {
            return $this->e164;
        }

        // 0 34 12 345 67 → « 034 12 345 67 »
        return substr($national, 0, 3).' '.substr($national, 3, 2).' '.substr($national, 5, 3).' '.substr($national, 8, 2);
    }

    /** Affichage masqué pour les messages (« 034 •• ••• 67 ») : ne révèle pas le numéro complet. */
    public function masked(): string
    {
        $national = $this->national();
        if (null === $national) {
            return substr($this->e164, 0, 4).str_repeat('•', max(0, \strlen($this->e164) - 6)).substr($this->e164, -2);
        }

        return substr($national, 0, 3).' •• ••• '.substr($national, 8, 2);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
