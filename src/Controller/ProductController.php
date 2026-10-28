<?php

namespace App\Controller;

use App\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/produit/{id}', name: 'app_product', methods: ['GET'])]
    public function show(string $id, CatalogProvider $catalog): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $catalog->findActiveProduct($id),
        ]);
    }
}
