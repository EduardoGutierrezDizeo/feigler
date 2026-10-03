<?php

namespace App\Exceptions;

use App\Models\Material;
use RuntimeException;

/**
 * The same material cannot be listed twice for a garment.
 *
 * The pivot table has no primary key of its own to catch it, so two rows for the same
 * garment and the same material would both survive and the percentages would then add
 * up to more than what they were meant to. It is refused before anything is written,
 * so the list the admin typed is what has to be fixed.
 */
class RepeatedProductMaterialException extends RuntimeException
{
    public static function forMaterial(Material $material): self
    {
        return new self("El material «{$material->name}» está repetido en la lista.");
    }

    /**
     * The repeat is reported by id when the material is not there to be named.
     */
    public static function forId(int $materialId): self
    {
        return new self("El material {$materialId} está repetido en la lista.");
    }
}
