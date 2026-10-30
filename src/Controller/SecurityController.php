<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Route de déconnexion.
 *
 * Le code de cette méthode n'est JAMAIS exécuté : le pare-feu Symfony (config/packages/security.yaml → logout)
 * intercepte la requête avant. La route doit toutefois exister pour que {{ logout_path() }} puisse générer
 * le lien (qui contient un jeton CSRF : un site tiers ne peut pas déconnecter un utilisateur de force).
 */
final class SecurityController extends AbstractController
{
    #[Route('/deconnexion', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par le pare-feu (logout).');
    }
}
