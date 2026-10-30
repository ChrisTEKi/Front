<?php

namespace App\Supplier;

use App\Entity\User;
use App\Phone\PhoneNumber;
use App\Repository\OrderItemRepository;
use App\Repository\SupplierProfileRepository;
use Psr\Clock\ClockInterface;

/**
 * Données RÉELLES de l'Espace Fournisseur (page /fournisseur) : dossier, statut d'approbation, commandes reçues et ventes.
 *
 * Tout vient de la base PostgreSQL (tables `supplier_profiles`, `order_items`, `orders`) ; rien n'est inventé.
 * Ce qui n'existe pas encore (catalogue de produits du fournisseur, reversements) est annoncé comme tel dans l'interface.
 *
 * Confidentialité : un fournisseur ne voit QUE ses propres lignes de commande, jamais le nom, le téléphone ni l'adresse du client.
 */
final class SupplierDashboardProvider
{
    /** Madagascar : UTC+3 toute l'année (pas d'heure d'été). Les « jours » du graphique sont ceux d'Antananarivo. */
    private const TIMEZONE = 'Indian/Antananarivo';
    private const CHART_DAYS = 7;
    private const ORDERS_LIMIT = 50;
    private const RECENT_ON_OVERVIEW = 5;

    public function __construct(
        private readonly SupplierProfileRepository $profiles,
        private readonly OrderItemRepository $items,
        private readonly CommissionSchedule $commissions,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forSupplier(User $user): array
    {
        $supplierId = $user->getId()->toRfc4122();
        $profile = $this->profiles->findOneByUser($user);
        $application = $profile?->getApplication() ?? [];
        $phone = $user->getPhone();

        // Plan « souhaité » dans la candidature : la facturation de la formule Pro n'est pas encore en service.
        $plan = 'pro' === ($application['plan'] ?? null) ? 'pro' : 'standard';
        $category = \is_string($application['mainCategory'] ?? null) ? $application['mainCategory'] : null;

        // Les chiffres de vente ne sont montrés qu'à un fournisseur APPROUVÉ : un dossier refusé ou suspendu ne donne plus accès
        // aux commandes ni aux ventes (un compte en attente n'en a pas encore).
        $approved = $profile?->isApproved() ?? false;
        $orders = $approved ? $this->items->recentOrdersForSupplier($supplierId, self::ORDERS_LIMIT) : [];
        $stats = $approved ? $this->items->statsForSupplier($supplierId) : ['orders' => 0, 'preparing' => 0, 'delivered' => 0, 'salesTotal' => 0];

        return [
            'companyName' => $profile?->getCompanyName() ?? $user->getFullName(),
            'companyAddress' => $profile?->getCompanyAddress(),
            'description' => $profile?->getCompanyDescription(),
            'email' => $user->getEmail(),
            'emailVerified' => $user->isEmailVerified(),
            'phone' => null === $phone ? null : PhoneNumber::fromE164($phone)->display(),
            'phoneVerified' => $user->isPhoneVerified(),
            'status' => $profile?->getStatus()->value ?? 'pending',
            'rejectionReason' => $profile?->getRejectionReason(),
            'approved' => $approved,
            'plan' => $plan,
            'proRent' => CommissionSchedule::PRO_MONTHLY_RENT,
            'commissionRate' => $this->commissions->rateFor($category, $plan),
            'stats' => $stats,
            'chart' => $this->chart($supplierId, $approved),
            'orders' => $orders,
            'recentOrders' => \array_slice($orders, 0, self::RECENT_ON_OVERVIEW),
        ];
    }

    /**
     * Ventes des 7 derniers jours (aujourd'hui compris), jour par jour, avec la hauteur relative (0–100) de chaque barre.
     *
     * @return array{days: list<array{date: \DateTimeImmutable, orders: int, sales: int, salesPct: int, ordersPct: int}>, totalSales: int, averageSales: int, peak: ?\DateTimeImmutable}
     */
    private function chart(string $supplierId, bool $approved): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);
        $first = $today->modify('-'.(self::CHART_DAYS - 1).' days');
        $perDay = $approved ? $this->items->dailySalesForSupplier($supplierId, $first) : [];

        $days = [];
        for ($i = 0; $i < self::CHART_DAYS; ++$i) {
            $date = $first->modify('+'.$i.' days');
            $row = $perDay[$date->format('Y-m-d')] ?? ['orders' => 0, 'sales' => 0];
            $days[] = ['date' => $date, 'orders' => $row['orders'], 'sales' => $row['sales'], 'salesPct' => 0, 'ordersPct' => 0];
        }

        $maxSales = max(array_column($days, 'sales'));
        $maxOrders = max(array_column($days, 'orders'));
        $peak = null;
        foreach ($days as &$day) {
            // Une barre non nulle reste visible (minimum 4 %), la plus haute occupe toute la hauteur.
            $day['salesPct'] = $maxSales > 0 && $day['sales'] > 0 ? max(4, (int) round($day['sales'] / $maxSales * 100)) : 0;
            $day['ordersPct'] = $maxOrders > 0 && $day['orders'] > 0 ? max(4, (int) round($day['orders'] / $maxOrders * 100)) : 0;
            if ($maxSales > 0 && $day['sales'] === $maxSales && null === $peak) {
                $peak = $day['date'];
            }
        }
        unset($day);

        $total = array_sum(array_column($days, 'sales'));

        return ['days' => $days, 'totalSales' => $total, 'averageSales' => (int) round($total / self::CHART_DAYS), 'peak' => $peak];
    }
}
