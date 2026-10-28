<?php

namespace App\Controller\Supplier;

use App\Entity\Supplier;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;

use function Symfony\Component\Translation\t;

abstract class AbstractSupplierController extends AbstractController
{
    protected function supplier(): Supplier
    {
        $user = $this->getUser();
        $supplier = $user instanceof User ? $user->getSupplier() : null;

        return $supplier ?? throw $this->createAccessDeniedException('Aucun profil fournisseur associé à ce compte.');
    }

    /** Returns a redirect (with an explanatory flash) when the profile is not validated yet. */
    protected function redirectUnlessApproved(): ?RedirectResponse
    {
        if ($this->supplier()->canSell()) {
            return null;
        }

        $this->addFlash('warning', $this->supplier()->isPending()
            ? t('Votre dossier est en cours de validation : vous pourrez publier vos produits dès son approbation.')
            : t('Votre profil n\'est pas actif : vous ne pouvez pas gérer de produits pour le moment.'));

        return $this->redirectToRoute('app_supplier_dashboard');
    }
}
