<?php

namespace App\Exceptions;

use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use Exception;

/**
 * A change to the hand-picked home highlights that the store refuses to honour.
 *
 * A highlight is a visible product that is not already in the list, and the list
 * only holds as many as the home page shows: the queue refuses what would make
 * the home page either point at something it cannot display or drop a choice.
 */
class HomeFeaturedProductException extends Exception
{
    public static function notVisible(Product $product): self
    {
        return new self(
            "El producto «{$product->name}» no se muestra en la tienda."
        );
    }

    public static function alreadyListed(Product $product): self
    {
        return new self(
            "El producto «{$product->name}» ya está en las novedades."
        );
    }

    public static function full(): self
    {
        return new self(
            'Las novedades ya tienen sus '.HomeFeaturedProduct::MAX
                .' productos. Quita uno antes de destacar otro.'
        );
    }
}
