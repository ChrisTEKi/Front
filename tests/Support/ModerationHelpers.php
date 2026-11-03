<?php

namespace App\Tests\Support;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\Permission;

/**
 * Aides communes aux tests de modération : administrateur aux droits choisis, commandes créées en base, lecture du journal d'audit.
 * À utiliser dans une classe qui étend AppTestCase.
 */
trait ModerationHelpers
{
    /** Administrateur « normal » avec les seuls droits demandés (en plus du profil Support). */
    private function adminWith(array $permissions, string $email = 'gestion@example.com'): User
    {
        return $this->createAdminUser($email, 'support', array_map(static fn (string $key): Permission => Permission::from($key), $permissions));
    }

    private function id(User $user): string
    {
        return $user->getId()->toRfc4122();
    }

    private function fresh(User $user): User
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $user->getId());
    }

    /**
     * Commande créée directement en base.
     *
     * @param list<array{0: User, 1: string, 2: int, 3: int}> $lines fournisseur, nom, prix, quantité
     */
    private function orderFor(User $client, array $lines, OrderStatus $status = OrderStatus::Confirmed, PaymentStatus $payment = PaymentStatus::Pending): string
    {
        static $counter = 100000;
        $number = 'GB-2026-'.(++$counter);
        $now = new \DateTimeImmutable();
        $subtotal = array_sum(array_map(static fn (array $l): int => $l[2] * $l[3], $lines));

        $order = new Order($number, $this->em()->getReference(User::class, $client->getId()), ['fullName' => 'Acheteur', 'phone' => null, 'street' => 'Rue 1', 'city' => 'Tana', 'postalCode' => null, 'instructions' => null], PaymentMethod::CashOnDelivery, 1, $subtotal, 10000, 'fr', $now);
        foreach ($lines as [$supplier, $name, $price, $quantity]) {
            $order->addItem(new OrderItem($order, null, $name, $price, $quantity, $this->em()->getReference(User::class, $supplier->getId())));
        }
        if (OrderStatus::Confirmed !== $status) {
            $order->advanceTo($status, null, null, $now);
        }
        if (PaymentStatus::Pending !== $payment) {
            $order->setPaymentStatus($payment, $now);
        }
        $this->em()->persist($order);
        $this->em()->flush();
        $this->em()->clear();

        return $number;
    }

    private function order(string $number): Order
    {
        $this->em()->clear();

        return $this->em()->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
    }

    private function actions(string $prefix): array
    {
        $this->em()->clear();

        return array_values(array_filter(array_map(static fn (AuditLog $l): string => $l->getAction(), $this->em()->getRepository(AuditLog::class)->findAll()), static fn (string $a): bool => str_starts_with($a, $prefix)));
    }
}
