<?php

namespace App\Exceptions;

use App\Models\Size;
use RuntimeException;

/**
 * The name of a size that variants are sold in is part of the SKU of those variants.
 *
 * The SKU is written once, when the variant is created, and it is never rewritten:
 * it is printed on labels and lives in the orders that were paid for. Renaming the
 * size would leave the SKU of every variant in it saying a size the store no longer
 * sells, so the way out is to turn the size off and add the new one, which creates
 * new SKUs instead of contradicting the old ones.
 */
class SizeNameLockedException extends RuntimeException
{
    public static function forSize(Size $size): self
    {
        return new self(
            'El nombre de una talla en uso no se puede cambiar porque forma parte del SKU de sus variantes; '
            ."desactívala y crea otra. La talla «{$size->name}» conserva el nombre que tiene."
        );
    }
}
