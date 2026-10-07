<?php

namespace App\Exceptions;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Exception;

/**
 * A decision to show a picture from a product as the home photo of a category
 * that the store refuses to honour.
 *
 * The photo has to belong to a product of that very category, and that product
 * has to be one the storefront shows: anything else would put a picture on the
 * home page that either represents somebody else's product or belongs to a
 * garment nobody can click through to.
 */
class HomeImageProductException extends Exception
{
    public static function notInCategory(Category $category, ProductImage $image): self
    {
        return new self(
            "La imagen «{$image->path}» no es de un producto de la categoría «{$category->name}»."
        );
    }

    public static function notVisible(Product $product): self
    {
        return new self(
            "El producto «{$product->name}» no se muestra en la tienda."
        );
    }
}
