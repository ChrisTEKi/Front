<?php

namespace App\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\AdminProfile;
use App\Entity\Order;
use App\Entity\SupplierProfile;
use App\Entity\User;
use App\Enum\AccountType;
use App\Repository\AdminProfileRepository;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\SupplierProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Exports CSV des clients, fournisseurs, administrateurs et commandes.
 *
 * Format pensé pour Excel / LibreOffice en français : séparateur « ; », encodage UTF-8 avec BOM (les accents s'affichent
 * correctement à l'ouverture), dates en heure de Madagascar.
 *
 * SÉCURITÉ :
 *   - le DROIT est contrôlé par le contrôleur (voir ExportType::permission) ; ici : quota (20 actions sensibles / 10 min
 *     par administrateur) + trace dans le journal d'audit (qui a exporté quoi, combien de lignes, depuis quelle IP) ;
 *   - protection contre l'« injection de formule CSV » : une cellule texte qui commence par = + - @ (ou tabulation /
 *     retour chariot) serait exécutée comme une formule par Excel. Un client pourrait saisir « =HYPERLINK(...) » comme nom.
 *     Ces cellules sont donc préfixées d'une apostrophe, qui force l'affichage en texte ;
 *   - les téléphones sont écrits en chiffres (« 034 12 345 67 » ou « 0033… ») plutôt qu'en « +… » : lisibles ET sans risque ;
 *   - lecture « en flux » : même avec 1 million de lignes, la mémoire du serveur ne gonfle pas ;
 *   - aucun mot de passe, jeton ni code n'est jamais exporté (ces données ne sont pas lues).
 */
