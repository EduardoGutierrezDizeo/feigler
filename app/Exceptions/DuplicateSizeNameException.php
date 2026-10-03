<?php

namespace App\Exceptions;

use App\Models\Category;
use RuntimeException;

/**
 * Two sizes of the same category cannot carry the same name.
 *
 * The database has a unique index on `(category_id, name)` and MySQL would refuse the
 * row, but it would refuse it for the wrong reason: with a collation that ignores case
 * and accents it would also refuse `ÚNICA` against `única`, and it would say nothing
 * about which of the two sizes is being repeated. The names are compared here
 * instead, so the admin is told which size of which category is the duplicate.
 */
class DuplicateSizeNameException extends RuntimeException
{
    public static function forName(Category $category, string $name): self
    {
        return new self(
            "La categoría «{$category->name}» ya tiene una talla llamada «{$name}»."
        );
    }
}
