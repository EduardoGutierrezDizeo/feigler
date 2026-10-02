<?php

namespace App\Actions\Products\Concerns;

use App\Exceptions\DuplicateProductVariantException;
use App\Exceptions\InvalidVariantSizeException;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * The size and the combination rules shared by creating and editing a variant.
 */
trait GuardsVariantSize
{
    /**
     * The size in the form it is stored in: uppercased, without spaces and one of
     * the sizes the store sells.
     *
     * Normalizing here is what keeps `m` from being stored next to `M`: the
     * unique index would refuse the second one, but with a driver error instead
     * of a message saying the size is already taken.
     */
    private function normalizeSize(string $size): string
    {
        $normalized = Str::upper(preg_replace('/\s+/', '', trim($size)) ?? $size);

        if (! in_array($normalized, ProductVariant::SIZES, true)) {
            throw InvalidVariantSizeException::unsupported($size);
        }

        return $normalized;
    }

    /**
     * Refuse a combination this product already sells.
     *
     * A variant being edited is left out of the search, or it would find itself
     * and refuse every edit that keeps the same size and color.
     */
    private function guardCombinationIsFree(Product $product, string $size, Color $color, ?ProductVariant $except = null): void
    {
        $query = $product->variants()
            ->where('size', $size)
            ->where('color_id', $color->getKey());

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        if ($query->exists()) {
            throw DuplicateProductVariantException::forCombination($product, $size, $color);
        }
    }
}