final class CsvExporter
{
    private const TIMEZONE = 'Indian/Antananarivo';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SupplierProfileRepository $suppliers,
        private readonly AdminProfileRepository $admins,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $adminSensitiveLimiter,
    ) {
    }

    /**
     * @throws AdminActionException « too_many_exports » si le quota est dépassé
     */
    public function export(ExportType $type, User $actor): StreamedResponse
    {
        $limit = $this->adminSensitiveLimiter->create($actor->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            $wait = max(60, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new AdminActionException('too_many_exports', ['%minutes%' => (int) ceil($wait / 60)]);
        }

        $rows = $this->count($type);
        // La trace est écrite AVANT le téléchargement : même si la connexion est coupée en route, l'export est journalisé.
        $this->audit->record(AuditAction::EXPORT, $actor, 'export', $type->value, ['rows' => $rows]);

        $filename = \sprintf('gobuy_%s_%s.csv', $type->value, $this->clock->now()->format('Y-m-d'));
        $response = new StreamedResponse(function () use ($type): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 : Excel reconnaît les accents
            foreach ($this->lines($type) as $line) {
                // 5e argument '' : pas de caractère d'échappement (comportement RFC 4180, compatible PHP 8.3 et 8.4).
                fputcsv($out, array_map([$this, 'safe'], $line), ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        // Données personnelles : ni cache navigateur, ni cache intermédiaire.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setStatusCode(Response::HTTP_OK);

        return $response;
    }

    /**
     * Les lignes du fichier (en-têtes d'abord), sous forme de tableaux de valeurs.
     *
     * @return \Generator<int, list<string|int|null>>
     */
    public function lines(ExportType $type): \Generator
    {
        return match ($type) {
            ExportType::Clients => $this->clientLines(),
            ExportType::Suppliers => $this->supplierLines(),
            ExportType::Admins => $this->adminLines(),
            ExportType::Orders => $this->orderLines(),
        };
    }

    /** @return \Generator<int, list<string|int|null>> */
    private function clientLines(): \Generator
    {
        yield ['ID', 'Nom complet', 'E-mail', 'E-mail vérifié', 'Téléphone', 'Téléphone vérifié', 'Double authentification', 'Langue', 'Statut', 'Inscrit le', 'Dernière connexion'];
        foreach ($this->users->iterateByType(AccountType::Client) as $user) {
            \assert($user instanceof User);
            yield [
                $user->getId()->toRfc4122(),
                $user->getFullName(),
                $user->getEmail(),
                $this->yesNo($user->isEmailVerified()),
                $this->phone($user->getPhone()),
                $this->yesNo($user->isPhoneVerified()),
                $this->twoFactor($user),
                $user->getLocale(),
                $user->getStatus()->value,
                $this->date($user->getCreatedAt()),
                $this->date($user->getLastLoginAt()),
            ];
            $this->em->detach($user);
        }
    }

    /** @return \Generator<int, list<string|int|null>> */
    private function supplierLines(): \Generator
    {
        $sales = $this->orderItems->salesTotalsBySupplier();
        yield ['ID', 'Raison sociale', 'Gérant', 'E-mail', 'E-mail vérifié', 'Téléphone', 'Téléphone vérifié', 'Adresse', 'Statut d\'approbation', 'Dossier déposé le', 'Examiné le', 'Examiné par', 'Motif', 'Ventes cumulées (Ar)'];
        foreach ($this->suppliers->iterateAll() as $profile) {
            \assert($profile instanceof SupplierProfile);
            $user = $profile->getUser();
            yield [
                $user->getId()->toRfc4122(),
                $profile->getCompanyName(),
                $user->getFullName(),
                $user->getEmail(),
                $this->yesNo($user->isEmailVerified()),
                $this->phone($user->getPhone()),
                $this->yesNo($user->isPhoneVerified()),
                $profile->getCompanyAddress(),
                $profile->getStatus()->value,
                $this->date($profile->getSubmittedAt()),
                $this->date($profile->getReviewedAt()),
                $profile->getReviewedBy()?->getEmail(),
                $profile->getRejectionReason(),
                $sales[$user->getId()->toRfc4122()] ?? 0,
            ];
            $this->em->detach($profile);
        }
    }

    /** @return \Generator<int, list<string|int|null>> */
    private function adminLines(): \Generator
    {
        yield ['ID', 'Nom complet', 'E-mail', 'Profil', 'Droits effectifs', 'Droits ajoutés', 'Droits retirés', 'Double authentification', 'Statut', 'Mot de passe défini', 'Créé par', 'Créé le', 'Dernière connexion'];
        foreach ($this->admins->findAllWithDetails() as $profile) {
            \assert($profile instanceof AdminProfile);
            $user = $profile->getUser();
            yield [
                $user->getId()->toRfc4122(),
                $user->getFullName(),
                $user->getEmail(),
                $profile->getRole()->getLabel(),
                implode(', ', $profile->effectivePermissions()),
                implode(', ', $profile->getExtraPermissions()),
                implode(', ', $profile->getDeniedPermissions()),
                'e-mail (obligatoire)',
                $user->getStatus()->value,
                $this->yesNo($user->hasPassword()),
                $profile->getCreatedBy()?->getEmail(),
                $this->date($profile->getCreatedAt()),
                $this->date($user->getLastLoginAt()),
            ];
        }
    }

    /** @return \Generator<int, list<string|int|null>> */
    private function orderLines(): \Generator
    {
        yield ['N° commande', 'Date', 'Client', 'E-mail client', 'Téléphone client', 'Articles', 'Sous-total (Ar)', 'Livraison (Ar)', 'Total (Ar)', 'Moyen de paiement', 'Échéances', 'Opérateur Mobile Money', 'Numéro Mobile Money', 'Statut du paiement', 'Statut de la commande', 'Ville de livraison'];
        foreach ($this->orders->iterateAll() as $order) {
            \assert($order instanceof Order);
            yield [
                $order->getOrderNumber(),
                $this->date($order->getCreatedAt()),
                $order->getCustomerName(),
                $order->getCustomerEmail(),
                $this->phone($order->getCustomerPhone()),
                array_sum(array_map(static fn ($i): int => $i->getQuantity(), $order->getItems()->toArray())),
                $order->getSubtotal(),
                $order->getDeliveryFee(),
                $order->getTotal(),
                $order->getPaymentMethod()->value,
                $order->getInstallmentsCount(),
                $order->getPaymentCarrier()?->value,
                $this->phone($order->getPaymentMsisdn()),
                $order->getPaymentStatus()->value,
                $order->getStatus()->value,
                $order->getShippingAddress()['city'] ?? null,
            ];
            $this->em->detach($order);
        }
    }

    private function count(ExportType $type): int
    {
        return match ($type) {
            ExportType::Clients => $this->users->countByType(AccountType::Client),
            ExportType::Suppliers => $this->suppliers->count([]),
            ExportType::Admins => $this->admins->count([]),
            ExportType::Orders => $this->orders->countAll(),
        };
    }

    // ------------------------------------------------------------------ Formats

    /**
     * Neutralise l'injection de formule : une cellule texte commençant par = + - @ tabulation ou retour chariot
     * est préfixée d'une apostrophe. Les nombres (int) passent tels quels.
     */
    public function safe(mixed $value): string|int
    {
        if (\is_int($value)) {
            return $value;
        }
        $text = str_replace("\0", '', (string) ($value ?? ''));
        if ('' !== $text && 1 === preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'oui' : 'non';
    }

    private function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    /** « +261341234567 » → « 034 12 345 67 » ; étranger « +33612345678 » → « 0033612345678 » (jamais de « + » en tête). */
    private function phone(?string $e164): ?string
    {
        if (null === $e164 || '' === $e164) {
            return null;
        }
        if (str_starts_with($e164, '+261') && 13 === \strlen($e164)) {
            $national = '0'.substr($e164, 4);

            return substr($national, 0, 3).' '.substr($national, 3, 2).' '.substr($national, 5, 3).' '.substr($national, 8, 2);
        }

        return str_starts_with($e164, '+') ? '00'.substr($e164, 1) : $e164;
    }

    private function twoFactor(User $user): string
    {
        return match (true) {
            $user->isTwoFactorEmailEnabled() && $user->isTwoFactorSmsEnabled() => 'e-mail + SMS',
            $user->isTwoFactorEmailEnabled() => 'e-mail',
            $user->isTwoFactorSmsEnabled() => 'SMS',
            default => 'non',
        };
    }
}
