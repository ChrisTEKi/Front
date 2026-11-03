<?php

namespace App\Notification;

use App\Contact\SupportChannel;
use App\Entity\SupplierAppeal;
use App\Entity\SupplierProfile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient l'ÉQUIPE GoBuy (adresse GOBUY_SUPPORT_EMAIL) qu'un fournisseur conteste une décision : le message du fournisseur et ses
 * pièces jointes arrivent par e-mail, avec le lien vers la fiche du compte. « Répondre » écrit directement au fournisseur.
 *
 * Sans adresse de support configurée, rien n'est envoyé : la contestation reste consultable dans l'espace administrateur
 * (fiche du compte). Un échec d'envoi n'annule jamais le dépôt : il est journalisé (sans le contenu du message).
 */
final class AppealNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SupportChannel $channel,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer.from_email%')]
        private readonly string $fromEmail,
        #[Autowire('%app.mailer.from_name%')]
        private readonly string $fromName,
    ) {
    }

    public function notifyTeam(SupplierAppeal $appeal, SupplierProfile $profile): void
    {
        $to = $this->channel->email();
        if (null === $to) {
            return;
        }
        $supplier = $appeal->getSupplier();
        $company = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $profile->getCompanyName());

        $lines = [
            'Un fournisseur conteste la décision prise sur son dossier.',
            '',
            'Société : '.$company,
            'Responsable : '.$supplier->getFullName().' <'.$supplier->getEmail().'>',
            'Téléphone : '.($supplier->getPhone() ?? '—'),
            'Décision contestée : '.$appeal->getContestedStatus().(null !== $appeal->getContestedReason() ? ' — '.$appeal->getContestedReason() : ''),
            'Pièces jointes : '.\count($appeal->getDocuments()),
            'Fiche du compte : '.$this->urls->generate('app_admin_account', ['id' => $supplier->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            '',
            'Message du fournisseur :',
            $appeal->getMessage(),
        ];

        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($to)
                ->replyTo(new Address($supplier->getEmail(), preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $supplier->getFullName())))
                // Aucun caractère de contrôle dans le sujet (le nom de société est saisi par un visiteur) : pas d'injection d'en-têtes.
                ->subject('[GoBuy] Contestation de dossier — '.$company)
                ->text(implode("\n", $lines));
            foreach ($appeal->getDocuments() as $document) {
                $email->attach($document->getContent(), $document->getFileName(), $document->getContentType());
            }
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec d\'envoi de l\'avis de contestation à l\'équipe : {error}', ['error' => $e->getMessage()]);
        }
    }
}
