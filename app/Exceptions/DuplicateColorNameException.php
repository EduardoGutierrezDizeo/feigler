<?php

namespace App\Exceptions;

use App\Models\Color;
use RuntimeException;

/**
 * Two colors of the store cannot carry the same name.
 *
 * `colors.name` is unique in the whole store, and MySQL would refuse the row, but it
 * would refuse it for the wrong reason: with a collation that ignores case and
 * accents it would also refuse `Café` against `cafe`, and it would say nothing about
 * which color is the repeated one. The names are compared here instead, so the admin
 * is told which color is the duplicate.
 */
class DuplicateColorNameException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("Ya existe un color llamado «{$name}».");
    }
}
