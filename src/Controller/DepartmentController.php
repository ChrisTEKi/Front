<?php

namespace App\Controller;

use App\Service\CategoryProvider;
use App\Service\CatalogProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DepartmentController extends AbstractController
{
    #[Route('/rayon/{slug}', name: 'app_department', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug, CategoryProvider $categories, CatalogProvider $catalog): Response
    {
        $department = $categories->findBySlug($slug) ?? throw new NotFoundHttpException('Rayon introuvable.');

        return $this->render('department/show.html.twig', [
            'department' => $department,
            'otherCategories' => array_values(array_filter(
                $categories->all(),
                static fn (array $c): bool => $c['slug'] !== $slug,
            )),
            'products' => $catalog->findActiveProducts($slug, 100),
            'brands' => [],
        ]);
    }
}
