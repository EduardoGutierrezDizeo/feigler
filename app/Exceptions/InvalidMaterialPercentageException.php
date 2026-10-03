<?php

namespace App\Exceptions;

use App\Models\Material;
use RuntimeException;

/**
 * The percentage of a material has to be a whole share of the garment.
 *
 * A garment is made of what it is made of, so the percentages add up to the whole of
 * it and no fraction of a percent is left unaccounted for. Zero and anything over a
 * hundred are refused because neither says anything about a share, and a decimal is
 * refused because the panel asks for whole numbers and one that arrived as a decimal
 * means the two sides of the form disagree on what it wants.
 */
class InvalidMaterialPercentageException extends RuntimeException
{
    public static function forPercentage(Material $material, int|float|string $percentage): self
    {
        return new self(
            "El porcentaje del material «{$material->name}» debe ser un número entero entre 1 y 100; "
            ."se ha recibido {$percentage}."
        );
    }
}
