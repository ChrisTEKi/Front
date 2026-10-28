<?php

namespace App\Controller;

use App\Service\CategoryProvider;
use App\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(CategoryProvider $categories, CatalogProvider $catalog): Response
    {
        return $this->render('home/index.html.twig', [
            'categories' => $categories->all(),
            'flashSaleProducts' => [],
            'fastestDeliveryProducts' => [],
            'catalogProducts' => $catalog->findActiveProducts(),
        ]);
    }

    #[Route('/rayons', name: 'app_categories', methods: ['GET'])]
    public function categories(CategoryProvider $categories, CatalogProvider $catalog): Response
    {
        return $this->render('categories/index.html.twig', [
            'categories' => $categories->all(),
            'productCounts' => $catalog->countActiveProductsByCategory(),
        ]);
    }
}
