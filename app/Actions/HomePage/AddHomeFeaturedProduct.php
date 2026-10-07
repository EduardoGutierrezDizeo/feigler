<?php

namespace App\Actions\HomePage;

use App\Exceptions\HomeFeaturedProductException;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;

/**
 * Add a product to the home highlights.
 *
 * The three guards are the rules of the list: a product the storefront does not
 * show has no business being highlighted, a product that is already in the list
 * cannot be put in twice, and a list that is full stays full — the fourth product
 * is the last one, on purpose, because the home page shows exactly as many as
 * `HomeFeaturedProduct::MAX`.
 *
 * The new row lands at the end of the list, right after the current last one.
 */
class AddHomeFeaturedProduct
{
    public function __invoke(Product $product): HomeFeaturedProduct
    {
        $isVisible = $product->newQuery()
            ->visible()
            ->whereKey($product->getKey())
            ->exists();

        if (! $isVisible) {
            throw HomeFeaturedProductException::notVisible($product);
        }

        if (HomeFeaturedProduct::query()->where('product_id', $product->getKey())->exists()) {
            throw HomeFeaturedProductException::alreadyListed($product);
        }

        if (HomeFeaturedProduct::count() >= HomeFeaturedProduct::MAX) {
            throw HomeFeaturedProductException::full();
        }

        return HomeFeaturedProduct::create([
            'product_id' => $product->getKey(),
            'order' => HomeFeaturedProduct::nextOrder(),
        ]);
    }
}
