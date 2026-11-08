<?php

namespace App\Currency;

/**
 * Résultat d'une mise à jour automatique des taux.
 *
 * @phpstan-type Row array{currency: string, status: string, rate: ?string}
 */
final class RateRefreshReport
{
    /** Taux enregistré. */
    public const UPDATED = 'updated';
    /** Taux identique au précédent (rien à enregistrer). */
    public const UNCHANGED = 'unchanged';
    /** Un taux manuel est en vigueur : l'automatique n'y touche pas. */
    public const LOCKED = 'locked';
    /** Écart anormal avec le taux précédent : valeur REFUSÉE (à vérifier par un administrateur). */
    public const SUSPICIOUS = 'suspicious';
    /** Le fournisseur n'a pas donné de taux pour cette devise. */
    public const MISSING = 'missing';

    /** @param array<string, array{status: string, rate: ?string}> $rows par code de devise */
    public function __construct(public readonly array $rows)
    {
    }

    /** @return list<string> codes de devises dont le taux a été enregistré */
    public function updated(): array
    {
        return array_keys(array_filter($this->rows, static fn (array $r): bool => self::UPDATED === $r['status']));
    }

    /** Vrai si une valeur a été refusée ou manque : à signaler. */
    public function hasProblem(): bool
    {
        foreach ($this->rows as $row) {
            if (\in_array($row['status'], [self::SUSPICIOUS, self::MISSING], true)) {
                return true;
            }
        }

        return false;
    }
}
