<?php

namespace App\Actions\ProductDetails;

use App\Models\Material;

/**
 * Turn a material on or off.
 *
 * Both directions are always allowed, and that is the point of the column: turning a
 * material off is how the store stops giving it to new products without taking it away
 * from the products that already say they are made of it. A material that products
 * carry is the normal case here and not the exception, so nothing about this action can
 * refuse.
 */
class ToggleMaterial
{
    public function __invoke(Material $material): Material
    {
        // The stored value is read back instead of the one the caller happens to hold:
        // a material that was found with `firstOrCreate()` never had `is_active` in
        // its attributes, and toggling a null would turn the material on instead of
        // off.
        $material->refresh();

        $material->update(['is_active' => ! $material->is_active]);

        return $material;
    }
}
