<?php

namespace App\Exceptions;

use App\Models\Material;
use RuntimeException;

/**
 * A material that products carry cannot be deleted.
 *
 * The foreign key of `material_product.material_id` restricts the delete, so the row
 * could not be removed even without this check. It is caught before the delete so the
 * admin is told how many garments describe their composition with it, and so the way
 * out — turning the material off — is named instead of left to be guessed.
 */
class MaterialInUseException extends RuntimeException
{
    public static function forMaterial(Material $material, int $products): self
    {
        $plural = $products === 1 ? '1 producto lo usa' : "{$products} productos lo usan";

        return new self(
            "No se puede eliminar el material «{$material->name}» porque {$plural}; "
            .'desactívalo para retirarlo de la oferta sin quitarlo de esos productos.'
        );
    }
}
