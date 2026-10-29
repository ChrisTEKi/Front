<?php

namespace App\Controller;

use App\Service\CategoryProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(CategoryProvider $categories): Response
    {
        return $this->render('home/index.html.twig', [
            'categories' => $categories->all(),
            // Catalog collections are intentionally empty: no fictional/mock data.
            // Wire these to a repository once the back-end (Doctrine) exists.
            'flashSaleProducts' => [],
            'fastestDeliveryProducts' => [],
            'catalogProducts' => [],
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
