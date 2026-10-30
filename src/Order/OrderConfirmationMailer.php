<?php

namespace App\Order;

use App\Entity\Order;
use App\Notification\MailDeliveryException;
use App\Notification\TransactionalMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mail automatique de confirmation de commande.
 *
 * Envoyé dans la LANGUE enregistrée sur la commande, à l'adresse e-mail copiée dans la commande.
 * Une fois parti, la date d'envoi est notée (`confirmation_email_sent_at`) : impossible d'envoyer deux fois le même e-mail.
 * S'il échoue (serveur d'e-mails indisponible), la date reste vide et la commande `app:orders:send-confirmations` réessaie.
 */
final class OrderConfirmationMailer
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return bool true si l'e-mail est parti (ou l'était déjà), false s'il n'a pas pu partir
     */
    public function send(Order $order): bool
    {
        if (null !== $order->getConfirmationEmailSentAt()) {
            return true; // déjà envoyé : jamais deux fois
        }
        $email = $order->getCustomerEmail();
        if (null === $email) {
            return false; // compte supprimé et anonymisé : plus personne à qui écrire
        }

        try {
            $this->mailer->send(
                $email,
                (string) $order->getCustomerName(),
                $order->getLocale(),
                'order_confirmation.subject',
                ['%order%' => $order->getOrderNumber()],
                'order_confirmation',
                [
                    'order' => $order,
                    // Lien absolu vers le suivi (la page exige d'être connecté avec le compte qui a passé la commande).
                    'trackingUrl' => $this->urls->generate('app_order_tracking', ['number' => $order->getOrderNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            );
        } catch (MailDeliveryException) {
            return false;
        }

        $order->markConfirmationEmailSent($this->clock->now());
        $this->em->flush();

        return true;
    }
}
