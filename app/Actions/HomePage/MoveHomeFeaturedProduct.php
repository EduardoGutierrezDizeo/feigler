<?php

namespace App\Actions\HomePage;

use App\Actions\ProductDetails\Concerns\ReordersWithinList;
use App\Models\HomeFeaturedProduct;

/**
 * Move a highlight one slot up or down inside the home highlights.
 *
 * This is the same reordering the categories do inside their section: the whole
 * list is reindexed to `0..n-1` instead of two positions being swapped, which is
 * what makes the move happen at all when two rows happen to share a position.
 *
 * The offset is clamped, so moving the first highlight up or the last one down
 * is a no-op rather than an error.
 */
class MoveHomeFeaturedProduct
{
    use ReordersWithinList;

    public function __invoke(HomeFeaturedProduct $featured, int $offset): void
    {
        $this->moveWithin(
            HomeFeaturedProduct::class,
            HomeFeaturedProduct::query()
                ->orderBy('order')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            $featured,
            $offset,
        );
    }
}
