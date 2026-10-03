<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\ReordersWithinList;
use App\Models\Size;

/**
 * Move a size one slot up or down inside its category.
 *
 * A size belongs to a category and the list the panel offers it in is the list of that
 * category, so this is the same reordering the categories do inside their section, over
 * the sizes of one category instead of the categories of a section. The positions are
 * rewritten as `0..n-1` starting from the first size in the category, so a category
 * whose sizes were seeded with `1..8` moves into `0..7` the first time it is used;
 * the relative order is what matters and it is preserved.
 *
 * The offset is clamped, so moving the first size up or the last one down is a no-op
 * rather than an error.
 */
class MoveSize
{
    use ReordersWithinList;

    public function __invoke(Size $size, int $offset): void
    {
        $sizes = $size->category->sizes()->ordered()->pluck('id')->all();

        $this->moveWithin(Size::class, $sizes, $size, $offset);
    }
}
