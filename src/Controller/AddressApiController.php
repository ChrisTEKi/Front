<?php

namespace App\Controller;

use App\Account\AccountActionException;
use App\Account\AddressBook;
use App\Account\AddressData;
use App\Account\AddressPresenter;
use App\Entity\User;
use App\Http\AccountResponder;
use App\Http\ReadsJsonPayload;
use App\Http\ValidationErrors;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API JSON du CARNET D'ADRESSES de livraison (page « Mon compte › Mes adresses » et page de commande).
 *
 * Réservée aux CLIENTS connectés ; jeton CSRF exigé pour toute modification. On n'agit que sur les adresses du compte connecté :
 * l'identifiant d'une adresse d'un autre compte donne « introuvable » (404), exactement comme un identifiant inexistant.
 *
 *   GET  /compte/api/adresses                    → liste (adresse par défaut en premier)
 *   POST /compte/api/adresses                    {label, fullName, phone, street, city, postalCode, instructions, makeDefault} → ajoute
 *   POST /compte/api/adresses/{id}/modifier      mêmes champs                                                                   → modifie
 *   POST /compte/api/adresses/{id}/defaut        {}                                                                             → adresse par défaut
 *   POST /compte/api/adresses/{id}/supprimer     {}                                                                             → supprime
 *
 * Chaque réponse contient la liste à jour : { "ok": true, "addresses": [ {id, label, fullName, …, isDefault}, … ] }
 */
#[Route('/compte/api/adresses', name: 'account_api_addresses_', defaults: ['_csrf_token_id' => 'auth'])]
final class AddressApiController extends AbstractController
{
    use ReadsJsonPayload;

    private const ID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly AddressBook $book,
        private readonly AddressPresenter $presenter,
        private readonly AccountResponder $responder,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->responder->run(fn (): JsonResponse => $this->listing($this->client()));
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $data = AddressData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($data): JsonResponse {
            $user = $this->client();
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->book->add($user, $data);

            return $this->listing($user, Response::HTTP_CREATED);
        });
    }

    #[Route('/{id}/modifier', name: 'update', methods: ['POST'], requirements: ['id' => self::ID])]
    public function update(Request $request, string $id): JsonResponse
    {
        $data = AddressData::fromArray($this->payload($request));

        return $this->responder->run(function () use ($id, $data): JsonResponse {
            $user = $this->client();
            if (null !== $invalid = $this->invalid($data)) {
                return $invalid;
            }
            $this->book->update($user, $id, $data);

            return $this->listing($user);
        });
    }

    #[Route('/{id}/defaut', name: 'default', methods: ['POST'], requirements: ['id' => self::ID])]
    public function makeDefault(string $id): JsonResponse
    {
        return $this->responder->run(function () use ($id): JsonResponse {
            $user = $this->client();
            $this->book->setDefault($user, $id);

            return $this->listing($user);
        });
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => self::ID])]
    public function delete(string $id): JsonResponse
    {
        return $this->responder->run(function () use ($id): JsonResponse {
            $user = $this->client();
            $this->book->delete($user, $id);

            return $this->listing($user);
        });
    }

    // ------------------------------------------------------------------ Aides

    private function listing(User $user, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->responder->ok(['addresses' => $this->presenter->list($this->book->list($user))], $status);
    }

    /** Réponse 422 avec les erreurs champ par champ, ou null si les données sont valides. */
    private function invalid(AddressData $data): ?JsonResponse
    {
        $violations = $this->validator->validate($data);
        if (0 === \count($violations)) {
            return null;
        }

        return $this->responder->noStore(new JsonResponse([
            'ok' => false,
            'error' => 'validation',
            'message' => $this->translator->trans('error.validation', [], 'auth'),
            'errors' => ValidationErrors::toArray($violations),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Seuls les clients ont un carnet d'adresses (un fournisseur ou un administrateur ne commande pas). */
    private function client(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$user->isClient()) {
            throw new AccountActionException('addresses_not_available');
        }

        return $user;
    }
}
