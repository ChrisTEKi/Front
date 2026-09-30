<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/panier', name: 'app_cart', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('cart/index.html.twig', ['items' => []]);
    }

    #[Route('/commande', name: 'app_checkout', methods: ['GET'])]
    public function checkout(): Response
    {
        return $this->render('checkout/index.html.twig', ['items' => [], 'user' => null]);
    }
}
