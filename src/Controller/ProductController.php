<?php

namespace App\Controller;

use App\Catalog\ProductPresenter;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fiche produit publique. Seul un produit « vendable » est affiché (actif, et vendu par GoBuy ou par un fournisseur
 * approuvé dont le compte est actif : voir ProductRepository::findSellable). Un produit masqué, retiré ou d'un fournisseur
 * suspendu donne la même page « introuvable » qu'un identifiant inconnu.
 */
final class ProductController extends AbstractController
{
    #[Route('/produit/{id}', name: 'app_product', methods: ['GET'])]
    public function show(string $id, ProductRepository $products, ProductPresenter $presenter): Response
    {
        $product = $products->findSellable($id);
        $card = null === $product ? null : $presenter->storefrontOne($product, $presenter->companyNames([$product]));

        return $this->render('product/show.html.twig', ['product' => $card], null !== $card ? null : new Response('', Response::HTTP_NOT_FOUND));
    }
}
