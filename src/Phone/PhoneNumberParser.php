<?php

namespace App\Phone;

/**
 * Analyse une saisie libre et la transforme en PhoneNumber (E.164), ou null si ce n'est pas un mobile valide.
 *
 * Formats malgaches acceptés (tous équivalents) :
 *   034 12 345 67  |  0341234567  |  +261 34 12 345 67  |  +261341234567  |  00261341234567  |  261341234567
 * Formats étrangers acceptés : tout numéro international commençant par « + » (E.164 : 8 à 15 chiffres) — utile
 * pour la diaspora. La possession du numéro est de toute façon prouvée par le code SMS.
 */
final class PhoneNumberParser
{
    /**
     * Mobile malgache national : « 03 » + 8 chiffres = 10 chiffres au total.
     * (Les numéros fixes comme 020 22 xxx xx sont refusés : on ne peut pas y envoyer de SMS.)
     */
    private const MALAGASY_MOBILE = '/^03\d{8}$/';

    public function parse(?string $raw): ?PhoneNumber
    {
        if (null === $raw) {
            return null;
        }

        // 1) Retire espaces, tirets, points et parenthèses : « 034 12-345.67 » → « 0341234567 ».
        $clean = preg_replace('/[\s\-\.\(\)]/u', '', $raw) ?? '';
        if ('' === $clean) {
            return null;
        }

        // 2) Après nettoyage il ne doit rester qu'un « + » initial facultatif suivi de chiffres.
        if (1 !== preg_match('/^\+?\d+$/', $clean)) {
            return null;
        }

        // 3) « 00 » est l'ancien préfixe international : « 00261… » ≡ « +261… ».
        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        // 4) « 261341234567 » (sans le +) → on le traite comme un numéro malgache international.
        if (1 === preg_match('/^261\d{9}$/', $clean)) {
            $clean = '+'.$clean;
        }

        // 5) Numéro international (commence par +).
        if (str_starts_with($clean, '+')) {
            if (str_starts_with($clean, '+261')) {
                // Malgache : on le ramène au format national pour appliquer la règle « mobile 03x ».
                $national = '0'.substr($clean, 4);
                if (1 !== preg_match(self::MALAGASY_MOBILE, $national)) {
                    return null;
                }

                return PhoneNumber::fromE164('+261'.substr($national, 1));
            }

            // Étranger : forme E.164 générale.
            return 1 === preg_match('/^\+[1-9]\d{7,14}$/', $clean) ? PhoneNumber::fromE164($clean) : null;
        }

        // 6) Format national malgache « 03xxxxxxxx ».
        if (1 === preg_match(self::MALAGASY_MOBILE, $clean)) {
            return PhoneNumber::fromE164('+261'.substr($clean, 1));
        }

        return null;
    }
}
