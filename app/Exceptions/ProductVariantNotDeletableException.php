<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use RuntimeException;

class ProductVariantNotDeletableException extends RuntimeException
{
    /**
     * A variant that was sold belongs to the history of the orders that sold it.
     *
     * The foreign keys of order_items and inventory_movements both restrict the
     * delete, so the row could not be removed even if this check were not here.
     * The way out is to take it out of the catalog, not to erase it.
     */
    public static function sold(ProductVariant $variant): self
    {
        return new self(
            "No se puede eliminar la variante {$variant->sku} porque ya aparece en pedidos; "
            .'desactívala para sacarla del catálogo sin perder su historial.'
        );
    }

    /**
     * The stock was moved after the variant was created, so the movements no
     * longer add up to what the variant was born with.
     */
    public static function adjusted(ProductVariant $variant): self
    {
        return new self(
            "No se puede eliminar la variante {$variant->sku} porque su stock fue ajustado después de crearse; "
            .'desactívala para sacarla del catálogo sin perder su historial.'
        );
    }

    /**
     * The variant carries movements that never belonged to its creation: a sale,
     * a return or a manual adjustment written by an admin.
     */
    public static function otherMovements(ProductVariant $variant): self
    {
        return new self(
            "No se puede eliminar la variante {$variant->sku} porque tiene movimientos de inventario que no son el stock inicial; "
            .'desactívala para sacarla del catálogo sin perder su historial.'
        );
    }
}
