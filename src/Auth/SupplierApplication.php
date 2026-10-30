<?php

namespace App\Auth;

/**
 * Réponses détaillées de l'assistant « Devenir partenaire » (4 étapes) — colonne JSONB `supplier_profiles.application`.
 *
 * POURQUOI UNE CLASSE DÉDIÉE ?
 *   Les réponses arrivent du navigateur : on ne leur fait JAMAIS confiance. `sanitize()` est la SEULE porte d'entrée :
 *     - seules les clés listées ci-dessous sont gardées (les autres sont ignorées, pas d'injection de champs) ;
 *     - les listes de choix ne gardent que des CODES connus (le texte affiché est traduit côté interface et admin,
 *       jamais enregistré en base : un dossier reste lisible dans les 3 langues) ;
 *     - les textes libres sont nettoyés (caractères de contrôle retirés) et tronqués à une longueur maximale ;
 *     - un type inattendu (tableau à la place d'un texte, etc.) est simplement ignoré.
 *   Le résultat est donc toujours sûr à stocker, et à afficher (Twig échappe en plus le HTML à l'affichage).
 */
final class SupplierApplication
{
    /** Rayons principaux (mêmes identifiants que le simulateur de commissions de la page). */
    public const CATEGORIES = ['cat-alim', 'cat-elec', 'cat-enfants', 'cat-electromenager', 'cat-maison', 'cat-sport', 'cat-mode', 'cat-beaute'];
    public const ACTIVITY_TYPES = ['individual', 'company'];
    public const PLANS = ['standard', 'pro'];
    public const PRODUCT_TYPES = ['electronics', 'fashion', 'grocery', 'home', 'beauty', 'sport', 'toys'];
    public const SKU_RANGES = ['1-10', '11-50', '51-200', '200+'];
    public const PREP_TIMES = ['under_2h', 'same_day', '24_48h'];
    public const SALES_POINTS = ['shop', 'warehouse', 'online_only', 'market'];
    public const PROOFS = ['photos', 'police_report', 'assessment'];

    /** Textes libres : clé => longueur maximale (en caractères). */
    private const TEXT_LIMITS = [
        'contactRole' => 100,
        'city' => 100,
        'taxId' => 50,
        'sector' => 100,
        'stockTool' => 100,
        'expectations' => 1000,
    ];

    /** Choix unique : clé => codes autorisés. */
    private const CHOICES = [
        'activityType' => self::ACTIVITY_TYPES,
        'mainCategory' => self::CATEGORIES,
        'plan' => self::PLANS,
        'skuRange' => self::SKU_RANGES,
        'prepTime' => self::PREP_TIMES,
    ];

    /** Choix multiple : clé => codes autorisés. */
    private const MULTI_CHOICES = [
        'productTypes' => self::PRODUCT_TYPES,
        'salesPoints' => self::SALES_POINTS,
        'proofs' => self::PROOFS,
    ];

    /** Oui / non. */
    private const FLAGS = ['ownDelivery', 'damage'];

    /**
     * Nettoie les réponses brutes du navigateur.
     *
     * @param mixed $raw contenu du champ « application » de la requête JSON (n'importe quoi)
     *
     * @return array<string, mixed>|null les réponses valides, ou null s'il n'y en a aucune
     */
    public static function sanitize(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $clean = [];

        foreach (self::TEXT_LIMITS as $key => $max) {
            $text = self::text($raw[$key] ?? null, $max);
            if ('' !== $text) {
                $clean[$key] = $text;
            }
        }

        foreach (self::CHOICES as $key => $allowed) {
            if (\is_string($raw[$key] ?? null) && \in_array($raw[$key], $allowed, true)) {
                $clean[$key] = $raw[$key];
            }
        }

        foreach (self::MULTI_CHOICES as $key => $allowed) {
            $picked = \is_array($raw[$key] ?? null) ? array_values(array_unique(array_filter($raw[$key], static fn (mixed $v): bool => \is_string($v) && \in_array($v, $allowed, true)))) : [];
            if ([] !== $picked) {
                $clean[$key] = $picked;
            }
        }

        foreach (self::FLAGS as $key) {
            if (\is_bool($raw[$key] ?? null)) {
                $clean[$key] = $raw[$key];
            }
        }

        return [] === $clean ? null : $clean;
    }

    /** Texte libre : seulement une chaîne, sans caractères de contrôle, espaces rognés, longueur limitée. */
    private static function text(mixed $value, int $max): string
    {
        if (!\is_string($value)) {
            return '';
        }
        // \x00-\x08, \x0B, \x0C, \x0E-\x1F, \x7F : caractères de contrôle invisibles. On garde les retours à la ligne (\n) et tabulations.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return mb_substr(trim($value), 0, $max);
    }
}
