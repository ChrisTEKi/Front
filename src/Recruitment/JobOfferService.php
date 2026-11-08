<?php

namespace App\Recruitment;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\JobOffer;
use App\Entity\User;
use App\Enum\ContractType;
use App\Enum\JobOfferStatus;
use App\Repository\JobApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Gestion des offres d'emploi par l'équipe (droit « recruitment.manage »).
 *
 * Une offre naît en BROUILLON (invisible). « Publier » la rend visible sur /emplois ; « Clôturer » la retire (les candidatures déjà
 * reçues restent consultables) ; la republier est possible. Une offre qui a reçu des candidatures ne se SUPPRIME pas : on la clôture.
 * Les textes sont nettoyés (espaces, caractères de contrôle) et bornés ; ils sont toujours échappés à l'affichage.
 */
final class JobOfferService
{
    public const TITLE_MIN = 3;
    public const TITLE_MAX = 120;
    public const DESCRIPTION_MIN = 20;
    public const DESCRIPTION_MAX = 8000;
    public const PROFILE_MAX = 4000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobApplicationRepository $applications,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $data title, department, location, contract, description, profile, closingDate (AAAA-MM-JJ)
     *
     * @throws RecruitmentException
     */
    public function create(User $admin, array $data): JobOffer
    {
        $now = $this->clock->now();
        [$title, $department, $location, $contract, $description, $profile, $closing] = $this->clean($data, null, $now);

        $offer = new JobOffer($admin, $title, $department, $location, $contract, $description, $profile, $closing, $now);
        $this->em->persist($offer);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_OFFER_SAVED, $admin, 'job_offer', $offer->getId()->toRfc4122(), ['created' => true, 'title' => $title]);

        return $offer;
    }

    /**
     * @param array<string, string> $data
     *
     * @throws RecruitmentException
     */
    public function update(User $admin, JobOffer $offer, array $data): void
    {
        $now = $this->clock->now();
        [$title, $department, $location, $contract, $description, $profile, $closing] = $this->clean($data, $offer, $now);

        $offer->update($title, $department, $location, $contract, $description, $profile, $closing, $now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_OFFER_SAVED, $admin, 'job_offer', $offer->getId()->toRfc4122(), ['created' => false, 'title' => $title]);
    }

    /** @throws RecruitmentException « job_offer_already_open » */
    public function publish(User $admin, JobOffer $offer): void
    {
        $now = $this->clock->now();
        if (JobOfferStatus::Open === $offer->getStatus()) {
            throw new RecruitmentException('job_offer_already_open');
        }
        // Une date limite déjà dépassée rendrait l'offre invisible dès la publication : on le dit plutôt que de publier « dans le vide ».
        if (null !== $offer->getClosingDate() && !$offer->acceptsApplicationsIfOpen($now)) {
            throw new RecruitmentException('job_date_invalid');
        }

        $offer->publish($now);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_OFFER_PUBLISHED, $admin, 'job_offer', $offer->getId()->toRfc4122(), ['title' => $offer->getTitle()]);
    }

    /** @throws RecruitmentException « job_offer_not_open » */
    public function close(User $admin, JobOffer $offer): void
    {
        if (JobOfferStatus::Open !== $offer->getStatus()) {
            throw new RecruitmentException('job_offer_not_open');
        }

        $offer->close($this->clock->now());
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_OFFER_CLOSED, $admin, 'job_offer', $offer->getId()->toRfc4122(), ['title' => $offer->getTitle()]);
    }

    /** @throws RecruitmentException « job_offer_has_applications » */
    public function delete(User $admin, JobOffer $offer): void
    {
        if ($this->applications->countForOfferAll($offer) > 0) {
            throw new RecruitmentException('job_offer_has_applications');
        }

        $id = $offer->getId()->toRfc4122();
        $title = $offer->getTitle();
        $this->em->remove($offer);
        $this->em->flush();
        $this->audit->record(AuditAction::JOB_OFFER_DELETED, $admin, 'job_offer', $id, ['title' => $title]);
    }

    /**
     * @param array<string, string> $data
     *
     * @return array{0: string, 1: ?string, 2: ?string, 3: ContractType, 4: string, 5: ?string, 6: ?\DateTimeImmutable}
     *
     * @throws RecruitmentException
     */
    private function clean(array $data, ?JobOffer $existing, \DateTimeImmutable $now): array
    {
        $title = $this->line($data['title'] ?? '');
        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw new RecruitmentException('job_title_invalid', ['%min%' => self::TITLE_MIN, '%max%' => self::TITLE_MAX]);
        }
        $department = $this->optionalLine($data['department'] ?? '', 80);
        $location = $this->optionalLine($data['location'] ?? '', 120);
        $contract = ContractType::tryFrom($data['contract'] ?? '') ?? throw new RecruitmentException('job_contract_invalid');

        $description = $this->block($data['description'] ?? '');
        if (mb_strlen($description) < self::DESCRIPTION_MIN || mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new RecruitmentException('job_description_invalid', ['%min%' => self::DESCRIPTION_MIN, '%max%' => self::DESCRIPTION_MAX]);
        }
        $profile = $this->block($data['profile'] ?? '');
        if (mb_strlen($profile) > self::PROFILE_MAX) {
            throw new RecruitmentException('job_profile_too_long', ['%max%' => self::PROFILE_MAX]);
        }

        return [$title, $department, $location, $contract, $description, '' === $profile ? null : $profile, $this->closingDate($data['closingDate'] ?? '', $existing, $now)];
    }

    /** Date limite « AAAA-MM-JJ » (facultative). Une date passée n'est acceptée que si c'est celle que l'offre a déjà (modification d'une ancienne offre). */
    private function closingDate(string $raw, ?JobOffer $existing, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, RecruitmentTime::zone());
        if (false === $date || $date->format('Y-m-d') !== $raw || (int) $date->format('Y') > (int) $now->format('Y') + 3) {
            throw new RecruitmentException('job_date_invalid');
        }
        $today = $now->setTimezone(RecruitmentTime::zone())->format('Y-m-d');
        if ($raw < $today && $existing?->getClosingDate()?->format('Y-m-d') !== $raw) {
            throw new RecruitmentException('job_date_invalid');
        }

        return $date;
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $text)));
    }

    private function optionalLine(string $text, int $max): ?string
    {
        $line = $this->line($text);
        if (mb_strlen($line) > $max) {
            throw new RecruitmentException('job_field_too_long', ['%max%' => $max]);
        }

        return '' === $line ? null : $line;
    }

    /** Texte sur plusieurs lignes : retours à la ligne conservés (au plus une ligne vide d'affilée), caractères de contrôle retirés. */
    private function block(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $text);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
