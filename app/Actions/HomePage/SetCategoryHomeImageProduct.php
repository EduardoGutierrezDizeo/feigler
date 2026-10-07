<?php

namespace App\Actions\HomePage;

use App\Actions\HomePage\Concerns\ReplacesCategoryHomeFiles;
use App\Enums\HomeImageSource;
use App\Exceptions\HomeImageProductException;
use App\Models\Category;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Show the photo of one of its products as the home picture of a category.
 *
 * The two fairness rules are the point: the photo has to belong to a product of
 * that very category, and that product has to be one the storefront shows. A
 * photo that is not of the category would put somebody else's garment on it,
 * and a photo of a product nobody can open is a link to nothing, so both are
 * refused before anything is stored.
 *
 * Replacing a previous custom photo erases its files only once the change is
 * confirmed, so a rollback leaves them exactly where they were.
 */
class SetCategoryHomeImageProduct
{
    use ReplacesCategoryHomeFiles;

    public function __invoke(Category $category, ProductImage $image): void
    {
        $product = $image->product;

        if ($product === null || (int) $product->category_id !== (int) $category->getKey()) {
            throw HomeImageProductException::notInCategory($category, $image);
        }

        $isVisible = $product->newQuery()
            ->visible()
            ->whereKey($product->getKey())
            ->exists();

        if (! $isVisible) {
            throw HomeImageProductException::notVisible($product);
        }

        $paths = DB::transaction(function () use ($category, $image): array {
            $fresh = $category->newQuery()->findOrFail($category->getKey());

            $paths = $this->categoryHomeFiles($fresh);

            $fresh->update([
                'home_image_source' => HomeImageSource::Product,
                'home_image_path' => null,
                'home_image_thumbnail_path' => null,
                'home_image_product_image_id' => $image->getKey(),
            ]);

            return $paths;
        });

        $this->deleteCategoryHomeFiles($paths);
    }
}
