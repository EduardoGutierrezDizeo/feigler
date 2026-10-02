<?php

namespace App\Exceptions;

use App\Models\Color;
use App\Models\Product;
use RuntimeException;

class ProductCoverColorWithoutImagesException extends RuntimeException
{
    /**
     * The color chosen as the cover of the product has no image to show.
     *
     * The cover is stored as a color and not as an image, so the picture behind it
     * is the main image of that color and a color with no images has none. Choosing
     * it would leave the product without a cover, which is exactly the state the
     * fallback of `cover_image` is there to avoid.
     */
    public static function forProduct(Product $product, Color $color): self
    {
        return new self(
            "El color «{$color->name}» no puede ser la portada del producto «{$product->name}» "
            .'porque todavía no tiene ninguna imagen.'
        );
    }
}
