<?php

namespace App\Services\Storefront;

use App\Enums\HomeNewProductsState;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The decision of what the «Novedades» section of the home page shows today.
 *
 * The storefront asks this class to render the section and the panel asks it to
 * show «Así se ve hoy en el inicio», so the administrator always reads on screen
 * exactly what the home page prints. The queries are the same ones the home page
 * used when the decision lived inside it: reading them here only gives the panel
 * a second reader, not a second copy of the rule.
 *
 * A hand-picked list wins over the automatic rule. Only when there is no choice,
 * or none of the chosen products is shown anymore, the automatic rule runs: the
 * newest visible products. The chosen products, when they show, keep their slot;
 * a list of three is not filled with automatic picks.
 */
class HomeNewProducts
{
    /**
     * The products and the reason they are the ones being shown.
     *
     * @return array{products: Collection<int, Product>, state: HomeNewProductsState}
     */
    public function effective(): array
    {
        $rows = HomeFeaturedProduct::query()
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'products' => $this->automatic(),
                'state' => HomeNewProductsState::SinElegidos,
            ];
        }

        $products = $this->featuredProducts($rows->pluck('product_id'));

        $featured = collect($rows)
            ->map(fn ($row) => $row->product_id)
            ->map(fn (int $productId) => $products[$productId] ?? null)
            ->filter()
            ->take(HomeFeaturedProduct::MAX)
            ->values();

        if ($featured->isEmpty()) {
            return [
                'products' => $this->automatic(),
                'state' => HomeNewProductsState::ElegidosOcultos,
            ];
        }

        return [
            'products' => $featured,
            'state' => HomeNewProductsState::Manual,
        ];
    }

    /**
     * The products picked by hand that the storefront still shows, keyed by id.
     *
     * The variants are brought with their color so that ProductCards builds each
     * card without asking again: a card built by hand would cost two queries per
     * novelty. The rows are read first and the products in a second query keyed
     * by id, so a list of any length costs the same.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, Product>
     */
    private function featuredProducts(Collection $productIds): Collection
    {
        return Product::query()
            ->with(['category', 'images'])
            ->withWhereHas('variants', function ($q) {
                $q->where('is_active', true)->with('color');
            })
            ->visible()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * The automatic rule: the newest visible products, when no decision or no
     * visible decision exists.
     *
     * @return Collection<int, Product>
     */
    private function automatic(): Collection
    {
        return Product::query()
            ->with(['category', 'images'])
            ->withWhereHas('variants', function ($q) {
                $q->where('is_active', true)->with('color');
            })
            ->visible()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(HomeFeaturedProduct::MAX)
            ->get();
    }
}
