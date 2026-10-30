<?php

namespace App\Contact;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Canal de contact du support GoBuy : « à quelle adresse e-mail arrivent les messages des formulaires du site ? ».
 *
 * L'adresse vient de la variable d'environnement GOBUY_SUPPORT_EMAIL (.env / .env.local, paramètre app.support.email).
 * Tant qu'elle est vide (ou invalide), le canal est DÉSACTIVÉ : le site masque alors les formulaires de contact,
 * de réclamation, d'avis et de candidature, au lieu d'afficher un formulaire qui n'enverrait rien nulle part.
 */
final class SupportChannel
{
    public function __construct(
        #[Autowire('%app.support.email%')]
        private readonly string $configuredEmail,
    ) {
    }

    /** L'adresse du support si elle est correctement configurée, sinon null (canal désactivé). */
    public function email(): ?string
    {
        $email = trim($this->configuredEmail);

        // filter_var refuse déjà les retours à la ligne ; le contrôle explicite documente l'intention (injection d'en-têtes).
        if ('' === $email || preg_match('/[\r\n]/', $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    public function isEnabled(): bool
    {
        return null !== $this->email();
    }
}
