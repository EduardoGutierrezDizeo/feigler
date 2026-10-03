<?php

namespace App\Actions\Products\Concerns;

use App\Exceptions\DuplicateProductVariantException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\InvalidVariantSizeException;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;

/**
 * The size and the combination rules shared by creating and editing a variant.
 */
trait GuardsVariantSize
{
    /**
     * Refuse a size the product cannot be sold in.
     *
     * A size belongs to a category, and a product belongs to one as well, so the two
     * have to be the same one: a garment of one category cannot be offered in a size
     * another category happens to name the same.
     *
     * A size that is turned off is a second rule on top of that one. It only applies
     * when the size would be new to the variant — a variant being created, or one
     * moving from another size. A variant that keeps the size it already has is left
     * alone, so turning a size off never locks the variants sitting in it, which is
     * the whole point of turning it off instead of deleting it.
     */
    private function guardSizeIsSettable(Product $product, Size $size, ?ProductVariant $variant = null): void
    {
        if ((int) $size->category_id !== (int) $product->category_id) {
            throw InvalidVariantSizeException::outsideCategory($size, $product->category);
        }

        $keepsItsSize = $variant !== null && (int) $variant->size_id === (int) $size->getKey();

        if (! $size->is_active && ! $keepsItsSize) {
            throw InactiveVariantSizeException::forSize($size);
        }
    }

    /**
     * Refuse a combination this product already sells.
     *
     * A variant being edited is left out of the search, or it would find itself
     * and refuse every edit that keeps the same size and color.
     */
    private function guardCombinationIsFree(Product $product, Size $size, Color $color, ?ProductVariant $except = null): void
    {
        $query = $product->variants()
            ->where('size_id', $size->getKey())
            ->where('color_id', $color->getKey());

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        if ($query->exists()) {
            throw DuplicateProductVariantException::forCombination($product, $size, $color);
        }
    }
}
