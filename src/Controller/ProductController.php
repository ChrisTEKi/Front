<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/produit/{id}', name: 'app_product', methods: ['GET'])]
    public function show(string $id): Response
    {
        // No catalog yet: `product` is always null so the template shows its
        // "not found" state. Swap this for a real repository lookup once the
        // back-end (Doctrine entity) exists — the template already expects a
        // `product` array shaped like the mockup's Product interface.
        return $this->render('product/show.html.twig', ['product' => null]);
    }
}
