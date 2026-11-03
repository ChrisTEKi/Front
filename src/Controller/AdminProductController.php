<?php

namespace App\Controller;

use App\Admin\StepUpService;
use App\Catalog\CatalogException;
use App\Catalog\ProductData;
use App\Catalog\ProductModerationService;
use App\Catalog\ProductPresenter;
use App\Catalog\ProductService;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\Currency;
use App\Enum\Permission;
use App\Http\CatalogResponder;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\CategoryProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration du CATALOGUE : tous les produits des fournisseurs ET ceux de GoBuy (page + API JSON).
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis un DROIT FIN par action.
 * Le Super Admin les a tous ; il peut en donner à d'autres administrateurs (profils « PDG », « DG »…). Les actions
 * irréversibles (suppression définitive) exigent en plus le mot de passe de l'administrateur (StepUpService).
 *
 *   GET  /admin/produits                              products.view     liste filtrée (propriétaire, état, signalés, mis en avant, recherche)
 *   POST /admin/api/produits                          products.manage   crée un produit GoBuy
 *   POST /admin/api/produits/{id}/modifier            products.manage   modifie un produit GoBuy (jamais celui d'un fournisseur)
 *   POST /admin/api/produits/{id}/retrait             products.remove   {action: remove|restore, reason}
 *   POST /admin/api/produits/{id}/signalement         products.flag     {action: flag|unflag, reason}
 *   POST /admin/api/produits/{id}/mise-en-avant       products.feature  {action: feature|unfeature, days}
 *   POST /admin/api/produits/{id}/supprimer           products.delete   {password}
 *   POST /admin/api/fournisseurs/{id}/catalogue       products.remove | products.delete   {action: remove|delete, reason, password}
 */
final class AdminProductController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F-]{36}';
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly ProductService $service,
        private readonly ProductModerationService $moderation,
        private readonly ProductPresenter $presenter,
        private readonly CategoryProvider $categories,
        private readonly CatalogResponder $responder,
        private readonly StepUpService $stepUp,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    // ------------------------------------------------------------------ Page

    #[Route('/admin/produits', name: 'app_admin_products', methods: ['GET'])]
    #[IsGranted('products.view')]
    public function page(Request $request): Response
    {
        $filters = [
            'owner' => (string) $request->query->get('owner', 'all'),
            'status' => (string) $request->query->get('status', ''),
            'flagged' => $request->query->getBoolean('flagged'),
            'featured' => $request->query->getBoolean('featured'),
            'q' => mb_substr((string) $request->query->get('q', ''), 0, 100),
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $now = $this->clock->now();

        $total = $this->products->countSearch($filters, $now);
        $found = $this->products->search($filters, $page, self::PER_PAGE, $now);
        $names = $this->presenter->companyNames($found);

        // Si la liste est limitée à UN fournisseur, on affiche son nom (et on propose les actions sur tout son catalogue).
        $supplier = Uuid::isValid($filters['owner']) ? $this->users->findOneByUuid($filters['owner']) : null;

        $can = [];
        foreach (Permission::cases() as $permission) {
            $can[$permission->value] = $this->isGranted($permission->value);
        }

        $response = $this->render('admin/products.html.twig', [
            'rows' => array_map(fn (Product $p): array => $this->presenter->adminRow($p, $names), $found),
            'filters' => $filters,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'supplier' => null !== $supplier && $supplier->isSupplier() ? ['id' => $supplier->getId()->toRfc4122(), 'name' => $supplier->getFullName(), 'email' => $supplier->getEmail()] : null,
            'can' => $can,
            'categories' => $this->categories->all(),
            'currencies' => array_filter(Currency::cases(), static fn (Currency $c): bool => Currency::BASE !== $c),
            'featureDays' => [7, 30, 90, 365],
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    // ------------------------------------------------------------------ Produits de GoBuy

    #[Route('/admin/api/produits', name: 'admin_api_product_add', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.manage')]
    public function add(Request $request): JsonResponse
    {
        $data = ProductData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($data): JsonResponse {
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->service->create(null, $data, $this->admin());

            return $this->responder->ok([], Response::HTTP_CREATED);
        });
    }

    #[Route('/admin/api/produits/{id}/modifier', name: 'admin_api_product_update', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.manage')]
    public function update(Request $request, string $id): JsonResponse
    {
        $data = ProductData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $product = $this->product($id);
            // On ne réécrit pas la fiche d'un fournisseur : l'administrateur la retire, la signale ou la supprime, sans la modifier.
            if (!$product->isGobuyOwned()) {
                throw new CatalogException('gobuy_only');
            }
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->service->update($product, $data, $this->admin());

            return $this->responder->ok();
        });
    }

    // ------------------------------------------------------------------ Modération d'un produit

    #[Route('/admin/api/produits/{id}/retrait', name: 'admin_api_product_removal', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.remove')]
    public function removal(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $product = $this->product($id);
            match ($this->text($data, 'action')) {
                'remove' => $this->moderation->remove($this->admin(), $product, $this->text($data, 'reason')),
                'restore' => $this->moderation->restore($this->admin(), $product),
                default => throw new CatalogException('invalid_action'),
            };

            return $this->responder->ok(['status' => $product->getStatus()->value]);
        });
    }

    #[Route('/admin/api/produits/{id}/signalement', name: 'admin_api_product_flag', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.flag')]
    public function flag(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $product = $this->product($id);
            match ($this->text($data, 'action')) {
                'flag' => $this->moderation->flag($this->admin(), $product, $this->text($data, 'reason')),
                'unflag' => $this->moderation->unflag($this->admin(), $product),
                default => throw new CatalogException('invalid_action'),
            };

            return $this->responder->ok(['flagged' => $product->isFlagged()]);
        });
    }

    #[Route('/admin/api/produits/{id}/mise-en-avant', name: 'admin_api_product_feature', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.feature')]
    public function feature(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $product = $this->product($id);
            match ($this->text($data, 'action')) {
                // days absent ou vide = « jusqu'à décision contraire » ; sinon un nombre de jours entier.
                'feature' => $this->moderation->feature($this->admin(), $product, $this->days($data)),
                'unfeature' => $this->moderation->unfeature($this->admin(), $product),
                default => throw new CatalogException('invalid_action'),
            };

            return $this->responder->ok(['featured' => $product->isFeatured($this->clock->now())]);
        });
    }

    #[Route('/admin/api/produits/{id}/supprimer', name: 'admin_api_product_delete', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('products.delete')]
    public function delete(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $admin = $this->admin();
            $this->stepUp->require($admin, (string) ($data['password'] ?? ''));
            $this->moderation->delete($admin, $this->product($id));

            return $this->responder->ok();
        });
    }

    // ------------------------------------------------------------------ Catalogue entier d'un fournisseur

    #[Route('/admin/api/fournisseurs/{id}/catalogue', name: 'admin_api_supplier_catalog', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    public function catalog(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);
        $action = $this->text($data, 'action');
        // Le droit dépend de l'action : retirer (réversible) ou supprimer (définitif).
        $this->denyAccessUnlessGranted('delete' === $action ? Permission::ProductsDelete->value : Permission::ProductsRemove->value);

        return $this->responder->run(function () use ($id, $data, $action): JsonResponse {
            $admin = $this->admin();
            $supplier = $this->users->findOneByUuid($id);
            if (null === $supplier || !$supplier->isSupplier()) {
                throw new CatalogException('not_found');
            }
            $this->stepUp->require($admin, (string) ($data['password'] ?? ''));

            $count = match ($action) {
                'remove' => $this->moderation->removeCatalog($admin, $supplier, $this->text($data, 'reason')),
                'delete' => $this->moderation->deleteCatalog($admin, $supplier),
                default => throw new CatalogException('invalid_action'),
            };

            return $this->responder->ok(['count' => $count]);
        });
    }

    // ------------------------------------------------------------------ Outils

    /** @throws CatalogException */
    private function product(string $id): Product
    {
        return $this->products->findOneByUuid($id) ?? throw new CatalogException('not_found');
    }

    private function invalid(ProductData $data): ?JsonResponse
    {
        $violations = $this->validator->validate($data);
        if (0 === \count($violations)) {
            return null;
        }

        return $this->responder->invalid(ValidationErrors::toArray($violations), $this->translator->trans('error.validation', [], 'catalog'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws CatalogException « invalid_days » si la valeur n'est pas un entier
     */
    private function days(array $data): ?int
    {
        $raw = $data['days'] ?? null;
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_int($raw) && !(\is_string($raw) && ctype_digit($raw))) {
            throw new CatalogException('invalid_days', ['%max%' => ProductModerationService::MAX_FEATURE_DAYS]);
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
