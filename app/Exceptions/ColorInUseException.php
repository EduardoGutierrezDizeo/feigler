<?php

namespace App\Exceptions;

use App\Models\Color;
use RuntimeException;

/**
 * A color that variants or images point at cannot be deleted.
 *
 * Both foreign keys restrict the delete, so the row could not be removed even without
 * this check. It is caught before the delete so the admin is told what is holding the
 * color, how much of it there is, and that turning it off is the way out.
 */
class ColorInUseException extends RuntimeException
{
    public static function forColor(Color $color, int $variants, int $images): self
    {
        $variantes = $variants === 1 ? '1 variante la usa' : "{$variants} variantes la usan";
        $imagenes = $images === 1 ? '1 imagen está en ella' : "{$images} imágenes están en ella";

        return new self(
            "No se puede eliminar el color «{$color->name}» porque {$variantes} y {$imagenes}; "
            .'desactívalo para sacarlo de la oferta sin perder variantes ni fotos.'
        );
    }
}
