<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Category taxonomy ("rayons"). This is site structure (navigation), NOT catalog data:
 * it mirrors initialCategories from the mockup. It will be replaced by a Doctrine
 * repository once the back-end exists — controllers only depend on this API.
 */
final class CategoryProvider
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $defaults = $this->defaultCategories();
        $defaultsBySlug = array_column($defaults, null, 'slug');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, slug, name, description, icon FROM category ORDER BY "position", name'
        );

        if ([] === $rows) {
            return $defaults;
        }

        return array_map(static function (array $row) use ($defaultsBySlug): array {
            $fallback = $defaultsBySlug[$row['slug']] ?? [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'nameEn' => $row['name'],
                'nameEs' => $row['name'],
                'icon' => 'tabler:category',
                'color' => 'bg-slate-50 text-slate-600',
                'bannerImage' => null,
                'description' => '',
                'subcategories' => [],
            ];

            return array_replace($fallback, [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'] ?? $fallback['description'],
                'icon' => $row['icon'] ?: $fallback['icon'],
            ]);
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function defaultCategories(): array
    {
        return [
            [
                'id' => 'cat-elec', 'slug' => 'electronique', 'name' => 'Appareils & High-Tech',
                'nameEn' => 'Electronics', 'nameEs' => 'Electrónica', 'icon' => 'tabler:device-mobile',
                'color' => 'bg-blue-50 text-blue-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Smartphones reconditionnés certifiés et neufs, PC portables, casques audio sans fil et montres connectées avec garantie.',
                'subcategories' => ['Tous', 'Smartphones', 'Ordinateurs & PC', 'Audio & Casques', 'Montres connectées', 'Accessoires'],
            ],
            [
                'id' => 'cat-mode', 'slug' => 'mode', 'name' => 'Vêtements & Mode',
                'nameEn' => 'Clothing & Fashion', 'nameEs' => 'Moda', 'icon' => 'tabler:shirt',
                'color' => 'bg-emerald-50 text-emerald-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1489987707025-afc232f7ea0f?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Les dernières tendances mode : robes bohèmes, baskets respirantes, vestes bomber et chemises élégantes livrées chez vous.',
                'subcategories' => ['Tous', 'Robes & Jupes', 'Chaussures & Baskets', 'Vestes & Manteaux', 'Chemises & Hauts'],
            ],
            [
                'id' => 'cat-electromenager', 'slug' => 'electromenager', 'name' => 'Électroménager',
                'nameEn' => 'Home Appliances', 'nameEs' => 'Electrodomésticos', 'icon' => 'tabler:bolt',
                'color' => 'bg-indigo-50 text-indigo-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1556911220-e15b29be8c8f?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Appareils modernes pour équiper votre cuisine et faciliter votre quotidien : Air Fryer, robots multifonctions, blenders et machines expresso.',
                'subcategories' => ['Tous', 'Cuisson & Air Fryer', 'Robots & Pâtisserie', 'Blenders & Mixeurs', 'Café & Expresso'],
            ],
            [
                'id' => 'cat-alim', 'slug' => 'alimentation', 'name' => 'Alimentation & Épicerie',
                'nameEn' => 'Groceries & Fresh', 'nameEs' => 'Alimentos', 'icon' => 'tabler:apple',
                'color' => 'bg-orange-50 text-orange-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Le meilleur du terroir malgache et produits frais : gousses de vanille Bourbon, miel d’eucalyptus, chocolats fins et paniers maraîchers.',
                'subcategories' => ['Tous', 'Vanille & Épices', 'Chocolats & Douceurs', 'Miels & Confitures', 'Fruits & Légumes frais'],
            ],
            [
                'id' => 'cat-enfants', 'slug' => 'jouets', 'name' => 'Jouets & Enfants',
                'nameEn' => 'Toys & Kids', 'nameEs' => 'Juguetes', 'icon' => 'tabler:baby-carriage',
                'color' => 'bg-pink-50 text-pink-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1585366119957-e9730b6d0f60?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Jeux éducatifs, coffrets de construction créatifs, peluches hypoallergéniques et véhicules télécommandés pour petits et grands.',
                'subcategories' => ['Tous', 'Blocs & Construction', 'Véhicules & Circuits', 'Peluches & Doudous', 'Plein air & Trottinettes'],
            ],
            [
                'id' => 'cat-maison', 'slug' => 'maison', 'name' => 'Maison & Déco',
                'nameEn' => 'Home & Living', 'nameEs' => 'Hogar', 'icon' => 'tabler:home',
                'color' => 'bg-amber-50 text-amber-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1513519245088-0e12902e5a38?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Décoration chaleureuse, artisanat malagasy en vannerie et rabane tressée, luminaires LED tactiles et parures de lit confort.',
                'subcategories' => ['Tous', 'Luminaires & Lampes', 'Artisanat & Vannerie', 'Linge de maison', 'Miroirs & Déco'],
            ],
            [
                'id' => 'cat-beaute', 'slug' => 'beaute', 'name' => 'Beauté & Santé',
                'nameEn' => 'Beauty & Wellness', 'nameEs' => 'Belleza', 'icon' => 'tabler:sparkles',
                'color' => 'bg-rose-50 text-rose-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1608571423902-eed4a5ad8108?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Cosmétique naturelle et trésors botaniques de Madagascar : huiles essentielles de Ravintsara pures, sérums bio et soins réparateurs.',
                'subcategories' => ['Tous', 'Huiles Essentielles', 'Soins Visage & Corps', 'Savons & Soins naturels', 'Huiles végétales'],
            ],
            [
                'id' => 'cat-sport', 'slug' => 'sport', 'name' => 'Sport & Loisirs',
                'nameEn' => 'Sports & Outdoors', 'nameEs' => 'Deportes', 'icon' => 'tabler:activity',
                'color' => 'bg-teal-50 text-teal-600',
                'bannerImage' => 'https://images.unsplash.com/photo-1517838277536-f5f99be501cd?w=1600&auto=format&fit=crop&q=80',
                'description' => 'Équipements pour le fitness, vélos tout-terrain, matériel de camping et loisirs sportifs pour rester actif au quotidien.',
                'subcategories' => ['Tous', 'Vélos & VTT', 'Fitness & Tapis', 'Camping & Randonnée', 'Ballons & Sports collectifs'],
            ],
        ];
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
}
