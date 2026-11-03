<?php

namespace App\Tests\Moderation;

use App\Entity\AuditLog;
use App\Entity\SupplierAppeal;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AppealStatus;
use App\Enum\SupplierStatus;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\SupplierFixtures;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Re-validation d'un refus par l'équipe et contestation par le fournisseur (message + pièces jointes).
 */
final class SupplierAppealTest extends AppTestCase
{
    use SupplierFixtures;

    private const MESSAGE = 'Voici nos documents officiels (NIF et STAT) qui prouvent notre activité.';
    private const URL = '/fournisseur/api/contestation';

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Aides

    private function pdf(int $pad = 0): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n".str_repeat('x', $pad);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * Envoie une contestation comme le navigateur (multipart + jeton CSRF).
     *
     * @param array<string, string> $files nom du fichier => contenu
     */
    private function appeal(string $message, array $files = []): array
    {
        $uploaded = [];
        foreach ($files as $name => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'gobuy-appeal-');
            self::assertIsString($path);
            $this->tmp[] = $path;
            file_put_contents($path, $bytes);
            $uploaded[] = new UploadedFile($path, $name, 'application/octet-stream', null, true);
        }
        $this->client->request('POST', self::URL, ['message' => $message], ['files' => $uploaded], [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken(),
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '203.0.113.7',
        ]);
        $this->em()->clear();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function profile(User $supplier): SupplierProfile
    {
        $this->em()->clear();

        return $this->em()->getRepository(SupplierProfile::class)->findOneBy(['user' => $supplier->getId()]);
    }

    /** @return list<SupplierAppeal> */
    private function appeals(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(SupplierAppeal::class)->findBy([], ['createdAt' => 'ASC']);
    }

    private function decision(User $supplier, array $payload): array
    {
        return $this->api('POST', '/admin/api/fournisseurs/'.$supplier->getId()->toRfc4122().'/decision', $payload);
    }

    // ------------------------------------------------------------------ Re-validation par l'équipe

    public function testAdminCanRevalidateARefusedSupplierAndTheShopComesBack(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $product = $this->productFor($supplier, 'Casque audio', 120000);
        $this->client->request('GET', '/produit/'.$product->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Refusé : pas vendable.');

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $r = $this->decision($supplier, ['action' => 'approve']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('approved', $r['status']);
        $profile = $this->profile($supplier);
        self::assertSame(SupplierStatus::Approved, $profile->getStatus());
        self::assertNull($profile->getRejectionReason(), 'Le motif de refus disparaît à la re-validation.');
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0), 'To', 'Test Fournisseur <marie@boutique.mg>');
        self::assertEmailTextBodyContains(self::getMailerMessage(0), 'Ma Boutique');
        $log = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.supplier.approved']);
        self::assertSame('revalidated', $log->getMetadata()['operation']);
        self::assertSame('rejected', $log->getMetadata()['from']);

        $this->newBrowser();
        $this->client->request('GET', '/produit/'.$product->getId()->toRfc4122());
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Re-validé : de nouveau vendable.');
    }

