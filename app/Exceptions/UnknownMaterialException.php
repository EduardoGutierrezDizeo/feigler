<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The store does not have a material under the name that was written.
 *
 * This is about the name, not about the id: `MaterialNotFoundException` is raised when
 * an id points at nothing, which is what would happen if a material were deleted in
 * another window after a row had been picked. Here the material was named in a text and
 * the store has nothing by that name at all.
 *
 * It is not the same thing as a material that exists but is turned off, and telling the
 * two apart is the point: a name that is not in the catalog is a typo or a material the
 * store has to add first, while a name that is there and turned off only has to be
 * turned back on.
 *
 * There is no list of what the store does carry, because the materials are the whole
 * catalog and naming them here would bury the one thing the reader has to fix.
 */
class UnknownMaterialException extends RuntimeException
{
    public static function forMaterial(string $name): self
    {
        return new self("El material «{$name}» no existe.");
    }
}
