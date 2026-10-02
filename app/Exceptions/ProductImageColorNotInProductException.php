<?php

namespace App\Exceptions;

use App\Models\Color;
use App\Models\Product;
use RuntimeException;

class ProductImageColorNotInProductException extends RuntimeException
{
    /**
     * The color the images were sent for is not one the product is sold in.
     *
     * Images are grouped by color, so a gallery only makes sense for a color that
     * exists in the product: the storefront switches the pictures when it switches
     * the color, and there is no button to reach a gallery that no variant of the
     * product would ever show.
     */
    public static function forProduct(Product $product, Color $color): self
    {
        $available = $product->colors()
            ->pluck('name')
            ->join(', ');

        return new self(
            "El color «{$color->name}» no está entre los colores del producto «{$product->name}»"
            .($available === '' ? ', que todavía no tiene ninguna variante.' : ": {$available}.")
        );
    }
}
