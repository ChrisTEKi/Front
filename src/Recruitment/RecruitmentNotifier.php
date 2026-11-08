<?php

namespace App\Recruitment;

use App\Contact\SupportChannel;
use App\Entity\Interview;
use App\Entity\JobApplication;
use App\Enum\InterviewMode;
use App\Notification\MailDeliveryException;
use App\Notification\TransactionalMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mails du recrutement.
 *
 * AU CANDIDAT (dans la langue qu'il utilisait sur le site, adressés à son NOM) : accusé de réception, refus, convocation à un
 * entretien (téléphone ou sur place), changement et annulation d'entretien. Un seul gabarit (templates/emails/job.*.twig) ; les textes
 * sont dans translations/emails.<langue>.yaml sous `job.<type>.subject|title|body`.
 *
 * À L'ÉQUIPE (adresse GOBUY_SUPPORT_EMAIL) : une nouvelle candidature est arrivée, avec le lien vers sa fiche. Les pièces ne sont JAMAIS
 * jointes à ce message : elles se lisent dans l'administration (accès contrôlé et tracé).
 *
 * Un échec d'envoi n'annule jamais une décision déjà enregistrée : chaque méthode destinée au candidat retourne FAUX si le message n'est
 * pas parti, pour que l'administrateur en soit averti et contacte la personne autrement.
 */
final class RecruitmentNotifier
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly MailerInterface $plainMailer,
        private readonly SupportChannel $channel,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer.from_email%')]
        private readonly string $fromEmail,
        #[Autowire('%app.mailer.from_name%')]
        private readonly string $fromName,
    ) {
    }

    public function applicationReceived(JobApplication $application): bool
    {
        return $this->toCandidate($application, 'application_received', [], null);
    }

    public function refused(JobApplication $application): bool
    {
        return $this->toCandidate($application, 'application_refused', [], $application->getRefusalNote());
    }

    /** @param bool $updated vrai si l'entretien existait déjà (date, mode ou lieu modifiés) */
    public function interviewScheduled(Interview $interview, bool $updated): bool
    {
        $application = $interview->getApplication();
        $when = RecruitmentTime::format($interview->getScheduledAt(), $application->getLocale());
        $phone = InterviewMode::Phone === $interview->getMode();
        $params = ['%date%' => $when['date'], '%time%' => $when['time'], '%duration%' => $interview->getDurationMinutes()]
            + ($phone ? ['%phone%' => $application->getPhone()] : ['%place%' => (string) $interview->getLocation()]);

        return $this->toCandidate($application, ($updated ? 'interview_updated_' : 'interview_scheduled_').($phone ? 'phone' : 'in_person'), $params, $interview->getNote());
    }

    public function interviewCancelled(Interview $interview): bool
    {
        $application = $interview->getApplication();
        $when = RecruitmentTime::format($interview->getScheduledAt(), $application->getLocale());

        return $this->toCandidate($application, 'interview_cancelled', ['%date%' => $when['date'], '%time%' => $when['time']], null);
    }

    /** Prévient l'équipe qu'une candidature vient d'arriver (rien n'est envoyé sans adresse de support configurée). */
    public function notifyTeam(JobApplication $application): void
    {
        $to = $this->channel->email();
        if (null === $to) {
            return;
        }
        $title = $this->oneLine($application->getOffer()->getTitle());
        $name = $this->oneLine($application->getFullName());
        $link = $this->urls->generate('app_admin_recruitment_application', ['id' => $application->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $this->plainMailer->send((new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($to)
                // Pas de « reply-to » : le candidat se contacte depuis sa fiche, jamais en répondant à ce message.
                ->subject('[GoBuy] Nouveau candidat — '.$title.' — '.$name)
                ->text(implode("\n", [
                    'Une nouvelle candidature vient d\'arriver.',
                    '',
                    'Offre : '.$title,
                    'Candidat : '.$name,
                    'Pièces jointes : '.\count($application->getDocuments()),
                    'Fiche (CV, lettre, entretiens) : '.$link,
                    '',
                    'Les pièces ne sont pas jointes à ce message : elles se lisent dans l\'administration (droit « recrutement »).',
                ])));
        } catch (\Throwable $e) {
            $this->logger->error('Échec d\'envoi de l\'avis de candidature à l\'équipe : {error}', ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, scalar> $params */
    private function toCandidate(JobApplication $application, string $kind, array $params, ?string $note): bool
    {
        $params += ['%offer%' => $this->oneLine($application->getOffer()->getTitle()), '%name%' => $this->oneLine($application->getFullName())];

        try {
            $this->mailer->send(
                $application->getEmail(),
                $this->oneLine($application->getFullName()),
                $application->getLocale(),
                'job.'.$kind.'.subject',
                $params,
                'job',
                ['kind' => $kind, 'params' => $params, 'note' => $note],
            );

            return true;
        } catch (MailDeliveryException) {
            $this->logger->warning('E-mail de recrutement "{kind}" non envoyé.', ['kind' => $kind]);

            return false;
        }
    }

    /** Texte sur une seule ligne, sans caractère de contrôle : il part dans un sujet ou un nom de destinataire. */
    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }
}
