<?php

namespace App\Supplier;

use App\Admin\AdminActionException;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\CommissionRate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Barème de commission du programme partenaire : un taux (en pourcentage des ventes) par rayon, MODIFIABLE par l'administration.
 *
 * C'est la SEULE source de ces chiffres : la page publique « Devenir partenaire » (simulateur) et l'Espace Fournisseur (taux
 * prévu) les lisent ici. Les taux vivent dans la table `commission_rates` ; les chiffres d'origine ci-dessous ne servent que de
 * REPLI (ligne absente) et de valeurs de départ à la migration. Les identifiants de rayon (« cat-mode »…) sont les mêmes que
 * ceux enregistrés dans le dossier de candidature (App\Auth\SupplierApplication::CATEGORIES). Les noms affichés sont traduits
 * (clés « vendor.cat.<key> »).
 *
 * Pas de loyer ni de formule « Pro » : un seul taux par rayon pour tous les fournisseurs.
 *
 * Ce barème est INFORMATIF : le calcul réel des commissions et des reversements n'est pas encore branché sur les paiements.
 */
final class CommissionSchedule implements ResetInterface
{
    /** Plafond d'un taux (en %) : garde-fou contre une faute de frappe (« 80 » au lieu de « 8 »). */
    public const MAX_RATE = 50;

    /** @var list<array{id: string, key: string, icon: string, default: string}> */
    public const CATEGORIES = [
        ['id' => 'cat-alim', 'key' => 'alim', 'icon' => '🌾', 'default' => '4.00'],
        ['id' => 'cat-elec', 'key' => 'elec', 'icon' => '📱', 'default' => '5.00'],
        ['id' => 'cat-enfants', 'key' => 'enfants', 'icon' => '🧸', 'default' => '6.00'],
        ['id' => 'cat-electromenager', 'key' => 'electromenager', 'icon' => '⚡', 'default' => '6.00'],
        ['id' => 'cat-maison', 'key' => 'maison', 'icon' => '🏠', 'default' => '7.00'],
        ['id' => 'cat-sport', 'key' => 'sport', 'icon' => '⚽', 'default' => '7.00'],
        ['id' => 'cat-mode', 'key' => 'mode', 'icon' => '👗', 'default' => '8.00'],
        ['id' => 'cat-beaute', 'key' => 'beaute', 'icon' => '✨', 'default' => '8.00'],
    ];

    /** @var array<string, CommissionRate>|null lignes de la base, lues une seule fois par requête */
    private ?array $stored = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Le barème complet, dans l'ordre d'affichage. `rate` est un nombre (4 ou 7.5) prêt à afficher ou à calculer ; `updatedAt` et
     * `updatedBy` valent null tant que le taux est celui d'origine (jamais enregistré en base).
     *
     * @return list<array{id: string, key: string, icon: string, rate: int|float, default: int|float, updatedAt: ?\DateTimeImmutable, updatedBy: ?User}>
     */
    public function all(): array
    {
        $rows = [];
        foreach (self::CATEGORIES as $category) {
            $row = $this->rows()[$category['id']] ?? null;
            $rows[] = [
                'id' => $category['id'],
                'key' => $category['key'],
                'icon' => $category['icon'],
                'rate' => self::number($row?->getRate() ?? $category['default']),
                'default' => self::number($category['default']),
                'updatedAt' => $row?->getUpdatedAt(),
                'updatedBy' => $row?->getUpdatedBy(),
            ];
        }

        return $rows;
    }

    /** Taux (en %) d'un rayon, ou null si le rayon est inconnu / non renseigné. */
    public function rateFor(?string $category): int|float|null
    {
        foreach ($this->all() as $row) {
            if ($row['id'] === $category) {
                return $row['rate'];
            }
        }

        return null;
    }

    /**
     * Enregistre de nouveaux taux. Seuls les rayons PRÉSENTS dans $input sont concernés ; ceux dont la valeur ne change pas sont
     * ignorés. Tout est refusé si UN taux est invalide (aucun changement partiel).
     *
     * @param array<string, mixed> $input rayon (« cat-mode ») => taux saisi (« 7,5 », « 7.50 », 7…)
     *
     * @return list<array{category: string, from: string, to: string}> les changements réellement faits
     *
     * @throws AdminActionException invalid_commission_rate | commission_no_change
     */
    public function update(User $admin, array $input): array
    {
        // Toujours partir de l'état de la base (jamais d'une lecture mise de côté plus tôt) : on modifie des lignes réelles.
        $this->reset();
        $now = $this->clock->now();
        $changes = [];
        $new = [];
        foreach (self::CATEGORIES as $category) {
            if (!\array_key_exists($category['id'], $input)) {
                continue;
            }
            $rate = self::parse($input[$category['id']]);
            $current = ($this->rows()[$category['id']] ?? null)?->getRate() ?? $category['default'];
            if ($rate !== $current) {
                $changes[] = ['category' => $category['id'], 'from' => $current, 'to' => $rate];
                $new[$category['id']] = $rate;
            }
        }
        if ([] === $changes) {
            throw new AdminActionException('commission_no_change');
        }

        $this->em->wrapInTransaction(function () use ($new, $now, $admin): void {
            foreach ($new as $id => $rate) {
                $row = $this->rows()[$id] ?? null;
                if (null === $row) {
                    $this->em->persist($this->stored[$id] = new CommissionRate($id, $rate, $now, $admin));
                } else {
                    $row->changeRate($rate, $now, $admin);
                }
            }
        });
        $this->audit->record(AuditAction::COMMISSION_CHANGED, $admin, 'commission', null, ['changes' => $changes]);
        $this->reset();

        return $changes;
    }

    /**
     * Taux saisi → texte décimal à 2 décimales (« 7.50 »). Accepte la virgule, refuse tout ce qui n'est pas un nombre positif ou
     * nul d'au plus 2 décimales, et tout taux au-dessus de MAX_RATE.
     *
     * @throws AdminActionException invalid_commission_rate
     */
    public static function parse(mixed $raw): string
    {
        $text = \is_int($raw) || \is_float($raw) ? (string) $raw : (\is_string($raw) ? $raw : '');
        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($text));
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $text) || (float) $text > self::MAX_RATE) {
            throw new AdminActionException('invalid_commission_rate', ['%max%' => self::MAX_RATE]);
        }

        return number_format((float) $text, 2, '.', '');
    }

    /** « 7.50 » → 7.5 ; « 4.00 » → 4 (un nombre, pour l'affichage et les calculs). */
    private static function number(string $decimal): int|float
    {
        $value = (float) $decimal;

        return floor($value) === $value ? (int) $value : $value;
    }

    /** Oublie les lignes lues (appelé par Symfony entre deux requêtes d'un même processus, et avant/après chaque enregistrement). */
    public function reset(): void
    {
        $this->stored = null;
    }

    /** @return array<string, CommissionRate> */
    private function rows(): array
    {
        if (null === $this->stored) {
            $this->stored = [];
            foreach ($this->em->getRepository(CommissionRate::class)->findAll() as $row) {
                $this->stored[$row->getCategory()] = $row;
            }
        }

        return $this->stored;
    }
}
