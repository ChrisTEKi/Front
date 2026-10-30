<?php

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Taxonomie des rayons (catégories). C'est la STRUCTURE du site (navigation), pas des données de catalogue :
 * elle reprend `initialCategories` de la maquette.
 *
 * Ce fichier ne contient que ce qui ne change pas d'une langue à l'autre (identifiant, adresse « slug », icône, couleur, image).
 * Les TEXTES (nom, description, sous-rayons) sont dans translations/categories.<langue>.yaml (FR / EN / ES) et sont
 * traduits ici, dans la langue de la requête en cours : les templates reçoivent déjà `name`, `description` et `subcategories`.
 *
 * Les sous-rayons « s1 … sN » de chaque rayon sont dans le fichier de traduction ; la liste commence toujours par « Tous ».
 */
final class CategoryProvider
{
    /** Nombre de sous-rayons (hors « Tous ») de chaque rayon, dans l'ordre d'affichage. */
    private const SUBCATEGORY_COUNT = [
        'electronique' => 5, 'mode' => 4, 'electromenager' => 4, 'alimentation' => 4,
        'jouets' => 4, 'maison' => 4, 'beaute' => 4, 'sport' => 4,
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map($this->localize(...), self::definitions());
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $category) {
            if ($category['slug'] === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Ajoute les textes traduits (langue de la requête) à la définition d'un rayon.
     *
     * @param array<string,mixed> $category
     *
     * @return array<string,mixed>
     */
    private function localize(array $category): array
    {
        $slug = (string) $category['slug'];
        $subcategories = [$this->translator->trans('sub.all', [], 'categories')];
        for ($i = 1; $i <= (self::SUBCATEGORY_COUNT[$slug] ?? 0); ++$i) {
            $subcategories[] = $this->translator->trans($slug.'.s'.$i, [], 'categories');
        }

        return $category + [
            'name' => $this->translator->trans($slug.'.name', [], 'categories'),
            'description' => $this->translator->trans($slug.'.description', [], 'categories'),
            'subcategories' => $subcategories,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function definitions(): array
    {
        return [
            [
                'id' => 'cat-elec', 'slug' => 'electronique',
                'icon' => 'tabler:device-mobile',
                'color' => 'bg-blue-50 text-blue-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-mode', 'slug' => 'mode',
                'icon' => 'tabler:shirt',
                'color' => 'bg-emerald-50 text-emerald-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1489987707025-afc232f7ea0f?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-electromenager', 'slug' => 'electromenager',
                'icon' => 'tabler:bolt',
                'color' => 'bg-indigo-50 text-indigo-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-alim', 'slug' => 'alimentation',
                'icon' => 'tabler:apple',
                'color' => 'bg-orange-50 text-orange-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-enfants', 'slug' => 'jouets',
                'icon' => 'tabler:baby-carriage',
                'color' => 'bg-pink-50 text-pink-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1585366119957-e9730b6d0f60?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-maison', 'slug' => 'maison',
                'icon' => 'tabler:home',
                'color' => 'bg-amber-50 text-amber-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-beaute', 'slug' => 'beaute',
                'icon' => 'tabler:sparkles',
                'color' => 'bg-rose-50 text-rose-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1608571423902-eed4a5ad8108?w=1600&auto=format&fit=crop&q=80',
            ],
            [
                'id' => 'cat-sport', 'slug' => 'sport',
                'icon' => 'tabler:activity',
                'color' => 'bg-teal-50 text-teal-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1517838277536-f5f99be501cd?w=1600&auto=format&fit=crop&q=80',
            ],
        ];
    }
}
