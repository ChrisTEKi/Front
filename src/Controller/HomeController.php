<?php

namespace App\Controller;

use App\Catalog\ProductPresenter;
use App\Repository\ProductRepository;
use App\Service\CategoryProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(CategoryProvider $categories, ProductRepository $products, ProductPresenter $presenter, ClockInterface $clock): Response
    {
        return $this->render('home/index.html.twig', [
            'categories' => $categories->all(),
            // Aucune donnée fictive : seuls les VRAIS produits vendables (mis en avant d'abord, puis les plus récents).
            // Les rubriques « ventes flash » et « livraison express » restent vides tant qu'elles n'ont pas de règle propre.
            'flashSaleProducts' => [],
            'fastestDeliveryProducts' => [],
            'catalogProducts' => $presenter->storefront($products->findStorefront(48, $clock->now())),
        ]);
    }

    #[Route('/rayons', name: 'app_categories', methods: ['GET'])]
    public function categories(CategoryProvider $categories): Response
    {
        return $this->render('categories/index.html.twig', [
            'categories' => $categories->all(),
            'productCounts' => [], // category id => number of products (empty until catalog exists)
        ]);
    }
}
