<?php

namespace App\Notification;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * E-mails envoyés à un COMPTE à la suite d'une décision de l'équipe GoBuy (produit retiré, catalogue retiré, dossier réexaminé…).
 *
 * Un seul gabarit (templates/emails/moderation.*.twig) ; chaque message est décrit par une clé « kind » et ses textes dans
 * translations/emails.<langue>.yaml sous `moderation.<kind>.subject|title|body`. Les paramètres (%detail%, %count%…) sont
 * insérés dans le sujet et le corps. Un échec d'envoi ne bloque jamais la décision : il est seulement journalisé.
 */
final class ModerationNotifier
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar> $params paramètres de traduction (ex. ['%detail%' => 'motif', '%count%' => 12])
     */
    public function notify(User $user, string $kind, array $params = []): void
    {
        try {
            $this->mailer->send(
                $user->getEmail(),
                $user->getFullName(),
                $user->getLocale(),
                'moderation.'.$kind.'.subject',
                $params,
                'moderation',
                ['kind' => $kind, 'params' => $params],
            );
        } catch (MailDeliveryException) {
            $this->logger->warning('E-mail de décision "{kind}" non envoyé.', ['kind' => $kind]);
        }
    }
}
