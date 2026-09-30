<?php

namespace App\Controller;

use App\Service\CategoryProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BecomeVendorController extends AbstractController
{
    #[Route('/devenir-partenaire', name: 'app_become_vendor', methods: ['GET'])]
    public function index(CategoryProvider $categories): Response
    {
        return $this->render('vendor/become.html.twig', ['categories' => $categories->all()]);
    }
}
