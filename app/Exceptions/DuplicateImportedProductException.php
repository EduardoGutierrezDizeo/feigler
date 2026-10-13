<?php

namespace App\Exceptions;

use App\Models\Category;
use RuntimeException;

class DuplicateImportedProductException extends RuntimeException
{
    /**
     * The name the file carries is already taken by a product of the same
     * category, which happens when the same file is confirmed twice or when two
     * loads run at once.
     *
     * `products` has no unique index on the name — only on `slug` and `reference`
     * — so the check lives in the importer, and this is its word for it: it is
     * written the same way the preview writes it, so a file and its confirmation
     * never disagree about why a garment cannot be saved.
     */
    public static function forName(string $name, Category $category): self
    {
        return new self(
            "Ya existe un producto llamado «{$name}» en la categoría «{$category->name}»."
        );
    }
}
