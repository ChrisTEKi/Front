<?php

namespace App\Tests\Auth;

use App\Entity\AuditLog;
use App\Entity\SupplierDocument;
use App\Entity\User;
use App\Enum\Permission;
use App\Enum\SupplierDocumentKind;
use App\Supplier\SupplierDocuments;
use App\Tests\Support\AppTestCase;

/**
 * Pièces justificatives de l'inscription fournisseur : pièce d'identité valide (toujours obligatoire) et justificatif NIF / STAT
 * (obligatoire pour une entreprise). Contrôle des fichiers par le SERVEUR, stockage en base, lecture réservée aux administrateurs.
 */
final class SupplierDocumentsTest extends AppTestCase
{
    private const SUPPLIER = [
        'fullName' => 'Razafy Marie', 'email' => 'marie@boutique.mg', 'password' => self::PASSWORD, 'phone' => '033 12 345 67',
        'companyName' => 'Boutique Marie', 'companyAddress' => 'Lot 12 Analakely, Antananarivo',
    ];

    private function png(string $name = 'cin.png'): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        return $this->uploadFile((string) base64_decode(self::SAMPLE_PNG_BASE64), $name, 'image/png');
    }

    private function countDocuments(): int
    {
        return $this->em()->getRepository(SupplierDocument::class)->count([]);
    }

    private function supplierUser(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => 'marie@boutique.mg']) ?? throw new \LogicException('Fournisseur introuvable.');
    }

    // ------------------------------------------------------------------ Dépôt

    public function testBothDocumentsAreStoredWithTheAccount(): void
    {
        $r = $this->registerSupplierApi(self::SUPPLIER + ['application' => ['activityType' => 'company', 'taxId' => '1234567890']]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('verify_email', $r['step']);
        $documents = $this->em()->getRepository(SupplierDocument::class)->findAll();
        self::assertCount(2, $documents);
        $byKind = [];
        foreach ($documents as $document) {
            $byKind[$document->getKind()->value] = $document;
            self::assertSame($this->supplierUser()->getId()->toRfc4122(), $document->getOwner()->getId()->toRfc4122());
        }
        self::assertSame('image/png', $byKind['identity']->getContentType());
        self::assertSame('cin.png', $byKind['identity']->getFileName());
        self::assertSame((string) base64_decode(self::SAMPLE_PNG_BASE64), $byKind['identity']->getContent());
        self::assertSame('application/pdf', $byKind['tax']->getContentType());
        self::assertSame(self::SAMPLE_PDF, $byKind['tax']->getContent());
    }

    public function testIdentityDocumentIsAlwaysRequired(): void
    {
        $r = $this->registerSupplierApi(self::SUPPLIER, []);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('identityDocument', $r['errors']);
        self::assertSame('This document is required.', $r['errors']['identityDocument'][0] ?? null, 'Messages en anglais : la langue des tests est « en » par défaut.');
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => 'marie@boutique.mg']), 'Aucun compte ne doit être créé sans pièce d\'identité.');
        self::assertSame(0, $this->countDocuments());
    }

    public function testPlainJsonWithoutFilesIsRefusedLikeAnIncompleteForm(): void
    {
        $r = $this->api('POST', '/auth/register/supplier', self::SUPPLIER);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('identityDocument', $r['errors']);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    public function testTaxDocumentIsRequiredForACompanyOnly(): void
    {
        $identityOnly = ['identityDocument' => $this->png()];

        $r = $this->registerSupplierApi(self::SUPPLIER + ['application' => ['activityType' => 'company']], $identityOnly);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['taxDocument'], array_keys($r['errors']), 'Une entreprise doit joindre son NIF / STAT.');

        $this->registerSupplierApi(self::SUPPLIER + ['application' => ['activityType' => 'individual']], $identityOnly);
        self::assertSame(200, $this->httpStatus(), 'Un particulier n\'a besoin que de sa pièce d\'identité.');
        self::assertSame(1, $this->countDocuments());
        self::assertSame(SupplierDocumentKind::Identity, $this->em()->getRepository(SupplierDocument::class)->findOneBy([])->getKind());
    }

    public function testTaxDocumentIsOptionalWithoutApplicationAnswers(): void
    {
        // Formulaire rapide de /connexion : pas de réponses détaillées, donc pas de type d'activité → NIF / STAT facultatif.
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $this->png()]);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, $this->countDocuments());
    }

    // ------------------------------------------------------------------ Contrôle des fichiers

    public function testFilesAreCheckedByContentNotByName(): void
    {
        $disguised = $this->uploadFile('<html><script>alert(1)</script></html>', 'identite.pdf', 'application/pdf');
        $r = $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $disguised]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(['identityDocument'], array_keys($r['errors']));
        self::assertSame('Format not accepted: PDF, JPEG or PNG only.', $r['errors']['identityDocument'][0]);

        $exe = $this->uploadFile("MZ\x90\x00\x03\x00\x00\x00", 'cin.png', 'image/png');
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $exe]);
        self::assertSame(422, $this->httpStatus(), 'Un exécutable renommé en image est refusé.');
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    public function testOversizedAndEmptyFilesAreRefused(): void
    {
        $big = $this->uploadFile(str_pad(self::SAMPLE_PDF, SupplierDocuments::MAX_FILE_BYTES + 1, ' '), 'gros.pdf');
        $r = $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $big]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('This file is over 2 MB.', $r['errors']['identityDocument'][0]);

        // Juste à la limite : accepté.
        $limit = $this->uploadFile(str_pad(self::SAMPLE_PDF, SupplierDocuments::MAX_FILE_BYTES, ' '), 'limite.pdf');
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $limit]);
        self::assertSame(200, $this->httpStatus());
    }

    public function testEmptyFileIsRefused(): void
    {
        $r = $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $this->uploadFile('', 'vide.pdf')]);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('identityDocument', $r['errors']);
    }

    public function testFileNameIsCleanedAndExtensionFollowsTheRealType(): void
    {
        $file = $this->uploadFile((string) base64_decode(self::SAMPLE_PNG_BASE64), '../../etc/<script>cin?.exe', 'application/octet-stream');
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $file]);

        self::assertSame(200, $this->httpStatus());
        $name = $this->em()->getRepository(SupplierDocument::class)->findOneBy([])->getFileName();
        self::assertMatchesRegularExpression('/^[\p{L}\p{N}._() -]+\.png$/u', $name);
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('<', $name);
    }

    public function testDocumentErrorsAreReportedTogetherWithFieldErrors(): void
    {
        $r = $this->registerSupplierApi(['phone' => ''] + self::SUPPLIER, []);

        self::assertSame(422, $this->httpStatus());
        self::assertArrayHasKey('phone', $r['errors']);
        self::assertArrayHasKey('identityDocument', $r['errors']);
    }

    // ------------------------------------------------------------------ Effacement

    public function testDocumentsDisappearWithTheAccount(): void
    {
        $this->registerSupplierApi(self::SUPPLIER);
        self::assertSame(2, $this->countDocuments());

        $this->em()->getConnection()->executeStatement('DELETE FROM users WHERE email = :e', ['e' => 'marie@boutique.mg']);

        self::assertSame(0, $this->countDocuments(), 'Les pièces d\'identité sont effacées avec le compte (suppression définitive).');
    }

    // ------------------------------------------------------------------ Lecture par l'administration

    private function download(string $supplierId, string $kind): void
    {
        $this->client->request('GET', '/admin/fournisseurs/'.$supplierId.'/pieces/'.$kind);
    }

    public function testAdminWithTheRightDownloadsAnAttachmentAndTheOpeningIsAudited(): void
    {
        $this->registerSupplierApi(self::SUPPLIER);
        $supplierId = $this->supplierUser()->getId()->toRfc4122();
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('rh@example.com', 'support', [Permission::SuppliersApprove]));

        $this->download($supplierId, 'identity');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame((string) base64_decode(self::SAMPLE_PNG_BASE64), $response->getContent());

        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.supplier.document_viewed']);
        self::assertNotNull($log);
        self::assertSame($supplierId, $log->getTargetId());
        self::assertSame(['kind' => 'identity'], $log->getMetadata(), 'Le journal ne garde ni le contenu ni le nom du fichier.');

        $this->download($supplierId, 'tax');
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testDownloadNeedsTheRightToApproveSuppliers(): void
    {
        $this->registerSupplierApi(self::SUPPLIER);
        $supplierId = $this->supplierUser()->getId()->toRfc4122();
        $this->newBrowser();
        // Le profil Compta voit les fournisseurs (suppliers.view) mais ne les valide pas : pas d'accès aux pièces d'identité.
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $this->download($supplierId, 'identity');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.supplier.document_viewed']));
    }

    public function testDownloadIsNotFoundForMissingDocumentClientsAndUnknownKinds(): void
    {
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $this->png()]);
        $supplierId = $this->supplierUser()->getId()->toRfc4122();
        $client = $this->createUser('client@example.com');
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('rh@example.com', 'support', [Permission::SuppliersApprove]));

        $this->download($supplierId, 'tax');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Pas de NIF / STAT déposé.');
        $this->download($client->getId()->toRfc4122(), 'identity');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Un client n\'a pas de pièces.');
        $this->download($supplierId, 'passeport');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminScreensListTheDocumentsOrSayTheyAreMissing(): void
    {
        $this->registerSupplierApi(self::SUPPLIER, ['identityDocument' => $this->png('ma-cin.png')]);
        $supplierId = $this->supplierUser()->getId()->toRfc4122();
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('rh@example.com', 'support', [Permission::SuppliersView, Permission::SuppliersApprove]));

        // Fiche du compte.
        $crawler = $this->client->request('GET', '/admin/comptes/'.$supplierId);
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-supplier-document="identity"]');
        self::assertCount(1, $link);
        self::assertSame('/admin/fournisseurs/'.$supplierId.'/pieces/identity', $link->attr('href'));
        self::assertStringContainsString('ma-cin.png', $link->text());
        self::assertCount(0, $crawler->filter('[data-supplier-document="tax"]'));
        self::assertStringContainsString('Non fournie', $crawler->filter('[data-supplier-documents]')->text());

        // Liste de validation du tableau de bord.
        $crawler = $this->client->request('GET', '/admin?tab=suppliers_approval');
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-supplier-document="identity"]')->count());
    }

    public function testViewOnlyAdminDoesNotSeeTheDocumentsAtAll(): void
    {
        $this->registerSupplierApi(self::SUPPLIER);
        $supplierId = $this->supplierUser()->getId()->toRfc4122();
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('compta@example.com', 'compta'));

        $crawler = $this->client->request('GET', '/admin/comptes/'.$supplierId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-supplier-documents]'));
        self::assertStringNotContainsString('cin.png', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin?tab=suppliers_approval');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('cin.png', (string) $this->client->getResponse()->getContent());
    }
}
