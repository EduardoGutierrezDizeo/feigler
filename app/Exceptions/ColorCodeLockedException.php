<?php

namespace App\Exceptions;

use App\Models\Color;
use RuntimeException;

/**
 * The code of a color that variants are sold in is part of the SKU of those variants.
 *
 * The SKU is written once, when the variant is created, and it is never rewritten: it
 * is printed on labels and lives in the orders that were paid for. Changing the code
 * would leave every variant in that color carrying a SKU that no longer matches the
 * color, so the way out is to add the new color instead of renaming this one.
 */
class ColorCodeLockedException extends RuntimeException
{
    public static function forColor(Color $color): self
    {
        return new self(
            "El código «{$color->code}» del color «{$color->name}» no se puede cambiar porque está en uso: "
            .'forma parte del SKU de sus variantes. Desactiva el color y crea otro.'
        );
    }
}
