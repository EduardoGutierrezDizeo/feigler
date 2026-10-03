<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\ReordersWithinList;
use App\Models\Color;

/**
 * Move a color one slot up or down inside the store.
 *
 * The colors of the store are one list, so this is the same reordering the categories
 * do inside their section: the whole list is reindexed to `0..n-1` instead of two
 * positions being swapped, which is what makes the move happen at all when two colors
 * happen to share a position.
 *
 * The offset is clamped, so moving the first color up or the last one down is a no-op
 * rather than an error.
 */
class MoveColor
{
    use ReordersWithinList;

    public function __invoke(Color $color, int $offset): void
    {
        $this->moveWithin(Color::class, Color::orderedIds(), $color, $offset);
    }
}
