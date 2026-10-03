<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Two variants of the same product and color normalize to the same size, so both
 * would end up pointing at the same `sizes` row and the unique index that is going
 * to replace `(product_id, size, color_id)` would refuse the second one.
 *
 * The copy is refused instead of picking a winner: which of the two rows is the
 * right one is a decision about the store's stock, not something a migration can
 * settle.
 */
class DuplicateNormalizedSizeException extends RuntimeException
{
    /**
     * @param  list<array{product: string, category: string, reference: string|null, color: string, sizes: list<string>}>  $collisions
     */
    public static function forCollisions(array $collisions): self
    {
        $lines = array_map(
            fn (array $collision): string => sprintf(
                '- «%s» (categoría «%s»%s) · color «%s»: tallas %s',
                $collision['product'],
                $collision['category'],
                $collision['reference'] !== null ? ', ref. '.$collision['reference'] : '',
                $collision['color'],
                implode(' / ', array_map(fn (string $size): string => "«{$size}»", $collision['sizes'])),
            ),
            $collisions,
        );

        return new self(
            "No se puede copiar la talla de las variantes: hay tallas que se normalizan al mismo valor dentro del mismo producto y el mismo color.\n"
            .implode("\n", $lines)
            ."\nNinguna variante ha sido modificada."
        );
    }
}
