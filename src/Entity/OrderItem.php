<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne d'une commande — table `order_items`.
 * Le nom et le prix du produit sont COPIÉS : l'historique reste exact même si le catalogue change.
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: 'order_items', options: ['comment' => 'Lignes de commande : nom et prix du produit copiés au moment de l\'achat.'])]
#[ORM\Index(name: 'idx_order_items_order', columns: ['order_id'])]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Commande parente. Supprimée avec elle. */
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** Identifiant du produit dans le catalogue (texte libre : le catalogue n'est pas encore en base). */
    #[ORM\Column(name: 'product_ref', type: Types::STRING, length: 64, nullable: true)]
    private ?string $productRef;

    /** Nom du produit au moment de l'achat. */
    #[ORM\Column(name: 'product_name', type: Types::STRING, length: 255)]
    private string $productName;

    /** Prix unitaire en Ariary au moment de l'achat. */
    #[ORM\Column(name: 'unit_price', type: Types::INTEGER)]
    private int $unitPrice;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $quantity;

    /** Fournisseur vendeur (NULL si son compte a disparu). Utile pour le tableau de bord fournisseur. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $supplier;

    public function __construct(Order $order, ?string $productRef, string $productName, int $unitPrice, int $quantity, ?User $supplier)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->productRef = $productRef;
        $this->productName = $productName;
        $this->unitPrice = $unitPrice;
        $this->quantity = $quantity;
        $this->supplier = $supplier;
    }

    public function getProductRef(): ?string
    {
        return $this->productRef;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /** Total de la ligne = prix unitaire × quantité. */
    public function getLineTotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function getSupplier(): ?User
    {
        return $this->supplier;
    }
}
