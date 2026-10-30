<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Noyau de l'application.
 *
 * MicroKernelTrait charge automatiquement :
 *  - config/packages/*.yaml (configuration des bundles, par environnement),
 *  - config/services.yaml   (déclaration des services),
 *  - config/routes.yaml     (routes).
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Valeur "placeholder" livrée dans .env : elle DOIT être remplacée en production.
     * Le secret signe les tokens CSRF, les cookies et les liens : un secret connu = faille.
     */
    private const PLACEHOLDER_SECRET = 'changeme_generate_a_real_secret';

    public function boot(): void
    {
        // Garde-fou : hors « dev » et « test » (donc aussi sur un serveur « staging » ou « preprod » joignable depuis Internet),
        // on refuse de démarrer avec un secret faible/connu.
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            $secret = (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? '');
            if ('' === $secret || self::PLACEHOLDER_SECRET === $secret || \strlen($secret) < 32) {
                throw new \LogicException('APP_SECRET est absent, trop court (< 32 caractères) ou égal au placeholder : définissez un secret aléatoire dans .env.local ou dans les secrets Symfony.');
            }
        }

        // Toutes les dates sont manipulées et stockées en UTC ; l'affichage est
        // converti dans le fuseau de Madagascar par Twig (voir config/packages/twig.yaml).
        // On le fixe AVANT le démarrage du conteneur pour que tous les services en héritent.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
