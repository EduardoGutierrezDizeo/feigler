<?php

namespace App\Exceptions;

use App\Models\Color;
use App\Models\Product;
use RuntimeException;

class DuplicateProductVariantException extends RuntimeException
{
    /**
     * A product already sells that size in that color, so the unique index on
     * (product_id, size, color_id) would have refused the row anyway.
     *
     * It is caught before the insert so the admin is told which combination is
     * repeated instead of reading a driver error about an index name.
     */
    public static function forCombination(Product $product, string $size, Color $color): self
    {
        return new self(
            "El producto «{$product->name}» ya tiene una variante en talla {$size} y color {$color->name}."
        );
    }
}
