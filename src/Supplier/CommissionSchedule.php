<?php

namespace App\Supplier;

/**
 * Barème de commission du programme partenaire, par rayon et par formule (en pourcentage des ventes).
 *
 * C'est la SEULE source de ces chiffres : la page publique « Devenir partenaire » (simulateur) et l'Espace Fournisseur
 * (taux prévu) les lisent ici. Les identifiants de rayon (« cat-mode »…) sont les mêmes que ceux enregistrés dans le dossier
 * de candidature (App\Auth\SupplierApplication::CATEGORIES). Les noms affichés sont traduits (clés « vendor.cat.<key> »).
 *
 * Ce barème est INFORMATIF : le calcul réel des commissions et des reversements n'est pas encore branché sur les paiements.
 */
final class CommissionSchedule
{
    /** Loyer mensuel de la Boutique Pro, en Ariary. */
    public const PRO_MONTHLY_RENT = 49000;

    /** @var list<array{id: string, key: string, icon: string, standard: int, pro: int}> */
    private const RATES = [
        ['id' => 'cat-alim', 'key' => 'alim', 'icon' => '🌾', 'standard' => 4, 'pro' => 2],
        ['id' => 'cat-elec', 'key' => 'elec', 'icon' => '📱', 'standard' => 5, 'pro' => 3],
        ['id' => 'cat-enfants', 'key' => 'enfants', 'icon' => '🧸', 'standard' => 6, 'pro' => 4],
        ['id' => 'cat-electromenager', 'key' => 'electromenager', 'icon' => '⚡', 'standard' => 6, 'pro' => 4],
        ['id' => 'cat-maison', 'key' => 'maison', 'icon' => '🏠', 'standard' => 7, 'pro' => 5],
        ['id' => 'cat-sport', 'key' => 'sport', 'icon' => '⚽', 'standard' => 7, 'pro' => 5],
        ['id' => 'cat-mode', 'key' => 'mode', 'icon' => '👗', 'standard' => 8, 'pro' => 6],
        ['id' => 'cat-beaute', 'key' => 'beaute', 'icon' => '✨', 'standard' => 8, 'pro' => 6],
    ];

    /** @return list<array{id: string, key: string, icon: string, standard: int, pro: int}> */
    public function all(): array
    {
        return self::RATES;
    }

    /**
     * Taux (en %) pour un rayon et une formule, ou null si le rayon est inconnu / non renseigné.
     * Une formule inconnue est traitée comme « standard » (jamais de taux réduit par erreur).
     */
    public function rateFor(?string $category, ?string $plan): ?int
    {
        foreach (self::RATES as $rate) {
            if ($rate['id'] === $category) {
                return 'pro' === $plan ? $rate['pro'] : $rate['standard'];
            }
        }

        return null;
    }
}
