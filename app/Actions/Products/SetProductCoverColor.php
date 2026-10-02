<?php

namespace App\Actions\Products;

use App\Exceptions\ProductCoverColorWithoutImagesException;
use App\Models\Color;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class SetProductCoverColor
{
    /**
     * Say which color stands for the product in the catalog.
     *
     * The cover is the color whose main picture is the picture of the product, so
     * the color has to have at least one picture: choosing an empty one would leave
     * the product with no cover at all. Passing null takes the cover away without
     * touching the pictures, and the product then falls back to the main image of
     * whatever color has images.
     */
    public function __invoke(Product $product, ?Color $color): Product
    {
        return DB::transaction(function () use ($product, $color): Product {
            $fresh = $product->newQuery()->lockForUpdate()->findOrFail($product->getKey());

            if ($color !== null && ! $fresh->imagesForColor($color)->exists()) {
                throw ProductCoverColorWithoutImagesException::forProduct($product, $color);
            }

            $fresh->update(['cover_color_id' => $color?->getKey()]);

            return $fresh;
        });
    }
}
