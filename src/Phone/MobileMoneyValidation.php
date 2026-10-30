<?php

namespace App\Phone;

use App\Enum\MobileMoneyCarrier;

/**
 * Résultat de la validation d'un numéro Mobile Money (voir MobileMoneyNumberValidator).
 */
final class MobileMoneyValidation
{
    public function __construct(
        public readonly bool $valid,
        /** Clé de traduction du message (domaine « auth »), ex. « mobile_money.valid.mvola ». */
        public readonly string $messageKey,
        /** Opérateur détecté (si valide). */
        public readonly ?MobileMoneyCarrier $carrier = null,
        /** Numéro normalisé E.164 (si valide). */
        public readonly ?PhoneNumber $phone = null,
    ) {
    }
}
