<?php

namespace App\Enum;

/**
 * Devises prises en charge par la boutique.
 *
 * L'ARIARY (MGA) est la devise de référence : tous les prix de base et tous les montants de commande sont en Ariary.
 * Les autres devises servent à AFFICHER les prix (conversion au taux du jour, ou prix spécifique saisi par le vendeur).
 *
 * Les montants sont toujours manipulés en « unités mineures » (entiers) pour éviter les erreurs d'arrondi des
 * nombres à virgule : l'Ariary n'a pas de centimes (0 décimale), l'euro et le dollar en ont 2 (centimes / cents).
 */
enum Currency: string
{
    case MGA = 'MGA';
    case EUR = 'EUR';
    case USD = 'USD';

    /** Devise de référence : celle des prix de base et des commandes. */
    public const BASE = self::MGA;

    /** Nombre de décimales de la devise (0 pour l'Ariary, 2 pour l'euro et le dollar). */
    public function decimals(): int
    {
        return self::MGA === $this ? 0 : 2;
    }

    /** Symbole affiché à côté du montant. */
    public function symbol(): string
    {
        return match ($this) {
            self::MGA => 'Ar',
            self::EUR => '€',
            self::USD => '$',
        };
    }

    /** Facteur entre l'unité mineure et l'unité (1 pour l'Ariary, 100 pour l'euro et le dollar). */
    public function minorFactor(): int
    {
        return 10 ** $this->decimals();
    }

    /** Montant en unités entières pour un champ de formulaire : « 12500 » (Ariary), « 12.50 » (euro, dollar). */
    public function toMajor(int $minor): string
    {
        return number_format($minor / $this->minorFactor(), $this->decimals(), '.', '');
    }

    /**
     * Texte lisible d'un montant en unités mineures, sans mise en forme propre à une langue : « 12 500 Ar », « 12.50 € ».
     * (La mise en forme localisée est faite côté Twig/JavaScript ; ceci sert aux e-mails et aux journaux.)
     */
    public function format(int $minor): string
    {
        $value = number_format($minor / $this->minorFactor(), $this->decimals(), '.', ' ');

        return $value.' '.$this->symbol();
    }

    /**
     * @return list<string> codes des devises (« MGA », « EUR », « USD »)
     */
    public static function codes(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
