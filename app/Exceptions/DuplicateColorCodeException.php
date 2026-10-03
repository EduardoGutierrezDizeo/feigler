<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Two colors cannot carry the same code.
 *
 * The code is what tells two colors apart in a SKU, so it cannot be shared. The
 * database has a unique index on it and MySQL would refuse the row, but a driver
 * error about an index name says nothing about which color was already there.
 */
class DuplicateColorCodeException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("Ya existe un color con el código «{$code}».");
    }
}
