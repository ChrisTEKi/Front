<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Restriction (facultative) de l'espace administration à certaines adresses IP.
 *
 * Configuration : variable d'environnement ADMIN_ALLOWED_IPS, liste séparée par des virgules d'adresses
 * ou de plages CIDR, ex. « 41.188.0.10,196.192.0.0/16,2001:db8::/32 ».
 *   - Vide (défaut)  → aucune restriction (confort en développement) ;
 *   - Renseignée     → un admin ne peut se connecter QUE depuis ces adresses (bureau, VPN de l'entreprise).
 * C'est une couche de défense supplémentaire : même avec un mot de passe volé, l'attaquant doit aussi
 * se trouver sur le réseau autorisé.
 */
final class AdminAccessPolicy
{
    /** @var list<string> */
    private readonly array $allowedIps;

    public function __construct(
        #[Autowire('%env(ADMIN_ALLOWED_IPS)%')]
        string $allowedIps,
    ) {
        // « a, b ,,c » → ['a', 'b', 'c'] : on retire espaces et entrées vides.
        $this->allowedIps = array_values(array_filter(array_map('trim', explode(',', $allowedIps)), static fn (string $ip): bool => '' !== $ip));
    }

    /** Cette adresse IP a-t-elle le droit d'accéder à l'administration ? */
    public function isIpAllowed(?string $ip): bool
    {
        if ([] === $this->allowedIps) {
            return true; // aucune restriction configurée
        }
        if (null === $ip) {
            return false; // IP inconnue alors qu'une liste existe : on refuse par prudence
        }

        return IpUtils::checkIp($ip, $this->allowedIps);
    }

    public function isRestricted(): bool
    {
        return [] !== $this->allowedIps;
    }
}
