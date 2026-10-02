<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteProductImage
{
    /**
     * Take a picture out of a color of a product.
     *
     * A color is never left without a main picture: when the one that was removed
     * held that place, the first of the ones left takes it over, which is the one
     * with the lowest order, the one uploaded right after it. When there is no one
     * left, the color has no gallery to speak of, and if it was the cover color of
     * the product the cover is dropped with it, so the product never points at a
     * color with no picture behind it and `cover_image` is free to fall back to
     * another color.
     *
     * The file is erased once the transaction is confirmed, and not before: the
     * delete of the row is what decides whether the picture goes away for good, and
     * a transaction that rolls back has to leave the file exactly where it was.
     *
     * The row of the product is locked for the whole transaction because two rows
     * are read and written here, the image and the cover color, and both answers
     * have to come from the same moment.
     */
    public function __invoke(Product $product, int $imageId): void
    {
        $path = DB::transaction(function () use ($product, $imageId): string {
            $fresh = $product->newQuery()->lockForUpdate()->findOrFail($product->getKey());

            $image = $fresh->images()->findOrFail($imageId);

            $colorId = $image->color_id;
            $wasPrimary = $image->is_primary;

            $left = $fresh->images()
                ->where('color_id', $colorId)
                ->whereKeyNot($image->getKey())
                ->orderBy('order')
                ->orderBy('id')
                ->get();

            $image->delete();

            if ($wasPrimary) {
                $left->first()?->update(['is_primary' => true]);
            }

            if ($left->isEmpty() && (int) $fresh->cover_color_id === (int) $colorId) {
                $fresh->update(['cover_color_id' => null]);
            }

            return $image->path;
        });

        Storage::disk(ProductImage::DISK)->delete($path);
    }
}
