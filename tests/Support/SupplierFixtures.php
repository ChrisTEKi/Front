<?php

namespace App\Tests\Support;

use App\Entity\Product;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountType;

/**
 * Fournisseurs et produits de test, créés directement en base (sans passer par l'inscription).
 * À utiliser dans une classe qui étend AppTestCase.
 */
trait SupplierFixtures
{
    /**
     * Fournisseur avec son dossier. $status : pending | approved | rejected | suspended.
     * Le profil de décision est créé AVANT de charger le dossier : createAdminUser vide l'EntityManager.
     */
    protected function supplierAccount(string $email = 'boutique@example.com', string $status = 'approved', string $phone = '+261331112233', string $company = 'Ma Boutique', bool $verified = true): User
    {
        $user = $this->createUser($email, AccountType::Supplier, true, $phone, $verified);
        $reviewer = 'pending' === $status ? null : $this->createAdminUser('modo-'.bin2hex(random_bytes(3)).'@example.com', 'moderation');
        $this->em()->clear();

        $profile = new SupplierProfile($this->em()->find(User::class, $user->getId()), $company, 'Lot 12 Analakely', 'Vêtements', new \DateTimeImmutable());
        $this->em()->persist($profile);
        if (null !== $reviewer) {
            $admin = $this->em()->find(User::class, $reviewer->getId());
            $now = new \DateTimeImmutable();
            match ($status) {
                'approved' => $profile->approve($admin, $now),
                'rejected' => $profile->reject($admin, 'Dossier incomplet', $now),
                'suspended' => $profile->suspend($admin, 'Plaintes clients', $now),
            };
        }
        $this->em()->flush();
        $this->em()->clear();

        return $user;
    }

    /** Produit en base ; $owner null = produit de GoBuy. $status : active | hidden. */
    protected function productFor(?User $owner, string $name = 'Produit', int $price = 1000, string $status = 'active'): Product
    {
        $product = new Product(null === $owner ? null : $this->em()->getReference(User::class, $owner->getId()), $name, $price, new \DateTimeImmutable());
        if ('active' !== $status) {
            $product->setVisible(false, new \DateTimeImmutable());
        }
        $this->em()->persist($product);
        $this->em()->flush();
        $this->em()->clear();

        return $product;
    }
}
