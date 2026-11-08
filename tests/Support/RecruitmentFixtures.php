<?php

namespace App\Tests\Support;

use App\Entity\ApplicationDocument;
use App\Entity\JobApplication;
use App\Entity\JobOffer;
use App\Enum\ContractType;
use App\Enum\DocumentKind;
use App\Recruitment\RecruitmentTime;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Aides des tests de recrutement : offre publiée, candidat créé en base (date de candidature au choix), fichiers de test, créneau futur,
 * dépôt par le formulaire public. À utiliser dans une classe qui étend AppTestCase.
 */
trait RecruitmentFixtures
{
    /** Contenu minimal reconnu comme PDF d'après son CONTENU (et non son nom). */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private function offer(string $title = 'Responsable logistique', bool $open = true, ?string $closing = null): JobOffer
    {
        $now = new \DateTimeImmutable();
        $offer = new JobOffer(null, $title, 'Opérations', 'Antananarivo', ContractType::Permanent, 'Piloter la logistique de dernier kilomètre de GoBuy sur Antananarivo.', 'Trois ans d\'expérience.', null === $closing ? null : new \DateTimeImmutable($closing, RecruitmentTime::zone()), $now);
        if ($open) {
            $offer->publish($now);
        }
        $this->em()->persist($offer);
        $this->em()->flush();
        $this->em()->clear();

        return $offer;
    }

    /** Candidat créé directement en base, avec un CV ; $applied = date de candidature (« -5 days »…). */
    private function candidate(JobOffer $offer, string $name = 'Marie Rakoto', string $email = 'marie@example.com', string $applied = 'now', string $locale = 'fr', bool $withLetter = false): JobApplication
    {
        $at = new \DateTimeImmutable($applied);
        $application = new JobApplication($this->em()->getReference(JobOffer::class, $offer->getId()), $name, $email, '+261341234567', 'Je suis très motivée.', $locale, $at);
        $application->addDocument(new ApplicationDocument($application, DocumentKind::Cv, 'mon-cv.pdf', 'application/pdf', self::PDF, $at));
        if ($withLetter) {
            $application->addDocument(new ApplicationDocument($application, DocumentKind::CoverLetter, 'lettre.pdf', 'application/pdf', self::PDF, $at));
        }
        $this->em()->persist($application);
        $this->em()->flush();
        $this->em()->clear();

        return $application;
    }

    private function appId(JobApplication $application): string
    {
        return $application->getId()->toRfc4122();
    }

    private function offerId(JobOffer $offer): string
    {
        return $offer->getId()->toRfc4122();
    }

    /** Valeur d'un champ datetime-local (heure de Madagascar) dans $days jours, à l'heure donnée. */
    private function slot(int $days = 3, int $hour = 9, int $minute = 30): string
    {
        return (new \DateTimeImmutable(sprintf('%+d days', $days), RecruitmentTime::zone()))->setTime($hour, $minute)->format('Y-m-d\TH:i');
    }

    /** Fichier temporaire envoyé comme par un navigateur. */
    private function upload(string $content, string $name = 'cv.pdf', string $mime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gbtest');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** Un PDF valide, complété d'octets pour atteindre (au moins) la taille voulue. */
    private function pdfOfSize(int $bytes, string $name = 'cv.pdf'): UploadedFile
    {
        return $this->upload(str_pad(self::PDF, $bytes, ' '), $name);
    }

    /**
     * Dépôt par le formulaire public (multipart, jeton CSRF dans l'en-tête).
     *
     * @param array<string, string>                                     $fields
     * @param array<string, \Symfony\Component\HttpFoundation\File\UploadedFile> $files
     *
     * @return array<string, mixed>
     */
    private function applyTo(JobOffer $offer, array $fields = [], ?array $files = null, string $ip = '198.51.100.20'): array
    {
        $fields += ['name' => 'Marie Rakoto', 'email' => 'marie@example.com', 'phone' => '034 12 345 67', 'message' => 'Bonjour, je postule.'];
        $files ??= ['cv' => $this->upload(self::PDF)];
        $this->client->request('POST', '/emplois/'.$this->offerId($offer).'/candidater', $fields, $files, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken(), 'REMOTE_ADDR' => $ip]);
        $this->em()->clear();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function applicationByEmail(string $email): ?JobApplication
    {
        $this->em()->clear();

        return $this->em()->getRepository(JobApplication::class)->findOneBy(['email' => $email]);
    }
}
