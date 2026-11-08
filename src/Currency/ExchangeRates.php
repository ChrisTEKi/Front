<?php

namespace App\Currency;

use App\Admin\AdminActionException;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\ExchangeRate;
use App\Entity\User;
use App\Enum\Currency;
use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Taux de change : lecture, conversion et mise à jour (automatique ou manuelle).
 *
 * L'ARIARY est la devise de référence (taux = 1). Un taux est « Ariary pour 1 unité » (ex. 1 EUR = 5 000 Ar).
 *
 * Règles :
 *  - AUCUN taux n'est inventé : tant qu'une devise n'a pas de taux enregistré (récupéré ou saisi), elle n'est pas proposée
 *    aux clients (`availableCurrencies()`), et `fromMga()` / `toMga()` retournent null ;
 *  - la mise à jour automatique (`refresh()`) REFUSE un taux qui s'écarte de plus de 30 % du dernier taux automatique
 *    (erreur de service, mauvaise unité…) : il est signalé dans le journal d'audit, l'ancien taux reste en vigueur ;
 *  - un taux MANUEL (saisi par un administrateur, avec ou sans durée) bloque les mises à jour automatiques de sa devise ;
 *    `resumeAutomatic()` rend la main à l'automatique ;
 *  - les montants restent des entiers en unités mineures (centimes) ; conversion arrondie à l'unité mineure la plus proche,
 *    et jamais à 0 pour un montant positif (un article ne devient pas gratuit à cause de l'arrondi).
 */
final class ExchangeRates
{
    /** Écart maximal accepté entre deux taux automatiques successifs (0,30 = 30 %). */
    public const MAX_JUMP = 0.30;

    /** Un taux automatique inchangé est quand même enregistré une fois par jour : cela prouve que le service répond toujours. */
    private const HEARTBEAT_AGE = '-24 hours';

    /** @var array<string, ExchangeRate|false> mémo pour la durée de la requête (false = aucun taux) */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExchangeRateRepository $rates,
        private readonly ExchangeRateProvider $provider,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    // =========================================================================
    // LECTURE ET CONVERSION
    // =========================================================================

    /** Taux en vigueur (ligne la plus récente), ou null si la devise n'a encore aucun taux. */
    public function current(Currency $currency): ?ExchangeRate
    {
        if (Currency::BASE === $currency) {
            return null;
        }
        $key = $currency->value;
        if (!isset($this->memo[$key])) {
            $this->memo[$key] = $this->rates->latestFor($currency) ?? false;
        }

        return false === $this->memo[$key] ? null : $this->memo[$key];
    }

    /** Ariary pour 1 unité : 1.0 pour l'Ariary, null si aucun taux n'est connu. */
    public function rateFor(Currency $currency): ?float
    {
        if (Currency::BASE === $currency) {
            return 1.0;
        }

        $row = $this->current($currency);

        return null === $row ? null : (float) $row->getRate();
    }

    /** Taux en texte décimal (pour l'enregistrer dans une commande), ou null. */
    public function rateTextFor(Currency $currency): ?string
    {
        if (Currency::BASE === $currency) {
            return '1.000000';
        }

        return $this->current($currency)?->getRate();
    }

    public function isAvailable(Currency $currency): bool
    {
        return null !== $this->rateFor($currency);
    }

    /**
     * Devises proposables aux clients : l'Ariary toujours, puis chaque devise qui a un taux.
     *
     * @return list<Currency>
     */
    public function availableCurrencies(): array
    {
        return array_values(array_filter(Currency::cases(), fn (Currency $c): bool => $this->isAvailable($c)));
    }

    /** Montant en Ariary → unités mineures de la devise (null si pas de taux). */
    public function fromMga(int $mga, Currency $to): ?int
    {
        $rate = $this->rateFor($to);
        if (null === $rate) {
            return null;
        }
        if (Currency::BASE === $to || $mga <= 0) {
            return $mga;
        }

        return max(1, (int) round($mga * $to->minorFactor() / $rate));
    }

    /**
     * PRIX d'un produit dans une devise (unités mineures) : le prix SPÉCIFIQUE fixé par le vendeur pour cette devise s'il existe,
     * sinon le prix de base en Ariary converti au taux du jour. Null si la devise n'a pas de taux (et pas de prix spécifique).
     * Règle unique, utilisée par la boutique ET par la commande.
     *
     * @param array<string, int> $overrides prix spécifiques du produit (clés « EUR », « USD »)
     */
    public function priceIn(int $baseMga, array $overrides, Currency $currency): ?int
    {
        if (Currency::BASE === $currency) {
            return $baseMga;
        }
        $specific = $overrides[$currency->value] ?? null;
        if (\is_int($specific) && $specific > 0) {
            return $specific;
        }

        return $this->fromMga($baseMga, $currency);
    }

    /** Unités mineures d'une devise → Ariary (null si pas de taux). */
    public function toMga(int $minor, Currency $from): ?int
    {
        $rate = $this->rateFor($from);
        if (null === $rate) {
            return null;
        }
        if (Currency::BASE === $from || $minor <= 0) {
            return $minor;
        }

        return max(1, (int) round($minor / $from->minorFactor() * $rate));
    }

    // =========================================================================
    // MISE À JOUR AUTOMATIQUE
    // =========================================================================

    /**
     * Récupère les taux auprès du fournisseur et enregistre ceux qui changent.
     *
     * @throws ExchangeRateUnavailableException si le fournisseur ne répond pas (les taux en vigueur ne sont pas touchés)
     */
    public function refresh(): RateRefreshReport
    {
        $fetched = $this->provider->fetch();
        $now = $this->clock->now();
        $rows = [];

        foreach (Currency::cases() as $currency) {
            if (Currency::BASE === $currency) {
                continue;
            }
            $code = $currency->value;
            $text = $fetched[$code] ?? null;
            if (null === $text || !$this->isValidRate($text)) {
                $rows[$code] = ['status' => RateRefreshReport::MISSING, 'rate' => null];
                continue;
            }
            $text = $this->normalizeRate($text);

            $latest = $this->rates->latestFor($currency);
            if (null !== $latest && $latest->locksAutomaticUpdates($now)) {
                $rows[$code] = ['status' => RateRefreshReport::LOCKED, 'rate' => $latest->getRate()];
                continue;
            }

            $previousAuto = $this->rates->latestAutomaticFor($currency);
            if (null !== $previousAuto) {
                $previous = (float) $previousAuto->getRate();
                if ($previous > 0 && abs((float) $text / $previous - 1) > self::MAX_JUMP) {
                    $this->audit->record(AuditAction::RATE_REJECTED, null, 'currency', $code, ['proposed' => $text, 'previous' => $previousAuto->getRate()]);
                    $rows[$code] = ['status' => RateRefreshReport::SUSPICIOUS, 'rate' => $text];
                    continue;
                }
            }

            // Même valeur que la dernière ligne automatique, et enregistrée il y a moins de 24 h : rien à ajouter.
            if (null !== $latest && !$latest->isManual() && $latest->getRate() === $text && $latest->getCreatedAt() > $now->modify(self::HEARTBEAT_AGE)) {
                $rows[$code] = ['status' => RateRefreshReport::UNCHANGED, 'rate' => $text];
                continue;
            }

            $this->em->persist(new ExchangeRate($currency, $text, ExchangeRate::SOURCE_AUTO, $now));
            $rows[$code] = ['status' => RateRefreshReport::UPDATED, 'rate' => $text];
        }

        $this->em->flush();
        $this->memo = [];

        return new RateRefreshReport($rows);
    }

    // =========================================================================
    // ACTIONS D'ADMINISTRATEUR
    // =========================================================================

    /**
     * Fixe un taux à la main (par exemple si le service est en panne, ou pour imposer le taux de la banque de GoBuy).
     *
     * @param string   $input saisie de l'administrateur (« 5012,5 », « 5 012.50 »)
     * @param int|null $days  validité en jours (1 à 365) ; null = jusqu'à la reprise du mode automatique
     *
     * @throws AdminActionException invalid_currency | invalid_rate | invalid_days
     */
    public function setManual(User $admin, Currency $currency, string $input, ?int $days, ?string $note): ExchangeRate
    {
        if (Currency::BASE === $currency) {
            throw new AdminActionException('invalid_currency');
        }
        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($input));
        if (!$this->isValidRate($text)) {
            throw new AdminActionException('invalid_rate');
        }
        if (null !== $days && ($days < 1 || $days > 365)) {
            throw new AdminActionException('invalid_days');
        }

        $now = $this->clock->now();
        $note = null === $note ? null : mb_substr(trim($note), 0, 255);
        $row = new ExchangeRate($currency, $this->normalizeRate($text), ExchangeRate::SOURCE_MANUAL, $now, $admin, '' === $note ? null : $note, null === $days ? null : $now->modify('+'.$days.' days'));
        $this->em->persist($row);
        $this->em->flush();
        $this->memo = [];

        $this->audit->record(AuditAction::RATE_MANUAL, $admin, 'currency', $currency->value, ['rate' => $row->getRate(), 'days' => $days, 'note' => $row->getNote()]);

        return $row;
    }

    /**
     * Reprend le mode automatique : va chercher le taux du fournisseur tout de suite et l'enregistre (sans le garde-fou d'écart :
     * l'administrateur sait ce qu'il fait).
     *
     * @throws AdminActionException invalid_currency | rate_provider_down | rate_missing
     */
    public function resumeAutomatic(User $admin, Currency $currency): ExchangeRate
    {
        if (Currency::BASE === $currency) {
            throw new AdminActionException('invalid_currency');
        }
        try {
            $fetched = $this->provider->fetch();
        } catch (ExchangeRateUnavailableException) {
            throw new AdminActionException('rate_provider_down');
        }
        $text = $fetched[$currency->value] ?? null;
        if (null === $text || !$this->isValidRate($text)) {
            throw new AdminActionException('rate_missing');
        }

        $row = new ExchangeRate($currency, $this->normalizeRate($text), ExchangeRate::SOURCE_AUTO, $this->clock->now(), $admin);
        $this->em->persist($row);
        $this->em->flush();
        $this->memo = [];

        $this->audit->record(AuditAction::RATE_AUTO_RESUMED, $admin, 'currency', $currency->value, ['rate' => $row->getRate()]);

        return $row;
    }

    // =========================================================================
    // OUTILS
    // =========================================================================

    /** Texte décimal strictement positif, 9 chiffres avant la virgule au plus, 6 après. */
    private function isValidRate(string $text): bool
    {
        return 1 === preg_match('/^\d{1,9}(\.\d{1,6})?$/', $text) && (float) $text > 0 && (float) $text <= 100000000;
    }

    private function normalizeRate(string $text): string
    {
        return number_format((float) $text, 6, '.', '');
    }
}
