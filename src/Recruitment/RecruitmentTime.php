<?php

namespace App\Recruitment;

/**
 * Heure des entretiens : toujours celle de MADAGASCAR (UTC+3, pas d'heure d'été), quelle que soit l'heure du serveur.
 *
 * L'administrateur saisit une date et une heure « locales » (champ datetime-local) ; on les lit à l'heure de Madagascar et on les
 * enregistre comme un instant absolu. Les e-mails et les écrans montrent la même heure, avec la mention du fuseau.
 */
final class RecruitmentTime
{
    public const ZONE = 'Indian/Antananarivo';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }

    /** Lit « 2026-11-12T09:30 » (ou avec secondes) à l'heure de Madagascar ; null si ce n'est pas une date et une heure valables. */
    public static function parse(string $local): ?\DateTimeImmutable
    {
        $local = trim($local);
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
            $at = \DateTimeImmutable::createFromFormat('!'.$format, $local, self::zone());
            // Le test d'aller-retour refuse « 2026-02-31T10:00 », que PHP corrigerait en silence en mars.
            if (false !== $at && $at->format($format) === $local) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Date et heure lisibles dans la langue du lecteur.
     *
     * @return array{date: string, time: string}
     */
    public static function format(\DateTimeImmutable $at, string $locale): array
    {
        $date = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, self::zone(), \IntlDateFormatter::GREGORIAN);
        $time = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, self::zone(), \IntlDateFormatter::GREGORIAN, 'HH:mm');

        return ['date' => (string) $date->format($at), 'time' => (string) $time->format($at)];
    }

    /** Valeur d'un champ datetime-local (« 2026-11-12T09:30 »), à l'heure de Madagascar. */
    public static function toInput(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(self::zone())->format('Y-m-d\TH:i');
    }
}
