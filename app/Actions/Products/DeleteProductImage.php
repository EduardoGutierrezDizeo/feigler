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
     * The original and the thumbnail are erased once the transaction is
     * confirmed, and not before: the delete of the row is what decides whether
     * the picture goes away for good, and a transaction that rolls back has to
     * leave both files exactly where they were. A picture with no thumbnail
     * stored deletes nothing more than its original.
     *
     * The row of the product is locked for the whole transaction because two rows
     * are read and written here, the image and the cover color, and both answers
     * have to come from the same moment.
     */
    public function __invoke(Product $product, int $imageId): void
    {
        $paths = DB::transaction(function () use ($product, $imageId): array {
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

            $paths = array_filter([$image->path, $image->thumbnail_path]);

            $image->delete();

            if ($wasPrimary) {
                $left->first()?->update(['is_primary' => true]);
            }

            if ($left->isEmpty() && (int) $fresh->cover_color_id === (int) $colorId) {
                $fresh->update(['cover_color_id' => null]);
            }

            return $paths;
        });

        $disk = Storage::disk(ProductImage::DISK);

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }
}
