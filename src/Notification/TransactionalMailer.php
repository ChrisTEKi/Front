<?php

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Point d'entrée UNIQUE pour envoyer un e-mail transactionnel (code OTP, invitation, confirmation de commande…).
 *
 * Il garantit pour tous les e-mails :
 *  - le même expéditeur (paramètres app.mailer.from_*),
 *  - la LANGUE du destinataire : le sujet et le contenu sont traduits dans sa langue, peu importe la langue
 *    de la personne qui a déclenché l'envoi (ex. : un Super Admin francophone invite un admin hispanophone),
 *  - deux versions du message : HTML (jolie) + texte brut (accessibilité, anti-spam),
 *  - un échec propre (MailDeliveryException) sans jamais exposer le détail technique ni le contenu du message.
 *
 * Gabarits : templates/emails/<nom>.html.twig et templates/emails/<nom>.txt.twig.
 * Textes : translations/emails.<langue>.yaml.
 */
final class TransactionalMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer.from_email%')]
        private readonly string $fromEmail,
        #[Autowire('%app.mailer.from_name%')]
        private readonly string $fromName,
    ) {
    }

    /**
     * @param string               $subjectKey Clé de traduction du sujet (domaine « emails »)
     * @param array<string, mixed> $subjectParams Paramètres du sujet (ex. ['%order%' => 'GB-2026-123456'])
     * @param array<string, mixed> $context   Variables transmises aux gabarits
     *
     * @throws MailDeliveryException
     */
    public function send(
        string $toEmail,
        string $toName,
        string $locale,
        string $subjectKey,
        array $subjectParams,
        string $template,
        array $context = [],
    ): void {
        try {
            // Tout ce qui est traduit/rendu dans la fonction anonyme l'est dans la langue du destinataire.
            $this->localeSwitcher->runWithLocale($locale, function () use ($toEmail, $toName, $locale, $subjectKey, $subjectParams, $template, $context): void {
                $email = (new TemplatedEmail())
                    ->from(new Address($this->fromEmail, $this->fromName))
                    ->to(new Address($toEmail, $toName))
                    ->subject($this->translator->trans($subjectKey, $subjectParams, 'emails'))
                    ->htmlTemplate('emails/'.$template.'.html.twig')
                    ->textTemplate('emails/'.$template.'.txt.twig')
                    ->context($context + ['locale' => $locale, 'recipient_name' => $toName]);

                $this->mailer->send($email);
            });
        } catch (\Throwable $e) {
            // On journalise le type d'erreur et le gabarit, JAMAIS le contexte (il peut contenir un code ou un lien secret).
            $this->logger->error('Échec d\'envoi d\'e-mail "{template}" : {error}', ['template' => $template, 'error' => $e->getMessage()]);

            throw new MailDeliveryException('Échec d\'envoi d\'e-mail.', 0, $e);
        }
    }
}
