<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HomePage
{
    private const NOVIDADES_LIMIT = 4;
    private const BADGE_NUEVO_DIAS = 30;

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
                    'url' => '/' . $section->value . '?categoria[]=' . $category->id, // Provisional; rutas se definirán después
                ];
            })->values()->all();

            $result[] = [
                'key' => $section->value,
                'label' => $section->label(),
                'url' => '/' . $section->value, // Provisional; rutas se definirán después
                'categories' => $categories,
            ];
        }

        return $result;
    }

    /**
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
                $q->where('is_active', true);
            })
            ->where('status', '!=', 'inactive')
            ->whereHas('variants', function ($q) {
                $q->where('is_active', true);
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::NOVIDADES_LIMIT)
            ->get();

        return $products->map(function (Product $product) {
            $totalStock = $this->getTotalActiveStock($product);
            $isNew = $this->isNewProduct($product);

            $badge = null;
            if ($totalStock <= 0) {
                $badge = 'agotado';
            } elseif ($isNew) {
                $badge = 'nuevo';
            }

            $image = $this->getCoverImageThumbnail($product);
            $colors = $this->getActiveColorsForProduct($product);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'url' => '/producto/' . $product->id . '-' . $product->slug, // Provisional; rutas se definirán después
                'price' => (int) round((float) $product->base_price),
                'image' => $image,
                'badge' => $badge,
                'colors' => $colors,
            ];
        })->values()->all();
    }

    private function getVisibleCategoriesForSection(StoreSection $section): Collection
    {
        return Category::query()
            ->where('section', $section->value)
            ->whereHas('products', function (Builder $q) {
                $q->where('status', '!=', 'inactive')
                    ->whereHas('variants', function (Builder $vq) {
                        $vq->where('is_active', true);
                    });
            })
            ->with(['products' => function ($q) {
                $q->where('status', '!=', 'inactive')
                    ->whereHas('variants', function ($vq) {
                        $vq->where('is_active', true);
                    })
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

        if (! $product) {
            return null;
        }

        return $this->getCoverImageThumbnail($product);
    }

    private function countVisibleProductsInCategory(Category $category): int
    {
        return DB::table('products')
            ->join('product_variants', 'products.id', '=', 'product_variants.product_id')
            ->where('products.category_id', $category->id)
            ->where('products.status', '!=', 'inactive')
            ->where('product_variants.is_active', true)
            ->distinct('products.id')
            ->count('products.id');
    }

    private function getTotalActiveStock(Product $product): int
    {
        return $product->variants()
            ->where('is_active', true)
            ->sum('stock');
    }

    private function isNewProduct(Product $product): bool
    {
        if (! $product->created_at) {
            return false;
        }

        $created = $product->created_at instanceof Carbon
            ? $product->created_at
            : Carbon::parse($product->created_at);

        return $created->greaterThanOrEqualTo(now()->subDays(self::BADGE_NUEVO_DIAS)->startOfDay());
    }

    private function getCoverImageThumbnail(Product $product): ?string
    {
        $images = $product->relationLoaded('images') ? $product->images : $product->images()->get();

        $primaries = $images->where('is_primary', true);

        if ($product->cover_color_id !== null) {
            $match = $primaries->firstWhere('color_id', $product->cover_color_id);
            if ($match) {
                return $match->thumbnailUrl();
            }
        }

        $firstPrimary = $primaries->first();
        if ($firstPrimary) {
            return $firstPrimary->thumbnailUrl();
        }

        return null;
    }

    /**
     * @return list<array{name: string, hex: string}>
     */
    private function getActiveColorsForProduct(Product $product): array
    {
        $colors = DB::table('product_variants')
            ->join('colors', 'product_variants.color_id', '=', 'colors.id')
            ->where('product_variants.product_id', $product->id)
            ->where('product_variants.is_active', true)
            ->where('colors.is_active', true)
            ->select('colors.id', 'colors.name', 'colors.hex')
            ->distinct()
            ->orderBy('colors.id')
            ->get();

        return $colors->map(function ($color) {
            return [
                'name' => $color->name,
                'hex' => $color->hex,
            ];
        })->values()->all();
    }
}
