<?php

namespace App\Twig;

use App\Contact\SupportChannel;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig `support_email()` : adresse e-mail du support (ou null si elle n'est pas configurée).
 *
 * Les gabarits s'en servent pour n'afficher les formulaires de contact / réclamation / avis / candidature
 * QUE lorsqu'ils peuvent réellement envoyer un message.
 */
final class SupportExtension extends AbstractExtension
{
    public function __construct(private readonly SupportChannel $channel)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('support_email', $this->channel->email(...))];
    }
}
