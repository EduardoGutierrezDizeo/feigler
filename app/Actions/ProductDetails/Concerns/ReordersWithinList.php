<?php

namespace App\Actions\ProductDetails\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moving a row up or down inside a list it is read in.
 *
 * This is the counterpart of `Category::moveWithinSection()`, generalized: the list is
 * the one the caller passes in, so the same reordering works for the sizes of a
 * category and for the colors and the materials of the store without each of them
 * carrying its own copy of the arithmetic.
 *
 * Reindexing instead of swapping is what makes the move happen. Swapping only worked
 * while the `order` values were unique, and two rows sharing a value turned the swap
 * into a no-op that still ran its UPDATEs. Rebuilding the sequence also heals a list
 * that is already corrupted.
 *
 * The offset is clamped to the bounds of the list, so calling this at either end is a
 * no-op rather than an error.
 */
trait ReordersWithinList
{
    /**
     * Move a row one slot up (-1) or down (+1) among `$orderedIds`, and write the
     * result as `order` = 0, 1, 2... n-1.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $orderedIds  The ids of the list, in the order it is read.
     */
    private function moveWithin(string $modelClass, array $orderedIds, Model $row, int $offset): void
    {
        DB::transaction(function () use ($modelClass, $orderedIds, $row, $offset): void {
            $currentIndex = array_search($row->getKey(), $orderedIds, true);

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = max(0, min($currentIndex + $offset, count($orderedIds) - 1));

            array_splice($orderedIds, $currentIndex, 1);
            array_splice($orderedIds, $targetIndex, 0, [$row->getKey()]);

            $this->writeOrderSequence($modelClass, $orderedIds);
        });
    }

    /**
     * Write `order` = 0, 1, 2... n-1 for an ordered list of ids of the given model.
     *
     * Rows already sitting at their target value are skipped, so a single move only
     * touches the rows that really changed instead of bumping `updated_at` across the
     * whole list.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $orderedIds
     */
    private function writeOrderSequence(string $modelClass, array $orderedIds): void
    {
        $currentOrders = $modelClass::query()
            ->whereIn('id', $orderedIds)
            ->pluck('order', 'id');

        foreach ($orderedIds as $position => $id) {
            if (($currentOrders[$id] ?? null) === $position) {
                continue;
            }

            $modelClass::query()->whereKey($id)->update(['order' => $position]);
        }
    }
}
