<?php

namespace App\Phone;

use App\Enum\MobileMoneyCarrier;

/**
 * Validation côté SERVEUR d'un numéro Mobile Money malgache.
 *
 * Reproduit EXACTEMENT les règles de la fonction `validateMobileMoney()` de assets/app.js
 * (qui ne sert qu'au confort de saisie : seule cette version fait foi, car le navigateur peut être contourné) :
 *   1. le numéro doit commencer par 0 (ou +261, converti en 0),
 *   2. il doit comporter exactement 10 chiffres,
 *   3. son préfixe doit appartenir à un opérateur : MVola 034/038, Airtel 033, Orange 032/037.
 */
final class MobileMoneyNumberValidator
{
    public function validate(?string $raw): MobileMoneyValidation
    {
        // Retire tous les espaces ; « +261… » ou « 00261… » ou « 261… » deviennent « 0… ».
        $clean = preg_replace('/\s+/', '', (string) $raw) ?? '';
        $clean = preg_replace('/^(\+261|00261|261)/', '0', $clean) ?? '';

        // Règle 1 : doit débuter par 0.
        if (!str_starts_with($clean, '0')) {
            return new MobileMoneyValidation(false, 'mobile_money.invalid_start');
        }

        // Règle 2 : 10 chiffres exactement, uniquement des chiffres.
        if (10 !== \strlen($clean) || !ctype_digit($clean)) {
            return new MobileMoneyValidation(false, 'mobile_money.invalid_length');
        }

        // Règle 3 : le préfixe désigne l'opérateur.
        $carrier = MobileMoneyCarrier::fromPrefix(substr($clean, 0, 3));
        if (null === $carrier) {
            return new MobileMoneyValidation(false, 'mobile_money.unknown_carrier');
        }

        return new MobileMoneyValidation(
            true,
            'mobile_money.valid.'.$carrier->value,
            $carrier,
            PhoneNumber::fromE164('+261'.substr($clean, 1)),
        );
    }
}
