<?php

namespace App\Controller\Supplier;

use App\Dto\SupplierRegistration;
use App\Form\SupplierRegistrationType;
use App\Service\SupplierRegistrar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

final class SupplierRegistrationController extends AbstractController
{
    #[Route('/fournisseur/inscription', name: 'app_supplier_register', methods: ['GET', 'POST'])]
    public function register(Request $request, SupplierRegistrar $registrar, Security $security): Response
    {
        if (null !== $this->getUser()) {
            if ($this->isGranted('ROLE_SUPPLIER')) {
                return $this->redirectToRoute('app_supplier_dashboard');
            }
            $this->addFlash('warning', t('Déconnectez-vous de votre compte client pour créer un compte partenaire.'));

            return $this->redirectToRoute('app_account');
        }

        $registration = new SupplierRegistration();
        // "Reconstruisons Ensemble" call-to-action on the landing page pre-selects the damage question.
        $registration->hasDamage = $request->query->getBoolean('solidarite');
        $form = $this->createForm(SupplierRegistrationType::class, $registration);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $supplier = $registrar->register($form->getData());
            // Log the new supplier in so they can follow their application right away.
            $security->login($supplier->getUser(), 'form_login', 'main');
            $this->addFlash('success', t('Candidature envoyée ! Nous examinons votre dossier sous 48h ouvrées.'));

            return $this->redirectToRoute('app_supplier_dashboard', status: 303);
        }

        return $this->render('supplier/register.html.twig', ['form' => $form]);
    }
}
