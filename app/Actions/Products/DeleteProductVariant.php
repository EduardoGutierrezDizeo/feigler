<?php

namespace App\Actions\Products;

use App\Exceptions\ProductVariantNotDeletableException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeleteProductVariant
{
    /**
     * Remove a variant nobody has touched since it was created.
     *
     * The foreign keys of order_items and inventory_movements both restrict the
     * delete of a variant, so the row can only go away when neither table points
     * at it. That is what the checks below are for, except that they also keep a
     * variant with history from being deleted by accident: deactivating is what
     * takes a variant out of the catalog without erasing what it took part in.
     *
     * The movements are deleted before the variant, not the other way around.
     * Restricting the delete of a variant means the database refuses to delete a
     * variant a movement still points at, so the movement has to be gone first.
     */
    public function __invoke(Product $product, int $variantId): void
    {
        DB::transaction(function () use ($product, $variantId): void {
            $variant = $product->variants()->lockForUpdate()->findOrFail($variantId);

            if ($variant->orderItems()->exists()) {
                throw ProductVariantNotDeletableException::sold($variant);
            }

            $this->guardNothingHappenedToIt($variant, $variant->inventoryMovements()->orderBy('id')->get());

            $variant->inventoryMovements()->delete();

            $variant->delete();
        });
    }

    /**
     * Refuse a variant whose stock is not still the one it was created with.
     *
     * A variant with no movements was created with no stock, so its stock has to
     * be zero. A variant with a single movement is only untouched if that
     * movement is the one it was born with: an entry, no order behind it, the
     * note of the initial stock, and an amount that still matches what is on
     * hand. Any other movement, or one more of them, means the stock moved.
     *
     * @param  Collection<int, InventoryMovement>  $movements
     */
    private function guardNothingHappenedToIt(ProductVariant $variant, Collection $movements): void
    {
        if ($movements->isEmpty()) {
            if ($variant->stock !== 0) {
                throw ProductVariantNotDeletableException::adjusted($variant);
            }

            return;
        }

        if ($movements->count() > 1) {
            throw ProductVariantNotDeletableException::adjusted($variant);
        }

        $movement = $movements->first();

        $isInitialStock = $movement->type === CreateProductVariant::INITIAL_STOCK_TYPE
            && $movement->order_id === null
            && $movement->note === CreateProductVariant::INITIAL_STOCK_REASON
            && $variant->stock === $movement->quantity;

        if (! $isInitialStock) {
            throw ProductVariantNotDeletableException::otherMovements($variant);
        }
    }
}
