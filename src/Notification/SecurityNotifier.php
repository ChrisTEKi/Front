<?php

namespace App\Notification;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avis de sécurité envoyés par e-mail après un changement sensible du compte.
 *
 * Pourquoi : si quelqu'un d'autre modifie le mot de passe ou la double authentification, le vrai titulaire
 * est prévenu immédiatement et peut réagir (« ce n'était pas moi »).
 *
 * Ces e-mails sont « au mieux » : si l'envoi échoue, l'action (changer le mot de passe…) n'est PAS annulée,
 * l'erreur est seulement journalisée. Bloquer un changement de mot de passe parce que le SMTP est en panne
 * serait pire que de ne pas envoyer l'avis.
 */
final class SecurityNotifier
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function passwordChanged(User $user): void
    {
        $this->notice($user, 'password_changed');
    }

    public function twoFactorChanged(User $user): void
    {
        $this->notice($user, 'two_factor_changed');
    }

    /** Suppression programmée : indique la date de suppression définitive et comment annuler. */
    public function deletionScheduled(User $user): void
    {
        $this->notice($user, 'deletion_scheduled', $user->getDeletionScheduledAt());
    }

    public function accountRestored(User $user): void
    {
        $this->notice($user, 'account_restored');
    }

    /** Le numéro de téléphone du compte vient d'être changé (la 2FA par SMS est alors coupée). */
    public function phoneChanged(User $user): void
    {
        $this->notice($user, 'phone_changed');
    }

    /**
     * L'adresse e-mail de connexion vient de changer. L'avis part vers l'ANCIENNE adresse (le compte n'y est plus rattaché) :
     * si ce n'est pas le titulaire qui a fait le changement, c'est le seul endroit où il peut encore le voir passer.
     * L'adresse d'arrivée n'est donnée que masquée.
     */
    public function emailChanged(string $oldEmail, string $name, string $locale, string $newEmailMasked): void
    {
        try {
            $this->mailer->send($oldEmail, $name, $locale, 'notice.email_changed.subject', [], 'notice', ['notice' => 'email_changed', 'date' => null, 'detail' => $newEmailMasked]);
        } catch (MailDeliveryException) {
            $this->logger->warning('Avis de changement d\'e-mail non envoyé.');
        }
    }

    /**
     * Quelqu'un a demandé, depuis un AUTRE compte, à utiliser l'adresse de ce compte comme nouvelle adresse de connexion.
     * Aucune modification n'a eu lieu ; le titulaire est simplement prévenu (voir ContactChangeService : réponse « leurre »).
     */
    public function emailAddressInUse(User $owner): void
    {
        $this->notice($owner, 'email_in_use');
    }

    /**
     * Dernier message après la suppression DÉFINITIVE du compte. Il reçoit l'adresse, le nom et la langue en paramètres
     * (le compte n'existe plus en base à ce moment-là) et ne les conserve nulle part.
     */
    public function accountPurged(string $email, string $name, string $locale): void
    {
        try {
            $this->mailer->send($email, $name, $locale, 'notice.account_purged.subject', [], 'notice', ['notice' => 'account_purged', 'date' => null]);
        } catch (MailDeliveryException) {
            $this->logger->warning('Avis de suppression définitive non envoyé.');
        }
    }

    /**
     * Quelqu'un a tenté de créer un compte avec l'adresse d'un compte EXISTANT (voir RegistrationService).
     * Le message oriente le vrai titulaire vers la connexion ou la réinitialisation du mot de passe.
     */
    public function alreadyRegistered(User $existing): void
    {
        try {
            $this->mailer->send(
                $existing->getEmail(),
                $existing->getFullName(),
                $existing->getLocale(),
                'already_registered.subject',
                [],
                'already_registered',
                [
                    'login_url' => $this->urls->generate('app_auth', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'reset_url' => $this->urls->generate('app_auth', ['view' => 'forgot'], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            );
        } catch (MailDeliveryException) {
            // Déjà journalisé par TransactionalMailer ; on ne révèle rien à l'écran.
        }
    }

    /** Décision sur une demande de compte fournisseur (approuvé / refusé + motif). */
    public function supplierDecision(User $supplier, bool $approved, ?string $reason = null): void
    {
        try {
            $this->mailer->send(
                $supplier->getEmail(),
                $supplier->getFullName(),
                $supplier->getLocale(),
                $approved ? 'supplier_decision.approved.subject' : 'supplier_decision.rejected.subject',
                [],
                'supplier_decision',
                [
                    'approved' => $approved,
                    'reason' => $reason,
                    'dashboard_url' => $this->urls->generate('app_supplier_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            );
        } catch (MailDeliveryException) {
        }
    }

    private function notice(User $user, string $notice, ?\DateTimeImmutable $date = null): void
    {
        try {
            $this->mailer->send(
                $user->getEmail(),
                $user->getFullName(),
                $user->getLocale(),
                'notice.'.$notice.'.subject',
                [],
                'notice',
                ['notice' => $notice, 'date' => $date],
            );
        } catch (MailDeliveryException $e) {
            $this->logger->warning('Avis de sécurité "{notice}" non envoyé.', ['notice' => $notice]);
        }
    }
}
