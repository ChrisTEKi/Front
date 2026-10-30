<?php

namespace App\Controller;

use App\Entity\User;
use App\Account\AddressBook;
use App\Account\AddressPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages « Panier » et « Validation de la commande ».
 *
 * Le panier est mémorisé dans le navigateur (assets/cart.js) : les visiteurs peuvent donc le remplir sans compte. Ces pages
 * n'affichent que la structure. À la commande, le navigateur envoie seulement { référence, quantité } à /commande/valider et
 * le serveur recalcule les prix depuis le catalogue (OrderService).
 */
final class CartController extends AbstractController
{
    public function __construct(private readonly AddressBook $addresses, private readonly AddressPresenter $presenter)
    {
    }

    #[Route('/panier', name: 'app_cart', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('cart/index.html.twig');
    }

    /**
     * Réservée aux clients connectés (règle d'accès /commande) : un visiteur est renvoyé vers /connexion?next=/commande.
     * On préremplit l'adresse PAR DÉFAUT du carnet d'adresses (voir AddressBook) ; la personne peut en choisir une autre.
     */
    #[Route('/commande', name: 'app_checkout', methods: ['GET'])]
    public function checkout(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(); // inatteignable : la règle d'accès exige un client connecté
        }

        $response = $this->render('checkout/index.html.twig', [
            'user' => $user,
            'phoneVerified' => $user->isPhoneVerified(),
            // Carnet d'adresses : celle par défaut est préremplie, le client peut en choisir une autre ou en saisir une nouvelle.
            'addresses' => $this->presenter->list($this->addresses->list($user)),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
