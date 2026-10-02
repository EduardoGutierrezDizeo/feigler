<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use RuntimeException;

class InvalidVariantSizeException extends RuntimeException
{
    /**
     * The size typed by the admin is not one of the sizes the store sells, so it
     * would have been stored as a typo nobody notices until the SKU is printed.
     */
    public static function unsupported(string $size): self
    {
        return new self(
            "La talla «{$size}» no existe; las tallas disponibles son: "
            .implode(', ', ProductVariant::SIZES).'.'
        );
    }
}
