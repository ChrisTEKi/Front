<?php

namespace App\Controller;

use App\Admin\AdminAccountService;
use App\Admin\AdminActionException;
use App\Admin\AdminRoleService;
use App\Admin\CsvExporter;
use App\Admin\ExportType;
use App\Admin\StepUpService;
use App\Admin\SupplierReviewService;
use App\Admin\UserModerationService;
use App\Entity\AdminRole;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\Permission;
use App\Http\ReadsJsonPayload;
use App\Order\OrderRefusedException;
use App\Order\OrderService;
use App\Repository\AdminRoleRepository;
use App\Repository\OrderRepository;
use App\Repository\SupplierProfileRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Actions de l'espace administrateur (JSON, appelées par le JavaScript du tableau de bord).
 *
 * TROIS couches de protection sur chaque route :
 *   1. pare-feu (security.yaml) : `^/admin` exige ROLE_ADMIN, session valide, réseau autorisé, inactivité < 15 min ;
 *   2. DROIT FIN : #[IsGranted('suppliers.approve')] etc. (voir App\Enum\Permission) ;
 *   3. jeton CSRF (en-tête X-CSRF-Token) et, pour les actions les plus sensibles (gestion des administrateurs),
 *      CONFIRMATION DU MOT DE PASSE de l'administrateur (StepUpService).
 *
 * Chaque service re-vérifie en plus ses propres règles métier : ce contrôleur reste fin.
 */
