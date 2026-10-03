<?php

namespace App\Actions\Products;

use App\Actions\Products\Concerns\GuardsVariantColor;
use App\Actions\Products\Concerns\GuardsVariantSize;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

class UpdateProductVariant
{
    use GuardsVariantColor;
    use GuardsVariantSize;

    /**
     * Change the size, the color and the price of a variant.
     *
     * The variant is resolved through the product so an id belonging to another
     * product is not found here at all, instead of being edited by accident.
     *
     * The SKU is not among the columns written, and neither is the stock: the SKU
     * is printed on labels and lives in the orders that were paid for, so it
     * belongs to the variant forever, and the stock only ever moves through a
     * movement.
     */
    public function __invoke(
        Product $product,
        int $variantId,
        int $sizeId,
        Color $color,
        ?string $price = null,
    ): ProductVariant {
        return DB::transaction(function () use ($product, $variantId, $sizeId, $color, $price): ProductVariant {
            $variant = $product->variants()->lockForUpdate()->findOrFail($variantId);
            $size = Size::query()->findOrFail($sizeId);

            $this->guardSizeIsSettable($product, $size, $variant);
            $this->guardColorIsSettable($color, $variant);
            $this->guardCombinationIsFree($product, $size, $color, $variant);

            $variant->update([
                'size_id' => $size->getKey(),
                'color_id' => $color->getKey(),
                'price_override' => $price,
            ]);

            return $variant;
        });
    }
}
