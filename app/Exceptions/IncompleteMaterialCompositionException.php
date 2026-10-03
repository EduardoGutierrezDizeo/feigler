<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The percentages of a garment have to add up to the whole of it.
 *
 * Anything other than a hundred leaves the composition either incomplete or impossible,
 * and which of the two it is cannot be told from the numbers alone: it is said here
 * instead, with the sum that arrived, so the admin can see what the list adds up to.
 */
class IncompleteMaterialCompositionException extends RuntimeException
{
    public static function forTotal(int $total): self
    {
        return new self(
            "Los porcentajes de los materiales suman {$total} y deben sumar exactamente 100."
        );
    }
}
