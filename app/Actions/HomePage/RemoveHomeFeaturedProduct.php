<?php

namespace App\Actions\HomePage;

use App\Actions\ProductDetails\Concerns\ReordersWithinList;
use App\Models\HomeFeaturedProduct;
use Illuminate\Support\Facades\DB;

/**
 * Take a product out of the home highlights.
 *
 * The list is read as the home page reads it and, once the row is gone, the
 * whole list is reindexed to a contiguous `0..n-1` sequence: removing the second
 * of three leaves the first and the third as positions 0 and 1, so the home page
 * never shows a hole.
 */
class RemoveHomeFeaturedProduct
{
    use ReordersWithinList;

    public function __invoke(HomeFeaturedProduct $featured): void
    {
        DB::transaction(function () use ($featured): void {
            $orderedIds = $this->orderedIds();

            $featured->delete();

            if ($orderedIds !== []) {
                $this->writeOrderSequence(
                    HomeFeaturedProduct::class,
                    array_values(array_diff($orderedIds, [$featured->getKey()])),
                );
            }
        });
    }

    /**
     * The rows of the list in display order, the same order the home page reads.
     *
     * @return list<int>
     */
    private function orderedIds(): array
    {
        return HomeFeaturedProduct::query()
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
