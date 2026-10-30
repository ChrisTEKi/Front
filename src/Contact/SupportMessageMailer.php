<?php

namespace App\Contact;

use App\Entity\User;
use App\Notification\MailDeliveryException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Transmet à l'équipe support (adresse GOBUY_SUPPORT_EMAIL) un message écrit sur le site.
 *
 * C'est un e-mail INTERNE, en texte brut et en français (langue de l'équipe) : pas de HTML, donc aucune possibilité
 * d'injecter du code dans le client de messagerie du support. Si le visiteur a laissé une adresse e-mail, elle devient
 * l'adresse de réponse (« Reply-To ») : l'équipe répond d'un clic.
 */
final class SupportMessageMailer
{
    /** Libellés (français) des types de messages, pour le sujet et le corps du mail. */
    private const KIND_LABELS = ['feedback' => 'Avis', 'contact' => 'Message', 'claim' => 'Réclamation', 'job' => 'Candidature spontanée'];
    private const REASON_LABELS = [
        'damaged' => 'Article endommagé ou non conforme',
        'delay' => 'Retard important de livraison',
        'missing' => 'Article manquant dans le colis',
        'refund' => 'Demande de remboursement',
        'other' => 'Autre motif',
    ];
    private const MOOD_LABELS = ['happy' => 'Satisfait', 'neutral' => 'Neutre', 'sad' => 'Insatisfait'];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SupportChannel $channel,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer.from_email%')]
        private readonly string $fromEmail,
        #[Autowire('%app.mailer.from_name%')]
        private readonly string $fromName,
    ) {
    }

    /**
     * @param string $locale Langue du visiteur (pour que l'équipe réponde dans la bonne langue)
     *
     * @throws MailDeliveryException si le canal est désactivé ou si le serveur d'e-mails refuse le message
     */
    public function send(SupportMessageData $data, string $locale, ?User $user): void
    {
        $to = $this->channel->email();
        if (null === $to) {
            throw new MailDeliveryException('Canal support non configuré.');
        }

        $label = self::KIND_LABELS[$data->kind] ?? $data->kind;
        $subject = '[GoBuy] '.$label.('claim' === $data->kind && '' !== $data->order ? ' — commande '.$data->order : '');

        $lines = ['Type : '.$label, 'Langue du visiteur : '.$locale];
        $lines[] = null === $user
            ? 'Compte GoBuy : visiteur non connecté'
            : 'Compte GoBuy : '.$user->getId()->toRfc4122().' ('.$user->getAccountType()->value.')';
        if ('' !== $data->name) {
            $lines[] = 'Nom : '.$data->name;
        }
        if ('' !== $data->contact) {
            $lines[] = 'Contact : '.$data->contact;
        }
        if ('' !== $data->order) {
            $lines[] = 'Commande : '.$data->order;
        }
        if (null !== $data->reason) {
            $lines[] = 'Motif : '.(self::REASON_LABELS[$data->reason] ?? $data->reason);
        }
        if (null !== $data->mood) {
            $lines[] = 'Humeur : '.(self::MOOD_LABELS[$data->mood] ?? $data->mood);
        }
        $lines[] = '';
        $lines[] = $data->message;

        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($to)
                // Retire tout caractère de contrôle du sujet : un numéro de commande saisi par un visiteur ne doit jamais
                // pouvoir ajouter un en-tête d'e-mail (injection d'en-têtes).
                ->subject((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject))
                ->text(implode("\n", $lines));
            if ($data->contactIsEmail()) {
                $email->replyTo($data->contact);
            }

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // On journalise la cause technique, jamais le message du visiteur (il peut contenir des données personnelles).
            $this->logger->error('Échec d\'envoi d\'un message au support : {error}', ['error' => $e->getMessage()]);

            throw new MailDeliveryException('Échec d\'envoi du message au support.', 0, $e);
        }
    }
}
