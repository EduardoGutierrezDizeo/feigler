<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

class SetPrimaryProductImage
{
    /**
     * Make one picture the main one of its color.
     *
     * The main picture is what the storefront shows first for that color, so a
     * color has exactly one of them: the others are unmarked in the same
     * transaction, otherwise a color could end up with two main pictures and the
     * order they are shown in would be decided by the database.
     *
     * Only the images of the same color are touched. The main pictures of the other
     * colors of the product say nothing about this one and are left as they are.
     *
     * The row of the product is locked for the whole transaction, so two admins
     * choosing a main picture at the same time cannot both leave one marked.
     */
    public function __invoke(Product $product, int $imageId): ProductImage
    {
        return DB::transaction(function () use ($product, $imageId): ProductImage {
            $fresh = $product->newQuery()->lockForUpdate()->findOrFail($product->getKey());

            $image = $fresh->images()->findOrFail($imageId);

            $fresh->images()
                ->where('color_id', $image->color_id)
                ->whereKeyNot($image->getKey())
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            return $image->refresh();
        });
    }
}
