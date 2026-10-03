<?php

namespace App\Exceptions;

use App\Models\Category;
use RuntimeException;

/**
 * A category that still has products in it cannot go away.
 *
 * The foreign key of `products.category_id` restricts the delete, so the row could
 * not be removed even without this check. It is caught before the delete so the
 * admin is told which products are in the way, and so the sizes of the category are
 * left untouched instead of disappearing with a failed delete.
 */
class CategoryInUseException extends RuntimeException
{
    public static function forProducts(Category $category): self
    {
        return new self(
            "No se puede eliminar «{$category->name}» porque tiene productos asociados. "
            .'Primero mueve esos productos a otra categoría o elimínalos.'
        );
    }
}
