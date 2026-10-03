<?php

namespace App\Exceptions;

use App\Models\Category;
use App\Models\Size;
use RuntimeException;

class InvalidVariantSizeException extends RuntimeException
{
    /**
     * The size chosen for the variant belongs to another category, so the garment
     * would be offered in a size that category does not sell: trousers in a shirt's
     * `S`, or a shirt in a trouser's `42`.
     *
     * The sizes of a category are its own rows, and two categories may carry the same
     * name, so the name on its own says nothing about whether a size fits here. It is
     * the category the size belongs to that decides, and the message names both so
     * the admin can see what was picked and where it belongs.
     */
    public static function outsideCategory(Size $size, Category $category): self
    {
        return new self(
            "La talla «{$size->name}» pertenece a la categoría «{$size->category->name}» "
            ."y no a «{$category->name}», que es la del producto."
        );
    }
}
