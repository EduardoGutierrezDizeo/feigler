<?php

namespace App\Exceptions;

use App\Models\Color;
use RuntimeException;

/**
 * The color is one the store still carries, but it has stopped being offered.
 *
 * A color that is turned off can still be edited while a variant stays in it, so this
 * is only about putting a variant into one, whether it is being created or moved from
 * another color.
 */
class InactiveVariantColorException extends RuntimeException
{
    public static function forColor(Color $color): self
    {
        return new self("El color «{$color->name}» está desactivado y no admite variantes nuevas.");
    }
}
