<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/compte', name: 'app_account', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('account/index.html.twig', [
            'user' => null, // replaced by the Symfony Security user once auth exists
            'orders' => [],
            'favorites' => [],
            'section' => $request->query->get('section', 'main'),
        ]);
    }

    #[Route('/connexion', name: 'app_auth', methods: ['GET'])]
    public function auth(Request $request): Response
    {
        return $this->render('auth/index.html.twig', [
            'mode' => $request->query->get('mode', 'login'),
        ]);
    }

    #[Route('/suivi/{number}', name: 'app_order_tracking', methods: ['GET'], defaults: ['number' => null])]
    public function tracking(Request $request, ?string $number): Response
    {
        $number ??= $request->query->get('number');

        return $this->render('account/tracking.html.twig', ['order' => null, 'number' => $number]);
    }
}
