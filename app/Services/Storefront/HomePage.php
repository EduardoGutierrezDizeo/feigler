<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HomePage
{
    private const NOVIDADES_LIMIT = 4;

    public function home(): array
    {
        $sections = $this->buildSections();
        $newProducts = $this->buildNewProducts();

        return [
            'sections' => $sections,
            'newProducts' => $newProducts,
            'store' => [
                'hours' => config('tienda.horario'),
                'mapUrl' => config('tienda.como_llegar_url'),
                'image' => config('tienda.imagen'),
            ],
            'cartCount' => 0,
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     url: string,
     *     categories: list<array{name: string, count: int, image: string|null, url: string}>
     * }>
     */
    private function buildSections(): array
    {
        $result = [];

        foreach (StoreSection::cases() as $section) {
            $visibleCategories = $this->getVisibleCategoriesForSection($section);

            if ($visibleCategories->isEmpty()) {
                continue;
            }

            $categories = $visibleCategories->map(function (Category $category) use ($section) {
                $image = $this->getRecentVisibleProductImageForCategory($category);
                $count = $this->countVisibleProductsInCategory($category);

                return [
                    'name' => $category->name,
                    'count' => $count,
                    'image' => $image,
                    'url' => '/'.$section->value.'?categoria[]='.$category->id, // Provisional; rutas se definirán después
                ];
            })->values()->all();

            $result[] = [
                'key' => $section->value,
                'label' => $section->label(),
                'url' => '/'.$section->value, // Provisional; rutas se definirán después
                'categories' => $categories,
            ];
        }

        return $result;
    }

    /**
     * Las cuatro prendas destacadas del catálogo, ya convertidas en tarjetas.
     *
     * Las variantes se traen con su color para que ProductCards arme cada
     * tarjeta sin volver a consultar: una tarjeta armada a mano costaría dos
     * consultas más por cada novedad.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     url: string,
     *     price: int,
     *     image: string|null,
     *     badge: string|null,
     *     colors: list<array{name: string, hex: string}>
     * }>
     */
    private function buildNewProducts(): array
    {
        $products = Product::query()
            ->with(['category', 'images'])
            ->withWhereHas('variants', function ($q) {
                $q->where('is_active', true)->with('color');
            })
            ->visible()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::NOVIDADES_LIMIT)
            ->get();

        return ProductCards::makeAll($products);
    }

    private function getVisibleCategoriesForSection(StoreSection $section): Collection
    {
        return Category::query()
            ->where('section', $section->value)
            ->whereHas('products', fn (Builder $q): Builder => $q->visible())
            ->with(['products' => function ($q) {
                $q->visible()
                    ->select('id', 'category_id', 'created_at', 'cover_color_id', 'slug', 'name', 'base_price', 'status')
                    ->with(['images']);
            }])
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    private function getRecentVisibleProductImageForCategory(Category $category): ?string
    {
        $product = $category->products
            ->sortByDesc('created_at')
            ->sortByDesc('id')
            ->first();

        return $product?->coverImage?->thumbnailUrl();
    }

    private function countVisibleProductsInCategory(Category $category): int
    {
        return Product::query()
            ->visible()
            ->where('category_id', $category->id)
            ->count('products.id');
    }
}
