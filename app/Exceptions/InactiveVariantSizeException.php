<?php

namespace App\Exceptions;

use App\Models\Size;
use RuntimeException;

/**
 * The size is one the category still carries, but the store has stopped offering it.
 *
 * A size that is turned off can still be edited while a variant stays in it, so this
 * is only about putting a variant into one, whether it is being created or moved from
 * another size.
 */
class InactiveVariantSizeException extends RuntimeException
{
    public static function forSize(Size $size): self
    {
        return new self("La talla «{$size->name}» está desactivada y no admite variantes nuevas.");
    }
}
