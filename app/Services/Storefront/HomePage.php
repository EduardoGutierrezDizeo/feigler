<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\HomeFeaturedProduct;
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
        $gathered = [];

        foreach (StoreSection::cases() as $section) {
            $visibleCategories = $this->getVisibleCategoriesForSection($section);

            if ($visibleCategories->isEmpty()) {
                continue;
            }

            $gathered[] = compact('section', 'visibleCategories');
        }

        return $this->renderSections($gathered);
    }

    /**
     * @param  list<array{section: StoreSection, visibleCategories: Collection<int, Category>}>  $gathered
     * @return list<array{
     *     key: string,
     *     label: string,
     *     url: string,
     *     categories: list<array{name: string, count: int, image: string|null, url: string}>
     * }>
     */
    private function renderSections(array $gathered): array
    {
        // The counts of all categories share one grouped query and the home
        // pictures share one service call, instead of each category asking for
        // its own count and for the picture it chose. That is what keeps the
        // home page at a fixed number of queries no matter how many categories
        // the store has.
        $allCategories = collect($gathered)
            ->flatMap(fn (array $entry): Collection => $entry['visibleCategories']);

        $counts = $this->countVisibleProductsInCategories($allCategories->pluck('id')->all());
        $homeImages = app(HomeCategoryImages::class)->forCategories($allCategories);

        $result = [];

        foreach ($gathered as ['section' => $section, 'visibleCategories' => $visibleCategories]) {
            $categories = $visibleCategories->map(function (Category $category) use ($section, $counts, $homeImages) {
                return [
                    'name' => $category->name,
                    'count' => $counts[$category->getKey()] ?? 0,
                    'image' => $homeImages[$category->getKey()]['url'] ?? null,
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
     * Las prendas destacadas de la portada, ya convertidas en tarjetas.
     *
     * Las variantes se traen con su color para que ProductCards arme cada
     * tarjeta sin volver a consultar: una tarjeta armada a mano costaría dos
     * consultas más por cada novedad.
     *
     * Una lista manual de novedades gana sobre la regla automática; solo cuando
     * no hay elección, o ninguna de las elegidas se muestra ya, se recurre a lo
     * de siempre.
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
        $featured = $this->getManualFeaturedProducts();

        $products = $featured ?? Product::query()
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

    /**
     * The visible product count of every category, in one grouped query.
     *
     * Each category used to ask for its own count, and a home page with more
     * categories cost more queries; one query for all of them keeps the count of
     * the page fixed no matter the size of the catalog. The result is keyed by
     * category id, and a category with nothing visible is simply absent.
     *
     * @param  list<int>  $categoryIds
     * @return Collection<int<0, max>, string> pluck() keyed by category id
     */
    private function countVisibleProductsInCategories(array $categoryIds): Collection
    {
        if ($categoryIds === []) {
            return new Collection;
        }

        return Product::query()
            ->visible()
            ->whereIn('category_id', $categoryIds)
            ->groupBy('category_id')
            ->selectRaw('category_id, count(*) as total')
            ->pluck('total', 'category_id');
    }

    /**
     * The hand-picked highlights, when there is a decision; null when there is
     * none or when none of the chosen products is shown, which is when the
     * automatic rule of always keeps running.
     *
     * The rows are read first and the products in a second query keyed by id, so
     * a list of any length costs the same. Only the chosen products are shown,
     * in their chosen order and with the visible ones keeping their slot: a list
     * of three is not filled with automatic picks.
     *
     * @return Collection<int, Product>|null
     */
    private function getManualFeaturedProducts(): ?Collection
    {
        $rows = HomeFeaturedProduct::query()
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $products = Product::query()
            ->with(['category', 'images'])
            ->withWhereHas('variants', function ($q) {
                $q->where('is_active', true)->with('color');
            })
            ->visible()
            ->whereIn('id', $rows->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $featured = collect($rows)
            ->pluck('product_id')
            ->map(fn (int $productId) => $products[$productId] ?? null)
            ->filter()
            ->take(self::NOVIDADES_LIMIT)
            ->values();

        return $featured->isEmpty() ? null : $featured;
    }
}
