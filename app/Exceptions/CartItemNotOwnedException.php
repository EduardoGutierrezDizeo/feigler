<?php

namespace App\Exceptions;

use App\Models\CartItem;
use RuntimeException;

class CartItemNotOwnedException extends RuntimeException
{
    /**
     * A line of someone else's cart was operated on, which would let one shopper
     * change another shopper's cart if it were allowed to go through.
     */
    public static function forItem(CartItem $item): self
    {
        return new self(
            "La línea del carrito #{$item->getKey()} no pertenece al carrito actual."
        );
    }
}
