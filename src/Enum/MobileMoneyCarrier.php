<?php

namespace App\Enum;

/**
 * Opérateurs Mobile Money de Madagascar et leurs préfixes de numéros.
 *
 * Règles reprises de la maquette (fonction validateMobileMoney de assets/app.js) :
 *   MVola (Telma) : 034, 038   |   Airtel Money : 033   |   Orange Money : 032, 037
 * Un numéro Mobile Money malgache = 10 chiffres commençant par l'un de ces préfixes.
 */
enum MobileMoneyCarrier: string
{
    case Mvola = 'mvola';
    case OrangeMoney = 'orange_money';
    case AirtelMoney = 'airtel_money';

    /** Libellé commercial de l'opérateur. */
    public function label(): string
    {
        return match ($this) {
            self::Mvola => 'MVola (Telma)',
            self::OrangeMoney => 'Orange Money',
            self::AirtelMoney => 'Airtel Money',
        };
    }

    /**
     * Préfixes nationaux (3 chiffres) appartenant à cet opérateur.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Mvola => ['034', '038'],
            self::OrangeMoney => ['032', '037'],
            self::AirtelMoney => ['033'],
        };
    }

    /** Retrouve l'opérateur d'après le préfixe à 3 chiffres (ex. '034' → MVola), ou null si inconnu. */
    public static function fromPrefix(string $prefix): ?self
    {
        foreach (self::cases() as $carrier) {
            if (\in_array($prefix, $carrier->prefixes(), true)) {
                return $carrier;
            }
        }

        return null;
    }
}
