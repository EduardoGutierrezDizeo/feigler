<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every code the store could derive from a color name is already taken.
 *
 * The code is derived from the name and, when the derived one is taken, from its first
 * two characters plus a character of its own, so a name gives a code unless the store
 * already has all thirty-six of them under one pair of letters. This is the one case
 * where no code can be produced, and it is reported instead of returning a code that
 * another color is already carrying.
 */
class ColorCodeUnavailableException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(
            "No se pudo generar un código para el color «{$name}»: ya están ocupados todos los códigos posibles para sus dos primeras letras. Escribe el código a mano."
        );
    }
}
