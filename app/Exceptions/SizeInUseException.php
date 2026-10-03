<?php

namespace App\Exceptions;

use App\Models\Size;
use RuntimeException;

/**
 * A size with variants in it cannot be deleted.
 *
 * The foreign key of `product_variants.size_id` restricts the delete, so the row
 * could not be removed even without this check. It is caught before the delete so
 * the admin is told how many variants are in the way, and so the way out — turning
 * the size off — is named instead of left to be guessed.
 */
class SizeInUseException extends RuntimeException
{
    public static function forSize(Size $size, int $variants): self
    {
        $plural = $variants === 1 ? '1 variante la usa' : "{$variants} variantes la usan";

        return new self(
            "No se puede eliminar la talla «{$size->name}» porque {$plural}; "
            .'desactívala para sacarla de la oferta sin perder sus variantes.'
        );
    }
}
