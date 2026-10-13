<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The price a person wrote in a file cannot be read as an amount.
 *
 * A price is written by hand, so `85000`, `85.000` and `$ 85.000` all mean the
 * same amount and all three have to arrive at the same number; a value that is
 * not a number, one that is ambiguous, a zero or a negative one does not say
 * what a garment costs and is refused here. The message names the value that
 * was written, which is the only thing the reader can go back and fix.
 */
class InvalidProductPriceException extends RuntimeException
{
    public static function forValue(mixed $value): self
    {
        $written = is_scalar($value) ? trim((string) $value) : get_debug_type($value);

        return new self(
            "El precio «{$written}» no es un monto válido. Escríbelo como «85000» o «85.000», sin ceros ni negativos."
        );
    }
}
