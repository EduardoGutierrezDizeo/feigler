<?php

namespace App\Exceptions;

use App\Models\Category;
use RuntimeException;

class MissingSkuPrefixException extends RuntimeException
{
    /**
     * A product cannot be given a reference because the category it belongs to has
     * no SKU prefix, and a reference is nothing more than that prefix plus a number.
     */
    public static function forCategory(Category $category): self
    {
        return new self(
            "La categoría «{$category->name}» no tiene prefijo de SKU. "
            .'Asígnalo en Categorías antes de crear productos.'
        );
    }
}
