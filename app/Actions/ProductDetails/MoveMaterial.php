<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\ReordersWithinList;
use App\Models\Material;

/**
 * Move a material one slot up or down inside the store.
 *
 * The materials of the store are one list, so this is the same reordering the
 * categories do inside their section: the whole list is reindexed to `0..n-1` instead
 * of two positions being swapped, which is what makes the move happen at all when two
 * materials happen to share a position.
 *
 * The offset is clamped, so moving the first material up or the last one down is a
 * no-op rather than an error.
 */
class MoveMaterial
{
    use ReordersWithinList;

    public function __invoke(Material $material, int $offset): void
    {
        $this->moveWithin(Material::class, Material::orderedIds(), $material, $offset);
    }
}
