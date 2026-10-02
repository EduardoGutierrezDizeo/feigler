<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductVariant;

class ToggleProductVariant
{
    /**
     * Take a variant in and out of the catalog.
     *
     * Nothing else is written: deactivating is how a variant that was sold stays
     * in the orders it was sold in and leaves the catalog, which is the only way
     * out of a variant with history. The stock is left alone on purpose, so a
     * variant that comes back does not lose the units it had.
     *
     * The variant is resolved through the product so an id belonging to another
     * product is not found here at all.
     *
     * There is no transaction around it because there is a single UPDATE behind
     * it, and a statement is already all or nothing.
     */
    public function __invoke(Product $product, int $variantId): ProductVariant
    {
        $variant = $product->variants()->findOrFail($variantId);

        $variant->update(['is_active' => ! $variant->is_active]);

        return $variant;
    }
}
