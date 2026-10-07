<?php

namespace App\Services\Storefront;

use App\Enums\HomeImageSource;
use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
        // The counts of all categories share one grouped query and the chosen
        // pictures share one more, instead of each category asking for its own
        // count and for the picture it chose. That is what keeps the home page at
        // a fixed number of queries no matter how many categories the store has.
        $allCategories = collect($gathered)
            ->flatMap(fn (array $entry): Collection => $entry['visibleCategories']);

        $counts = $this->countVisibleProductsInCategories($allCategories->pluck('id')->all());
        $chosenImages = $this->chosenHomeImages($allCategories);

        $result = [];

        foreach ($gathered as ['section' => $section, 'visibleCategories' => $visibleCategories]) {
            $categories = $visibleCategories->map(function (Category $category) use ($section, $counts, $chosenImages) {
                return [
                    'name' => $category->name,
                    'count' => $counts[$category->getKey()] ?? 0,
                    'image' => $this->getHomeImageFor($category, $chosenImages),
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

    private function getRecentVisibleProductImageForCategory(Category $category): ?string
    {
        $product = $category->products
            ->sortByDesc('created_at')
            ->sortByDesc('id')
            ->first();

        return $product?->coverImage?->thumbnailUrl();
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
     * The picked product pictures of every category whose decision asks for one.
     *
     * The products are loaded with the visibility rule, so a picture whose
     * product stopped being visible comes back without its product and the
     * resolution of the picture falls back to the automatic rule; a picture that
     * was deleted is simply not among the ids any more.
     *
     * The query is only asked when some category picked a picture: a home page
     * where nobody did keeps its count of queries untouched.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, ProductImage>
     */
    private function chosenHomeImages(Collection $categories): Collection
    {
        $ids = $categories
            ->filter(fn (Category $category): bool => $this->sourceOf($category) === HomeImageSource::Product)
            ->pluck('home_image_product_image_id')
            ->filter()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return ProductImage::query()
            ->whereIn('id', $ids)
            ->with(['product' => function ($query) {
                $query->visible();
            }])
            ->get()
            ->keyBy('id');
    }

    /**
     * The home picture a category chose, or the automatic one when the choice is
     * broken.
     *
     * Every failure falls back to the automatic rule on purpose: the page must
     * always render, and a category is never left without a picture because a
     * choice it made stopped being available.
     */
    private function getHomeImageFor(Category $category, Collection $chosenImages): ?string
    {
        $image = match ($this->sourceOf($category)) {
            HomeImageSource::Upload => $this->ownUploadedUrl($category),
            HomeImageSource::Product => $this->chosenProductImageUrl($category, $chosenImages),
            default => null,
        };

        return $image ?? $this->getRecentVisibleProductImageForCategory($category);
    }

    /**
     * Which decision a category made, read as it is stored.
     *
     * It reads the raw value and never asks the enum to throw, so a value that is
     * not one of the three (a column default that changed, a handwritten row)
     * behaves like the automatic rule instead of breaking the page.
     */
    private function sourceOf(Category $category): ?HomeImageSource
    {
        return HomeImageSource::tryFrom((string) $category->getRawOriginal('home_image_source'));
    }

    /**
     * The address of the photo the category uploaded, or the automatic rule when
     * there is none to show.
     */
    private function ownUploadedUrl(Category $category): ?string
    {
        $path = $category->home_image_thumbnail_path ?? $category->home_image_path;

        if ($path === null) {
            return null;
        }

        return Storage::disk(ProductImage::DISK)->url($path);
    }

    /**
     * The address of the product picture a category chose, or null when the
     * choice no longer holds: the picture was deleted, belongs to a product that
     * is not shown, or belongs to a product that moved to another category.
     */
    private function chosenProductImageUrl(Category $category, Collection $chosenImages): ?string
    {
        $image = $chosenImages->get($category->home_image_product_image_id);

        if ($image === null || $image->product === null) {
            return null;
        }

        if ((int) $image->product->category_id !== (int) $category->getKey()) {
            return null;
        }

        return $image->thumbnailUrl();
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
