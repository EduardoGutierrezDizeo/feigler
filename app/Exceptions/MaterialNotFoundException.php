<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * The composition of a garment names a material the store does not have.
 *
 * The pivot table has no foreign key that could catch it — the id is written by
 * `attach()` and comes straight from the form — so a material that was deleted in
 * another window would otherwise be written as a row pointing at nothing.
 */
class MaterialNotFoundException extends RuntimeException
{
    public static function forProduct(Product $product, int $materialId): self
    {
        return new self(
            "El material {$materialId} no existe y no se puede añadir al producto «{$product->name}»."
        );
    }
}