#[Route('/admin', name: 'admin_api_', defaults: ['_csrf_token_id' => 'auth'])]
final class AdminApiController extends AbstractController
{
    use ReadsJsonPayload;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $users,
        private readonly SupplierProfileRepository $suppliers,
        private readonly OrderRepository $orders,
        private readonly AdminRoleRepository $roles,
        private readonly SupplierReviewService $supplierReview,
        private readonly UserModerationService $moderation,
        private readonly AdminAccountService $adminAccounts,
        private readonly AdminRoleService $roleService,
        private readonly OrderService $orderService,
        private readonly StepUpService $stepUp,
        private readonly CsvExporter $exporter,
    ) {
    }

    // ------------------------------------------------------------------ Fournisseurs

    /** Décision sur un dossier fournisseur : approve | reject | suspend (+ motif). */
    #[Route('/api/fournisseurs/{id}/decision', name: 'supplier_decision', methods: ['POST'])]
    #[IsGranted('suppliers.approve')]
    public function supplierDecision(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $data = $this->payload($request);
            $user = $this->users->findOneByUuid($id);
            $profile = null !== $user ? $this->suppliers->findOneByUser($user) : null;
            if (null === $profile) {
                throw new AdminActionException('not_found');
            }
            $reason = $this->text($data, 'reason');
            match ($this->text($data, 'action')) {
                'approve' => $this->supplierReview->approve($this->admin(), $profile),
                'reject' => $this->supplierReview->reject($this->admin(), $profile, $reason),
                'suspend' => $this->supplierReview->suspend($this->admin(), $profile, $reason),
                default => throw new AdminActionException('invalid_action'),
            };

            return ['status' => $profile->getStatus()->value];
        });
    }

    // ------------------------------------------------------------------ Clients et fournisseurs (comptes)

    /** Suspension / réactivation d'un client ou d'un fournisseur. */
    #[Route('/api/utilisateurs/{id}/statut', name: 'user_status', methods: ['POST'])]
    #[IsGranted('users.suspend')]
    public function userStatus(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $target = $this->users->findOneByUuid($id) ?? throw new AdminActionException('not_found');
            match ($this->text($this->payload($request), 'action')) {
                'suspend' => $this->moderation->suspend($this->admin(), $target),
                'reactivate' => $this->moderation->reactivate($this->admin(), $target),
                default => throw new AdminActionException('invalid_action'),
            };

            return ['status' => $target->getStatus()->value];
        });
    }

    // ------------------------------------------------------------------ Commandes

    /** Avancement du suivi de colis et livreur. */
    #[Route('/api/commandes/{number}/statut', name: 'order_status', methods: ['POST'])]
    #[IsGranted('orders.update_tracking')]
    public function orderStatus(string $number, Request $request): JsonResponse
    {
        $data = $this->payload($request);
        try {
            $order = $this->orders->findOneByNumber($number) ?? throw new AdminActionException('not_found');
            if ('' !== $this->text($data, 'courierName')) {
                $this->orderService->assignCourier($order, $this->text($data, 'courierName'), $this->text($data, 'courierPhone'), $this->admin());
            }
            if ('' !== $this->text($data, 'status')) {
                $status = OrderStatus::tryFrom($this->text($data, 'status')) ?? throw new AdminActionException('invalid_action');
                $this->orderService->advance($order, $status, $this->text($data, 'description'), $this->admin());
            }

            return $this->ok(['status' => $order->getStatus()->value]);
        } catch (AdminActionException $e) {
            return $this->failure($e);
        } catch (OrderRefusedException $e) {
            return $this->failureFromOrder($e);
        }
    }

    // ------------------------------------------------------------------ Administrateurs (Super Admin uniquement)

    /** Invite un nouvel administrateur. */
    #[Route('/api/administrateurs', name: 'admin_invite', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function inviteAdmin(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $data = $this->payload($request);
            $this->stepUp->require($this->admin(), (string) ($data['password'] ?? ''));

            $user = $this->adminAccounts->invite(
                $this->admin(),
                $this->text($data, 'email'),
                $this->text($data, 'fullName'),
                $this->role($this->text($data, 'roleId')),
                $this->permissions($data['extra'] ?? []),
                $this->permissions($data['denied'] ?? []),
                $this->text($data, 'locale') ?: 'fr',
            );

            return ['id' => $user->getId()->toRfc4122(), 'email' => $user->getEmail()];
        }, Response::HTTP_CREATED);
    }

    /** Change le profil et les droits d'un administrateur. */
    #[Route('/api/administrateurs/{id}/acces', name: 'admin_access', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function adminAccess(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $data = $this->payload($request);
            $this->stepUp->require($this->admin(), (string) ($data['password'] ?? ''));
            $target = $this->users->findOneByUuid($id) ?? throw new AdminActionException('not_found');

            $this->adminAccounts->updateAccess(
                $this->admin(),
                $target,
                $this->role($this->text($data, 'roleId')),
                $this->permissions($data['extra'] ?? []),
                $this->permissions($data['denied'] ?? []),
            );

            return [];
        });
    }

    /** Suspend / réactive un administrateur. */
    #[Route('/api/administrateurs/{id}/statut', name: 'admin_status', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function adminStatus(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $data = $this->payload($request);
            $this->stepUp->require($this->admin(), (string) ($data['password'] ?? ''));
            $target = $this->users->findOneByUuid($id) ?? throw new AdminActionException('not_found');

            match ($this->text($data, 'action')) {
                'suspend' => $this->adminAccounts->suspend($this->admin(), $target),
                'reactivate' => $this->adminAccounts->reactivate($this->admin(), $target),
                default => throw new AdminActionException('invalid_action'),
            };

            return ['status' => $target->getStatus()->value];
        });
    }

    /** Renvoie le lien d'activation d'un administrateur invité. */
    #[Route('/api/administrateurs/{id}/invitation', name: 'admin_resend', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function resendInvitation(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $this->stepUp->require($this->admin(), (string) ($this->payload($request)['password'] ?? ''));
            $target = $this->users->findOneByUuid($id) ?? throw new AdminActionException('not_found');
            $this->adminAccounts->resendInvitation($this->admin(), $target);

            return [];
        });
    }

    /** Réinitialise l'accès d'un administrateur activé : mot de passe supprimé + nouveau lien d'activation par e-mail. */
    #[Route('/api/administrateurs/{id}/reinitialisation', name: 'admin_reset_access', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function resetAccess(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $this->stepUp->require($this->admin(), (string) ($this->payload($request)['password'] ?? ''));
            $target = $this->users->findOneByUuid($id) ?? throw new AdminActionException('not_found');
            $this->adminAccounts->resetAccess($this->admin(), $target);

            return [];
        });
    }

    // ------------------------------------------------------------------ Profils de droits (Super Admin uniquement)

    /** Crée ou modifie un profil (Finance, Compta…). */
    #[Route('/api/profils', name: 'role_save', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function saveRole(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $data = $this->payload($request);
            $this->stepUp->require($this->admin(), (string) ($data['password'] ?? ''));
            $existing = '' !== $this->text($data, 'id') ? $this->role($this->text($data, 'id')) : null;

            $role = $this->roleService->save(
                $this->admin(),
                $existing,
                $this->text($data, 'code'),
                $this->text($data, 'label'),
                $this->text($data, 'description'),
                $this->permissions($data['permissions'] ?? []),
            );

            return ['id' => $role->getId()->toRfc4122(), 'code' => $role->getCode()];
        });
    }

    #[Route('/api/profils/{id}/supprimer', name: 'role_delete', methods: ['POST'])]
    #[IsGranted('admins.manage')]
    public function deleteRole(string $id, Request $request): JsonResponse
    {
        return $this->run(function () use ($id, $request): array {
            $this->stepUp->require($this->admin(), (string) ($this->payload($request)['password'] ?? ''));
            $this->roleService->delete($this->admin(), $this->role($id));

            return [];
        });
    }

    // ------------------------------------------------------------------ Exports CSV

    /**
     * Téléchargement d'un export CSV. Envoyé par un formulaire POST (avec jeton CSRF « admin_export ») plutôt qu'un lien :
     * un site tiers ne peut pas déclencher le téléchargement à l'insu de l'administrateur.
     */
    #[Route('/export/{type}', name: 'export', methods: ['POST'], defaults: ['_csrf_token_id' => null])]
    public function export(string $type, Request $request): Response
    {
        $exportType = ExportType::tryFrom($type) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($exportType->permission()->value);
        if (!$this->isCsrfTokenValid('admin_export', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            return $this->exporter->export($exportType, $this->admin());
        } catch (AdminActionException $e) {
            return $this->failure($e);
        }
    }

    // ------------------------------------------------------------------ Outils

    /**
     * Exécute une action et convertit résultat / exceptions en réponse JSON.
     *
     * @param \Closure(): array<string, mixed> $action
     */
    private function run(\Closure $action, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return $this->ok($action(), $status);
        } catch (AdminActionException $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->noStore(new JsonResponse(['ok' => true] + $data, $status));
    }

    private function failure(AdminActionException $e): JsonResponse
    {
        $response = new JsonResponse([
            'ok' => false,
            'error' => $e->reason,
            'message' => $this->translator->trans('error.'.$e->reason, $e->params, 'admin'),
        ], match ($e->reason) {
            // 403 : action interdite à CE compte (droit, confirmation du mot de passe, règles de protection du Super Admin).
            'forbidden', 'step_up_failed', 'cannot_modify_super_admin', 'cannot_suspend_self', 'use_admin_management' => Response::HTTP_FORBIDDEN,
            'not_found' => Response::HTTP_NOT_FOUND,
            // 429 : trop d'essais ou d'envois rapprochés.
            'step_up_locked', 'too_many_exports', 'too_soon', 'too_many_attempts' => Response::HTTP_TOO_MANY_REQUESTS,
            // 409 : l'état actuel des données ne permet pas l'action (doublon, étape déjà passée, profil utilisé…).
            'email_taken', 'code_taken', 'role_in_use', 'invalid_transition', 'super_admin_exists', 'system_role', 'already_activated', 'not_activated', 'account_not_active' => Response::HTTP_CONFLICT,
            'delivery_failed' => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        });

        return $this->noStore($response);
    }

    private function failureFromOrder(OrderRefusedException $e): JsonResponse
    {
        return $this->noStore(new JsonResponse([
            'ok' => false,
            'error' => $e->reason,
            'message' => $this->translator->trans('error.'.$e->reason, $e->params, 'orders'),
        ], \in_array($e->reason, ['order_closed', 'order_backwards'], true) ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @throws AdminActionException */
    private function role(string $id): AdminRole
    {
        $role = \Symfony\Component\Uid\Uuid::isValid($id) ? $this->roles->find($id) : null;

        return $role ?? throw new AdminActionException('not_found');
    }

    /**
     * Convertit une liste de textes en droits. Un droit inconnu est REFUSÉ (jamais ignoré en silence).
     *
     * @return list<Permission>
     *
     * @throws AdminActionException
     */
    private function permissions(mixed $values): array
    {
        if (!\is_array($values)) {
            throw new AdminActionException('invalid_permission');
        }
        $result = [];
        foreach ($values as $value) {
            $permission = \is_string($value) ? Permission::tryFrom($value) : null;
            if (null === $permission) {
                throw new AdminActionException('invalid_permission');
            }
            $result[$permission->value] = $permission;
        }

        return array_values($result);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
