<?php

namespace App\Controller;

use App\Catalog\CatalogException;
use App\Catalog\ProductData;
use App\Catalog\ProductPresenter;
use App\Catalog\ProductService;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\Currency;
use App\Http\CatalogResponder;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use App\Repository\ProductRepository;
use App\Repository\SupplierProfileRepository;
use App\Service\CategoryProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Mes produits » : le fournisseur gère SON catalogue (page + API JSON). Réservé aux comptes fournisseur
 * (règle d'accès `^/fournisseur` dans security.yaml) ; jeton CSRF exigé pour toute modification.
 *
 *   GET  /fournisseur/produits                       page
 *   POST /fournisseur/api/produits                   {name, description, category, reference, priceMga, prices:{EUR,USD}} → crée
 *   POST /fournisseur/api/produits/{id}/modifier     mêmes champs                                                         → modifie
 *   POST /fournisseur/api/produits/{id}/visibilite   {visible: true|false}                                                → masque / réaffiche
 *   POST /fournisseur/api/produits/{id}/supprimer    {}                                                                   → supprime
 *
 * On n'agit que sur SES produits : l'identifiant d'un produit d'un autre compte donne « introuvable » (404), comme un
 * identifiant inexistant.
 */
final class SupplierProductController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly SupplierProfileRepository $profiles,
        private readonly CategoryProvider $categories,
        private readonly ProductService $service,
        private readonly ProductPresenter $presenter,
        private readonly CatalogResponder $responder,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/fournisseur/produits', name: 'app_supplier_products', methods: ['GET'])]
    public function page(#[CurrentUser] User $user): Response
    {
        $response = $this->render('supplier/products.html.twig', [
            'products' => array_map($this->presenter->supplierRow(...), $this->products->findByOwner($user)),
            'currencies' => array_filter(Currency::cases(), static fn (Currency $c): bool => Currency::BASE !== $c),
            'limit' => ProductService::MAX_PER_SUPPLIER,
            'categories' => $this->categories->all(),
            'status' => $this->profiles->findOneByUser($user)?->getStatus()->value ?? 'pending',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/fournisseur/api/produits', name: 'supplier_api_product_add', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    public function add(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $data = ProductData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($user, $data): JsonResponse {
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->service->create($user, $data, $user);

            return $this->listing($user, Response::HTTP_CREATED);
        });
    }

    #[Route('/fournisseur/api/produits/{id}/modifier', name: 'supplier_api_product_update', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    public function update(#[CurrentUser] User $user, Request $request, string $id): JsonResponse
    {
        $data = ProductData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($user, $id, $data): JsonResponse {
            $product = $this->owned($user, $id);
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->service->update($product, $data, $user);

            return $this->listing($user);
        });
    }

    #[Route('/fournisseur/api/produits/{id}/visibilite', name: 'supplier_api_product_visibility', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    public function visibility(#[CurrentUser] User $user, Request $request, string $id): JsonResponse
    {
        $visible = true === ($this->payload($request)['visible'] ?? null);

        return $this->responder->run(function () use ($user, $id, $visible): JsonResponse {
            $this->service->setVisible($this->owned($user, $id), $visible, $user);

            return $this->listing($user);
        });
    }

    #[Route('/fournisseur/api/produits/{id}/supprimer', name: 'supplier_api_product_delete', methods: ['POST'], requirements: ['id' => self::ID], defaults: ['_csrf_token_id' => 'auth'])]
    public function delete(#[CurrentUser] User $user, string $id): JsonResponse
    {
        return $this->responder->run(function () use ($user, $id): JsonResponse {
            $this->service->delete($this->owned($user, $id), $user);

            return $this->listing($user);
        });
    }

    /** @throws CatalogException */
    private function owned(User $user, string $id): Product
    {
        return $this->products->findOwnedBy($user, $id) ?? throw new CatalogException('not_found');
    }

    private function invalid(ProductData $data): ?JsonResponse
    {
        $violations = $this->validator->validate($data);
        if (0 === \count($violations)) {
            return null;
        }

        return $this->responder->invalid(ValidationErrors::toArray($violations), $this->translator->trans('error.validation', [], 'catalog'));
    }

    private function listing(User $user, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->responder->ok(['products' => array_map($this->presenter->supplierRow(...), $this->products->findByOwner($user))], $status);
    }
}
