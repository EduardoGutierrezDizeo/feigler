<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The store does not have a color under the name that was written.
 *
 * This is the other thing from a color that exists but is turned off, and the
 * difference is the one the person writing a file has to be told about: a name that is
 * not in the catalog is a typo or a color the store has to add first, while a name that
 * is there and turned off only has to be turned back on.
 *
 * There is no list of what the store does carry, because the colors are the whole
 * catalog and naming them here would bury the one thing the reader has to fix.
 */
class UnknownColorException extends RuntimeException
{
    public static function forColor(string $name): self
    {
        return new self("El color «{$name}» no existe.");
    }
}