    public function testAdminCanRefuseAnApprovedSupplierAndHisProductsLeaveTheShop(): void
    {
        $supplier = $this->supplierAccount();
        $product = $this->productFor($supplier, 'Casque audio', 120000);
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $r = $this->decision($supplier, ['action' => 'reject', 'reason' => 'oui']);
        self::assertSame('reason_required', $r['error']);

        $this->decision($supplier, ['action' => 'reject', 'reason' => 'Activité non conforme à nos conditions.']);
        self::assertSame(200, $this->httpStatus());
        $profile = $this->profile($supplier);
        self::assertSame(SupplierStatus::Rejected, $profile->getStatus());
        self::assertSame('Activité non conforme à nos conditions.', $profile->getRejectionReason());
        self::assertEmailCount(1);

        $this->newBrowser();
        $this->client->request('GET', '/produit/'.$product->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRevalidationNeedsVerifiedContactsAndAnActiveAccount(): void
    {
        $unverified = $this->supplierAccount('a@boutique.mg', 'rejected', '+261331110001', 'Boutique A', false);
        $suspended = $this->supplierAccount('b@boutique.mg', 'rejected', '+261331110002', 'Boutique B');
        $this->em()->getConnection()->executeStatement("UPDATE users SET status = 'suspended' WHERE id = :id", ['id' => $suspended->getId()->toRfc4122()]);
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));

        $r = $this->decision($unverified, ['action' => 'approve']);
        self::assertSame('supplier_not_verified', $r['error']);
        self::assertSame(SupplierStatus::Rejected, $this->profile($unverified)->getStatus());

        $r = $this->decision($suspended, ['action' => 'approve']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('account_not_active', $r['error']);
        self::assertSame(SupplierStatus::Rejected, $this->profile($suspended)->getStatus());
    }

    // ------------------------------------------------------------------ Page du fournisseur

    public function testOnlyARefusedOrSuspendedSupplierSeesTheAppealForm(): void
    {
        $this->signIn($this->supplierAccount('rej@boutique.mg', 'rejected', '+261331110001'));
        $this->client->request('GET', '/fournisseur/contestation');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-appeal-form', $html);
        self::assertStringContainsString('Dossier incomplet', $html);
        $this->client->request('GET', '/fournisseur');
        self::assertStringContainsString('data-appeal-link', (string) $this->client->getResponse()->getContent());

        $this->newBrowser();
        $this->signIn($this->supplierAccount('ok@boutique.mg', 'approved', '+261331110002', 'Boutique OK'));
        $this->client->request('GET', '/fournisseur/contestation');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-appeal-unavailable="appeal_not_needed"', $html);
        self::assertStringNotContainsString('data-appeal-form', $html);
        $this->client->request('GET', '/fournisseur');
        self::assertStringNotContainsString('data-appeal-link', (string) $this->client->getResponse()->getContent());
    }

    public function testClientsAndVisitorsCannotReachTheAppealArea(): void
    {
        $this->client->request('GET', '/fournisseur/contestation');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);

        $this->signIn($this->createUser('client@example.com'));
        $this->client->request('GET', '/fournisseur/contestation');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
        $this->appeal(self::MESSAGE);
        self::assertContains($this->httpStatus(), [302, 401, 403]);
        self::assertSame([], $this->appeals());
    }

    // ------------------------------------------------------------------ Dépôt

