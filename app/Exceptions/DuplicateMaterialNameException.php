<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Two materials of the store cannot carry the same name.
 *
 * `materials.name` is unique in the whole store, and MySQL would refuse the row, but
 * it would refuse it for the wrong reason: with a collation that ignores case and
 * accents it would also refuse `Café` against `cafe`, and it would say nothing about
 * which material is the repeated one. The names are compared here instead, so the
 * admin is told which material is the duplicate.
 */
class DuplicateMaterialNameException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("Ya existe un material llamado «{$name}».");
    }
}
