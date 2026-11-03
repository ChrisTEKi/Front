<?php

namespace App\Enum;

/**
 * Délais proposés à un administrateur pour la suppression définitive d'un compte client ou fournisseur.
 *
 * « Immédiat » efface le compte tout de suite (refusé s'il reste des commandes en cours) ; les autres délais laissent le temps de
 * rembourser, de contester ou de changer d'avis : pendant l'attente le compte est fermé (plus de connexion, plus de vente) et
 * un administrateur peut annuler à tout moment. La suppression elle-même est faite par `app:accounts:purge` (cron quotidien).
 */
enum DeletionDelay: string
{
    case Immediate = 'immediate';
    case Hours24 = '24h';
    case Hours48 = '48h';
    case Hours72 = '72h';
    case Days7 = '7d';
    case Days14 = '14d';
    case Days30 = '30d';
    case Months3 = '3m';
    case Months6 = '6m';
    case Year1 = '1y';

    /** Date de suppression pour une décision prise à `$now`. */
    public function dueFrom(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return match ($this) {
            self::Immediate => $now,
            self::Hours24 => $now->modify('+24 hours'),
            self::Hours48 => $now->modify('+48 hours'),
            self::Hours72 => $now->modify('+72 hours'),
            self::Days7 => $now->modify('+7 days'),
            self::Days14 => $now->modify('+14 days'),
            self::Days30 => $now->modify('+30 days'),
            self::Months3 => $now->modify('+3 months'),
            self::Months6 => $now->modify('+6 months'),
            self::Year1 => $now->modify('+1 year'),
        };
    }

    public function isImmediate(): bool
    {
        return self::Immediate === $this;
    }
}
