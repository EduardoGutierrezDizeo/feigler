<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A hex without a `#` in front is not the value the column can store.
 *
 * The column is six hexadecimal digits, and the `#` is what makes them read as a color
 * rather than as a number, so it is part of the value instead of something the admin
 * has to remember to type. This is reported when the six digits are there and the `#`
 * is not, or when what came in is not a color at all.
 */
class InvalidColorHexException extends RuntimeException
{
    public static function forValue(string $hex): self
    {
        return new self("El color «{$hex}» no es un hexadecimal válido: se espera # seguido de seis dígitos, como #1A2B3C.");
    }
}
