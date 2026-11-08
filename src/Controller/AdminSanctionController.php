<?php

namespace App\Controller;

use App\Admin\StepUpService;
use App\Entity\User;
use App\Enum\BlacklistKind;
use App\Enum\DeletionDelay;
use App\Enum\Permission;
use App\Http\ModerationResponder;
use App\Http\ReadsJsonPayload;
use App\Moderation\AccountDeletionService;
use App\Moderation\BanOptions;
use App\Moderation\BlacklistService;
use App\Moderation\ModerationException;
use App\Moderation\RefundService;
use App\Moderation\SanctionService;
use App\Repository\BlacklistEntryRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SANCTIONS sur les comptes clients et fournisseurs (bannissement, gel, remboursements, suppression définitive) et LISTE NOIRE.
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis un DROIT FIN par action. Chaque
 * action redemande le mot de passe de l'administrateur (StepUpService). Un administrateur ne peut JAMAIS être visé ici.
 *
 *   POST /admin/api/comptes/{id}/bannissement   sanctions.ban (+ droits des options)  {action: ban|unban, reason, catalog, refund, blacklist[], blacklistDays, password}
 *                                               « unban » est réservé au Super Admin
 *   POST /admin/api/comptes/{id}/gel            sanctions.freeze                       {action: freeze|unfreeze, reason, password}
 *   POST /admin/api/comptes/{id}/remboursements sanctions.refund                       {scope: open|all, reason, password}   (fournisseur)
 *   POST /admin/api/comptes/{id}/suppression   accounts.delete                       {action: schedule|cancel, delay, reason, password}
 *                                               suppression immédiate réussie : la réponse porte « redirect » (la fiche n'existe plus)
 *                                               délais : immediate, 24h, 48h, 72h, 7d, 14d, 30d, 3m, 6m, 1y (voir DeletionDelay)
 *   GET  /admin/liste-noire                     blacklist.manage
 *   POST /admin/api/liste-noire                 blacklist.manage                       {kind, value, reason, days, password}
 *   POST /admin/api/liste-noire/{id}/supprimer  blacklist.manage                       {password}
 *
 * Les options d'un bannissement exigent chacune leur droit : retirer le catalogue (products.remove), le supprimer
 * (products.delete), rembourser (sanctions.refund), inscrire sur la liste noire (blacklist.manage). Un administrateur qui n'a
 * que « sanctions.ban » peut donc bannir, mais pas aller plus loin que ce que ses droits permettent.
 */
final class AdminSanctionController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly UserRepository $users,
        private readonly BlacklistEntryRepository $entries,
        private readonly SanctionService $sanctions,
        private readonly RefundService $refunds,
        private readonly AccountDeletionService $deletions,
        private readonly BlacklistService $blacklist,
        private readonly ModerationResponder $responder,
        private readonly StepUpService $stepUp,
    ) {
    }

    // ------------------------------------------------------------------ Comptes

    #[Route('/admin/api/comptes/{id}/bannissement', name: 'admin_api_account_ban', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('sanctions.ban')]
    public function ban(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $target = $this->target($id);
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            if ('unban' === $this->text($data, 'action')) {
                $this->sanctions->unban($admin, $target, $this->text($data, 'reason'));

                return $this->responder->ok(['status' => $target->getStatus()->value]);
            }
            if ('ban' !== $this->text($data, 'action')) {
                throw new ModerationException('invalid_action');
            }

            $options = new BanOptions(
                '' === $this->text($data, 'catalog') ? 'none' : $this->text($data, 'catalog'),
                '' === $this->text($data, 'refund') ? 'none' : $this->text($data, 'refund'),
                $this->kinds($data['blacklist'] ?? []),
                $this->days($data['blacklistDays'] ?? null),
            );
            $options->validate();
            // Chaque mesure d'accompagnement a son propre droit.
            $needed = array_filter([
                'remove' === $options->catalog ? Permission::ProductsRemove : null,
                'delete' === $options->catalog ? Permission::ProductsDelete : null,
                'none' !== $options->refund ? Permission::SanctionsRefund : null,
                [] !== $options->blacklist ? Permission::BlacklistManage : null,
            ]);
            foreach ($needed as $permission) {
                if (!$this->isGranted($permission->value)) {
                    throw new ModerationException('forbidden');
                }
            }

            $report = $this->sanctions->ban($admin, $target, $this->text($data, 'reason'), $options);

            return $this->responder->ok(['status' => $target->getStatus()->value] + $report);
        });
    }

    #[Route('/admin/api/comptes/{id}/gel', name: 'admin_api_account_freeze', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('sanctions.freeze')]
    public function freeze(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $target = $this->target($id);
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            match ($this->text($data, 'action')) {
                'freeze' => $this->sanctions->freeze($admin, $target, $this->text($data, 'reason')),
                'unfreeze' => $this->sanctions->unfreeze($admin, $target),
                default => throw new ModerationException('invalid_action'),
            };

            return $this->responder->ok(['frozen' => $target->isFundsFrozen()]);
        });
    }

    #[Route('/admin/api/comptes/{id}/remboursements', name: 'admin_api_account_refund', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('sanctions.refund')]
    public function refund(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $target = $this->target($id);
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            $report = $this->refunds->refundSupplierBuyers($admin, $target, $this->text($data, 'scope'), $this->text($data, 'reason'));

            return $this->responder->ok(['refund' => $report->toArray()]);
        });
    }

    #[Route('/admin/api/comptes/{id}/suppression', name: 'admin_api_account_deletion', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('accounts.delete')]
    public function deletion(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $target = $this->target($id);
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            switch ($this->text($data, 'action')) {
                case 'schedule':
                    $delay = DeletionDelay::tryFrom($this->text($data, 'delay')) ?? throw new ModerationException('invalid_delay');
                    $tab = $target->isSupplier() ? 'suppliers_approval' : 'users_table';
                    $result = $this->deletions->schedule($admin, $target, $delay, $this->text($data, 'reason'));
                    $body = ['purged' => $result['purged'], 'scheduledAt' => $result['scheduledAt']->format(\DATE_ATOM)];
                    // Suppression IMMÉDIATE : la fiche du compte n'existe plus, la recharger donnerait une erreur 404. On renvoie à la liste.
                    if ($result['purged']) {
                        $body['redirect'] = $this->generateUrl('app_admin_dashboard', ['tab' => $tab]);
                    }

                    return $this->responder->ok($body);
                case 'cancel':
                    $this->deletions->cancel($admin, $target);

                    return $this->responder->ok(['status' => $target->getStatus()->value]);
                default:
                    throw new ModerationException('invalid_action');
            }
        });
    }

    // ------------------------------------------------------------------ Liste noire

    #[Route('/admin/liste-noire', name: 'app_admin_blacklist', methods: ['GET'])]
    #[IsGranted('blacklist.manage')]
    public function blacklistPage(): Response
    {
        $response = $this->render('admin/blacklist.html.twig', [
            'entries' => $this->entries->findRecent(),
            'kinds' => BlacklistKind::cases(),
            'maxIpDays' => BlacklistService::MAX_IP_DAYS,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/admin/api/liste-noire', name: 'admin_api_blacklist_add', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('blacklist.manage')]
    public function blacklistAdd(Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($request): JsonResponse {
            $admin = $this->admin();
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            $kind = BlacklistKind::tryFrom($this->text($data, 'kind')) ?? throw new ModerationException('invalid_blacklist_kind');
            $entry = $this->blacklist->add($admin, $kind, $this->text($data, 'value'), $this->text($data, 'reason'), $this->days($data['days'] ?? null));

            return $this->responder->ok(['id' => $entry->getId()->toRfc4122(), 'value' => $entry->getValue()], Response::HTTP_CREATED);
        });
    }

    #[Route('/admin/api/liste-noire/{id}/supprimer', name: 'admin_api_blacklist_remove', requirements: ['id' => self::ID], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('blacklist.manage')]
    public function blacklistRemove(string $id, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($id, $request): JsonResponse {
            $admin = $this->admin();
            $this->stepUp->require($admin, $this->text($this->payload($request), 'password'));
            $entry = $this->entries->findOneByUuid($id) ?? throw new ModerationException('not_found');
            $this->blacklist->remove($admin, $entry);

            return $this->responder->ok();
        });
    }

    // ------------------------------------------------------------------ Outils

    /** Compte visé : un client ou un fournisseur. Introuvable ou administrateur : refus (jamais d'indice sur un administrateur). */
    private function target(string $id): User
    {
        $user = Uuid::isValid($id) ? $this->users->findOneByUuid($id) : null;
        if (null === $user) {
            throw new ModerationException('not_found');
        }
        if ($user->isAdmin()) {
            throw new ModerationException('cannot_target_admin');
        }

        return $user;
    }

    /**
     * @return list<BlacklistKind> natures cochées (les valeurs inconnues sont refusées, pas ignorées)
     */
    private function kinds(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $kinds = [];
        foreach ($raw as $value) {
            $kinds[] = \is_string($value) ? (BlacklistKind::tryFrom($value) ?? throw new ModerationException('invalid_blacklist_kind')) : throw new ModerationException('invalid_blacklist_kind');
        }

        return array_values(array_unique($kinds, \SORT_REGULAR));
    }

    private function days(mixed $raw): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_scalar($raw) || !preg_match('/^\d{1,4}$/', (string) $raw)) {
            throw new ModerationException('invalid_days', ['%max%' => BlacklistService::MAX_DAYS]);
        }

        return (int) $raw;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
