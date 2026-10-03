<?php

namespace App\Exceptions;

use App\Models\Category;
use RuntimeException;

/**
 * The store does not have a size under the name that was written.
 *
 * This is a different thing from a size that exists but is turned off, and the
 * difference is the one the person writing a file has to be told about: a name that is
 * not in the catalog is a typo or a size the store has to add first, while a name that
 * is there and turned off only has to be turned back on. So the message carries the
 * list of the sizes the category does carry, which is usually enough to see what was
 * meant.
 *
 * A size is only looked for inside one category, since a size only means something
 * inside a category: a `42` that trousers carry says nothing about the shirts, and a
 * file that asks for it under the wrong category is asking for a size that is not on
 * offer there.
 */
class UnknownSizeException extends RuntimeException
{
    /**
     * @param  list<string>  $available  The active sizes of the category, in catalog order.
     */
    public static function forSize(string $name, Category $category, array $available): self
    {
        $list = $available === [] ? 'ninguna' : implode(', ', $available);

        return new self(
            "La talla «{$name}» no existe en la categoría «{$category->name}». Tallas disponibles: {$list}."
        );
    }
}
