<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    /**
     * A stock change would have driven the variant below zero, so it was rejected
     * instead of silently leaving the inventory inconsistent.
     */
    public static function forVariant(ProductVariant $variant, int $quantity): self
    {
        return new self(
            "No hay stock suficiente de «{$variant->sku}»: quedan {$variant->stock} unidades "
            ."y se pidieron {$quantity}."
        );
    }
}
