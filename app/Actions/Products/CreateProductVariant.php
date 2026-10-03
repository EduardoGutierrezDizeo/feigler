<?php

namespace App\Actions\Products;

use App\Actions\Products\Concerns\GuardsVariantColor;
use App\Actions\Products\Concerns\GuardsVariantSize;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateProductVariant
{
    use GuardsVariantColor;
    use GuardsVariantSize;

    /**
     * The note that tells the movement a variant is born with apart from the ones
     * an admin records later on.
     *
     * It is also what tells a variant that was never touched from one whose
     * history cannot be erased, so the two have to agree.
     */
    public const INITIAL_STOCK_REASON = 'Stock inicial';

    /**
     * The movement type the initial stock is recorded as.
     *
     * The enum of movement types has no type of its own for the stock a variant
     * is created with, and an entry is exactly what that stock is.
     */
    public const INITIAL_STOCK_TYPE = 'ajuste_entrada';

    /**
     * Add a size/color combination to a product.
     *
     * The variant is born with no stock and is then given the initial stock
     * through a movement, so every unit the store holds is explained by a row in
     * inventory_movements from the moment it exists. It is also what lets a later
     * deletion tell a variant nobody ever touched from one whose stock moved.
     *
     * A price left empty is stored as null, which is how a variant says it sells
     * at the price of its product.
     *
     * The variant comes back active. It is written down instead of left to the
     * default of the column so the object handed to the caller says the same as the
     * row behind it.
     */
    public function __invoke(
        Product $product,
        int $sizeId,
        Color $color,
        ?string $price = null,
        int $stock = 0,
        ?User $user = null,
    ): ProductVariant {
        if ($stock < 0) {
            throw InvalidStockAdjustmentException::negativeStock();
        }

        return DB::transaction(function () use ($product, $sizeId, $color, $price, $stock, $user): ProductVariant {
            $size = Size::query()->findOrFail($sizeId);

            $this->guardSizeIsSettable($product, $size);
            $this->guardColorIsSettable($color);
            $this->guardCombinationIsFree($product, $size, $color);

            $variant = $product->variants()->create([
                'size_id' => $size->getKey(),
                'color_id' => $color->getKey(),
                'sku' => $this->freeSkuFor($product, $size, $color),
                'stock' => 0,
                'price_override' => $price,
                'is_active' => true,
            ]);

            if ($stock > 0) {
                $variant->recordStockChange(
                    self::INITIAL_STOCK_TYPE,
                    $stock,
                    $user?->getKey(),
                    self::INITIAL_STOCK_REASON,
                );
            }

            return $variant;
        });
    }

    /**
     * The SKU of this size/color combination, free to be used.
     *
     * The SKU is built from the reference of the product, and the reference is
     * unique, so the base SKU is free unless it was already taken by a variant
     * that is no longer around. A number is appended from a 2 until the SKU is
     * free, the way the reference numbering does it.
     *
     * The search is over every variant and not only the ones of this product,
     * because the SKU column is unique in the whole store.
     */
    private function freeSkuFor(Product $product, Size $size, Color $color): string
    {
        $base = ProductVariant::makeSku($product, $size, $color);
        $sku = $base;
        $attempt = 2;

        while (ProductVariant::query()->where('sku', $sku)->exists()) {
            $sku = "{$base}-{$attempt}";
            $attempt++;
        }

        return $sku;
    }
}
