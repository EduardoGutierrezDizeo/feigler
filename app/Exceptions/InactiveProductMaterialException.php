<?php

namespace App\Exceptions;

use App\Models\Material;
use RuntimeException;

/**
 * A material that has been turned off cannot be added to a garment.
 *
 * A material the garment already carries is left alone even when it is off: turning
 * one off is how the store stops offering it to new garments without taking it away
 * from the ones that already describe their composition with it.
 */
class InactiveProductMaterialException extends RuntimeException
{
    public static function forMaterial(Material $material): self
    {
        return new self(
            "El material «{$material->name}» está desactivado y no se puede añadir a un producto."
        );
    }
}
