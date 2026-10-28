<?php

namespace App\Controller\Supplier;

use App\Entity\Product;
use App\Enum\ProductStatus;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use App\Security\ProductVoter;
use App\Service\ProductImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

#[IsGranted('ROLE_SUPPLIER')]
#[Route('/fournisseur/produits')]
final class SupplierProductController extends AbstractSupplierController
{
    #[Route('', name: 'app_supplier_products', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        $supplier = $this->supplier();
        $status = ProductStatus::tryFrom($request->query->getString('statut'));
        $term = $request->query->getString('q');

        return $this->render('supplier/products.html.twig', [
            'supplier' => $supplier,
            'section' => 'products',
            'products' => $products->searchForSupplier($supplier, $term, $status),
            'counts' => $products->countByStatusForSupplier($supplier),
            'currentStatus' => $status,
            'term' => $term,
        ]);
    }

    #[Route('/nouveau', name: 'app_supplier_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, ProductImageUploader $uploader, TranslatorInterface $translator): Response
    {
        if (null !== $redirect = $this->redirectUnlessApproved()) {
            return $redirect;
        }

        $product = (new Product())->setSupplier($this->supplier());
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $files = $this->uploadedFiles($form);
            if (ProductStatus::Active === $product->getStatus() && [] === $files) {
                $form->get('images')->addError(new FormError($translator->trans('Ajoutez au moins une photo pour publier ce produit.')));
            } else {
                $product->setImagePaths($uploader->upload($files));
                $em->persist($product);
                $em->flush();
                $this->addFlash('success', t('« %name% » a été enregistré.', ['%name%' => $product->getName()]));

                return $this->redirectToRoute('app_supplier_products', status: 303);
            }
        }

        return $this->render('supplier/product_form.html.twig', [
            'supplier' => $this->supplier(),
            'section' => 'products',
            'form' => $form,
            'product' => $product,
            'isNew' => true,
            'maxImages' => Product::MAX_IMAGES,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_supplier_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Product $product, Request $request, EntityManagerInterface $em, ProductImageUploader $uploader, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);

        $form = $this->createForm(ProductType::class, $product, ['existing_images' => $product->getImagePaths()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $files = $this->uploadedFiles($form);
            /** @var list<string> $toRemove */
            $toRemove = array_values(array_intersect($form->get('removeImages')->getData() ?? [], $product->getImagePaths()));
            $kept = array_values(array_diff($product->getImagePaths(), $toRemove));
            $total = \count($kept) + \count($files);

            if ($total > Product::MAX_IMAGES) {
                $form->get('images')->addError(new FormError($translator->trans('Maximum %max% photos par produit (vous en avez %count%).', ['%max%' => Product::MAX_IMAGES, '%count%' => $total])));
            } elseif (ProductStatus::Active === $product->getStatus() && 0 === $total) {
                $form->get('images')->addError(new FormError($translator->trans('Ajoutez au moins une photo pour publier ce produit.')));
            } else {
                $product->setImagePaths([...$kept, ...$uploader->upload($files)])->touch();
                $em->flush();
                $uploader->removeAll($toRemove);
                $this->addFlash('success', t('« %name% » a été mis à jour.', ['%name%' => $product->getName()]));

                return $this->redirectToRoute('app_supplier_products', status: 303);
            }
        }

        return $this->render('supplier/product_form.html.twig', [
            'supplier' => $this->supplier(),
            'section' => 'products',
            'form' => $form,
            'product' => $product,
            'isNew' => false,
            'maxImages' => Product::MAX_IMAGES,
        ]);
    }

    #[Route('/{id}/statut', name: 'app_supplier_product_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeStatus(Product $product, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(ProductVoter::EDIT, $product);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $status = ProductStatus::tryFrom($request->request->getString('status'));
        if (null === $status) {
            throw $this->createNotFoundException();
        }
        if (ProductStatus::Active === $status && [] === $product->getImagePaths()) {
            $this->addFlash('error', t('Ajoutez au moins une photo avant de publier ce produit.'));

            return $this->redirectToRoute('app_supplier_product_edit', ['id' => $product->getId()], 303);
        }

        $product->setStatus($status)->touch();
        $em->flush();
        $this->addFlash('success', t('« %name% » : %status%.', ['%name%' => $product->getName(), '%status%' => t($status->label())]));

        return $this->redirectToRoute('app_supplier_products', status: 303);
    }

    #[Route('/{id}/supprimer', name: 'app_supplier_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Product $product, Request $request, EntityManagerInterface $em, ProductImageUploader $uploader): Response
    {
        $this->denyAccessUnlessGranted(ProductVoter::DELETE, $product);
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $name = $product->getName();
        $images = $product->getImagePaths();
        $em->remove($product);
        $em->flush();
        $uploader->removeAll($images);
        $this->addFlash('success', t('« %name% » a été supprimé. Les commandes passées restent dans votre historique.', ['%name%' => $name]));

        return $this->redirectToRoute('app_supplier_products', status: 303);
    }

    /** @return list<UploadedFile> */
    private function uploadedFiles(FormInterface $form): array
    {
        $files = $form->get('images')->getData();

        return array_values(array_filter(\is_array($files) ? $files : [], static fn (mixed $f): bool => $f instanceof UploadedFile));
    }
}