    public function testSupplierSendsAnAppealWithDocumentsAndTheTeamIsWarned(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $this->signIn($supplier);

        $r = $this->appeal(self::MESSAGE, ['../../évil nom.pdf.exe' => $this->pdf(), 'logo.png' => $this->png()]);

        self::assertSame(201, $this->httpStatus(), json_encode($r));
        $appeals = $this->appeals();
        self::assertCount(1, $appeals);
        $appeal = $appeals[0];
        self::assertSame(AppealStatus::Open, $appeal->getStatus());
        self::assertSame(self::MESSAGE, $appeal->getMessage());
        self::assertSame('rejected', $appeal->getContestedStatus());
        self::assertSame('Dossier incomplet', $appeal->getContestedReason());
        $docs = $appeal->getDocuments()->toArray();
        self::assertCount(2, $docs);
        self::assertSame('application/pdf', $docs[0]->getContentType());
        self::assertSame('image/png', $docs[1]->getContentType());
        self::assertStringEndsWith('.pdf', $docs[0]->getFileName(), 'Extension imposée par le type réel.');
        self::assertStringNotContainsString('/', $docs[0]->getFileName());
        self::assertSame($this->pdf(), $docs[0]->getContent());

        // E-mail à l'équipe (avec les pièces, « Répondre » écrit au fournisseur) puis accusé de réception au fournisseur.
        self::assertEmailCount(2);
        $team = self::getMailerMessage(0);
        self::assertEmailHeaderSame($team, 'To', 'support@gobuy.test');
        self::assertEmailHeaderSame($team, 'Reply-To', 'Test Fournisseur <marie@boutique.mg>');
        self::assertEmailTextBodyContains($team, self::MESSAGE);
        self::assertEmailTextBodyContains($team, '/admin/comptes/'.$supplier->getId()->toRfc4122());
        self::assertCount(2, $team->getAttachments());
        self::assertEmailHeaderSame(self::getMailerMessage(1), 'To', 'Test Fournisseur <marie@boutique.mg>');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'supplier.appeal.submitted']));
    }

    public function testAppealWithoutDocumentsIsAccepted(): void
    {
        $this->signIn($this->supplierAccount('marie@boutique.mg', 'suspended'));

        $this->appeal(self::MESSAGE);

        self::assertSame(201, $this->httpStatus());
        self::assertSame('suspended', $this->appeals()[0]->getContestedStatus());
    }

    public function testAppealIsValidatedStrictly(): void
    {
        $this->signIn($this->supplierAccount('marie@boutique.mg', 'rejected'));

        $r = $this->appeal('trop court');
        self::assertSame(422, $this->httpStatus());
        self::assertSame('appeal_message_length', $r['error']);

        $r = $this->appeal(self::MESSAGE, ['faux.pdf' => 'ceci est du texte, pas un PDF']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('appeal_file_type', $r['error'], 'Le type vient du contenu, pas du nom.');

        $r = $this->appeal(self::MESSAGE, ['virus.pdf' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00"]);
        self::assertSame('appeal_file_type', $r['error'], 'Un exécutable renommé est refusé.');

        $r = $this->appeal(self::MESSAGE, ['vide.pdf' => '']);
        self::assertSame('appeal_file_invalid', $r['error']);

        $six = [];
        foreach (range(1, 6) as $i) {
            $six['p'.$i.'.pdf'] = $this->pdf();
        }
        $r = $this->appeal(self::MESSAGE, $six);
        self::assertSame('appeal_too_many_files', $r['error']);

        $r = $this->appeal(self::MESSAGE, ['gros.pdf' => $this->pdf(2 * 1024 * 1024)]);
        self::assertSame(413, $this->httpStatus());
        self::assertSame('appeal_file_too_large', $r['error']);

        $four = [];
        foreach (range(1, 4) as $i) {
            $four['p'.$i.'.pdf'] = $this->pdf(1_900_000);
        }
        $r = $this->appeal(self::MESSAGE, $four);
        self::assertSame(413, $this->httpStatus());
        self::assertSame('appeal_files_total', $r['error'], json_encode($r));

        self::assertSame([], $this->appeals(), 'Aucun dépôt n\'a abouti.');
    }

    public function testOneOpenAppealAtATimeAndThreePerDecision(): void
    {
        $this->signIn($this->supplierAccount('marie@boutique.mg', 'rejected'));

        $this->appeal(self::MESSAGE);
        self::assertSame(201, $this->httpStatus());

        $r = $this->appeal(self::MESSAGE);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('appeal_open', $r['error']);

        foreach ([2, 3] as $n) {
            $this->em()->getConnection()->executeStatement("UPDATE supplier_appeals SET status = 'dismissed'");
            $this->appeal(self::MESSAGE.' (essai '.$n.')');
            self::assertSame(201, $this->httpStatus(), 'Essai '.$n);
        }

        $this->em()->getConnection()->executeStatement("UPDATE supplier_appeals SET status = 'dismissed'");
        $r = $this->appeal(self::MESSAGE.' (essai 4)');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('appeal_limit', $r['error']);
        self::assertCount(3, $this->appeals());
    }

    public function testApprovedAndPendingSuppliersCannotAppeal(): void
    {
        $this->signIn($this->supplierAccount('ok@boutique.mg', 'approved'));
        $r = $this->appeal(self::MESSAGE);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('appeal_not_needed', $r['error']);

        $this->newBrowser();
        $this->signIn($this->supplierAccount('att@boutique.mg', 'pending', '+261331110009', 'Boutique en attente'));
        $r = $this->appeal(self::MESSAGE);
        self::assertSame('appeal_not_needed', $r['error']);
        self::assertSame([], $this->appeals());
    }

    // ------------------------------------------------------------------ Décision de l'équipe

    private function openAppealFor(User $supplier, array $files = []): SupplierAppeal
    {
        $this->signIn($supplier);
        $this->appeal(self::MESSAGE, $files);
        self::assertSame(201, $this->httpStatus());
        $this->newBrowser();

        return $this->appeals()[0];
    }

    public function testAcceptingAnAppealRevalidatesTheSupplier(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $appeal = $this->openAppealFor($supplier);

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $r = $this->api('POST', '/admin/api/contestations/'.$appeal->getId()->toRfc4122().'/decision', ['action' => 'accept', 'note' => 'Pièces conformes, merci.']);

        self::assertSame(200, $this->httpStatus(), json_encode($r));
        self::assertSame(SupplierStatus::Approved, $this->profile($supplier)->getStatus());
        $stored = $this->appeals()[0];
        self::assertSame(AppealStatus::Accepted, $stored->getStatus());
        self::assertSame('Pièces conformes, merci.', $stored->getDecisionNote());
        self::assertNotNull($stored->getDecidedAt());
        self::assertSame('Admin moderation', $stored->getDecidedBy()->getFullName());
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(0), 'Pièces conformes, merci.');
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'admin.supplier.appeal.accepted']));
    }

    public function testDismissingNeedsAnExplanationAndKeepsTheDecision(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $appeal = $this->openAppealFor($supplier);
        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $uri = '/admin/api/contestations/'.$appeal->getId()->toRfc4122().'/decision';

        $r = $this->api('POST', $uri, ['action' => 'dismiss', 'note' => 'non']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('reason_required', $r['error']);
        self::assertTrue($this->appeals()[0]->isOpen());

        $this->api('POST', $uri, ['action' => 'dismiss', 'note' => 'Le numéro NIF fourni est illisible.']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(SupplierStatus::Rejected, $this->profile($supplier)->getStatus(), 'La décision est maintenue.');
        self::assertSame(AppealStatus::Dismissed, $this->appeals()[0]->getStatus());
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage(0), 'Le numéro NIF fourni est illisible.');

        $r = $this->api('POST', $uri, ['action' => 'accept']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('appeal_closed', $r['error']);

        $r = $this->api('POST', $uri, ['action' => 'explode']);
        self::assertContains($this->httpStatus(), [409, 422]);
    }

    public function testApprovingDirectlyClosesTheOpenAppeal(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $this->openAppealFor($supplier);

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $this->decision($supplier, ['action' => 'approve']);

        self::assertSame(AppealStatus::Accepted, $this->appeals()[0]->getStatus());
    }

    public function testDecidingNeedsTheApprovalRight(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $appeal = $this->openAppealFor($supplier);
        // « Support » n'a ni suppliers.view ni suppliers.approve.
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));

        $this->api('POST', '/admin/api/contestations/'.$appeal->getId()->toRfc4122().'/decision', ['action' => 'accept']);

        self::assertSame(403, $this->httpStatus());
        self::assertTrue($this->appeals()[0]->isOpen());
        self::assertSame(SupplierStatus::Rejected, $this->profile($supplier)->getStatus());
    }

    // ------------------------------------------------------------------ Pièces jointes et fiche

    public function testAdminDownloadsAttachmentsSafely(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $appeal = $this->openAppealFor($supplier, ['justificatif.pdf' => $this->pdf()]);
        $doc = $appeal->getDocuments()->first();
        $url = '/admin/contestations/'.$appeal->getId()->toRfc4122().'/pieces/'.$doc->getId()->toRfc4122();

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->pdf(), $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('justificatif.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        // Mauvaise pièce ou mauvaise contestation : introuvable.
        $this->client->request('GET', '/admin/contestations/'.$appeal->getId()->toRfc4122().'/pieces/01920000-0000-7000-8000-00000000ffff');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // Sans le droit « suppliers.view » : refusé.
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));
        $this->client->request('GET', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Un fournisseur ne télécharge pas : ce n'est pas son espace.
        $this->newBrowser();
        $this->signIn($supplier);
        $this->client->request('GET', $url);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
    }

    public function testAccountPageShowsTheAppealAndIsProtected(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $this->openAppealFor($supplier, ['justificatif.pdf' => $this->pdf()]);
        $client = $this->createUser('client@example.com');
        $id = $supplier->getId()->toRfc4122();

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $this->client->request('GET', '/admin/comptes/'.$id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ma Boutique', $html);
        self::assertStringContainsString(self::MESSAGE, $html);
        self::assertStringContainsString('justificatif.pdf', $html);
        self::assertStringContainsString('data-appeal-status="open"', $html);
        self::assertStringContainsString('Re-valider le dossier', $html);

        // « Modération » n'a pas users.view : la fiche d'un client lui est refusée.
        $this->client->request('GET', '/admin/comptes/'.$client->getId()->toRfc4122());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Un administrateur ne se gère pas ici.
        $this->client->request('GET', '/admin/comptes/'.$this->createAdminUser('autre@example.com', 'finance')->getId()->toRfc4122());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/admin/comptes/pas-un-uuid');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/comptes/01920000-0000-7000-8000-00000000ffff');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // « Support » voit les clients mais pas les fournisseurs.
        $this->newBrowser();
        $this->signInAdmin($this->createAdminUser('sup@example.com', 'support'));
        $this->client->request('GET', '/admin/comptes/'.$client->getId()->toRfc4122());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/comptes/'.$id);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testDashboardFlagsSuppliersWithAnOpenAppeal(): void
    {
        $supplier = $this->supplierAccount('marie@boutique.mg', 'rejected');
        $this->openAppealFor($supplier);

        $this->signInAdmin($this->createAdminUser('mod@example.com', 'moderation'));
        $this->client->request('GET', '/admin?tab=vendor_applications');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Contestation en attente', $html);
        self::assertStringContainsString('/admin/comptes/'.$supplier->getId()->toRfc4122(), $html);
        self::assertStringContainsString('Re-valider', $html);
    }
}
