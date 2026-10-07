<?php

namespace App\Actions\HomePage\Concerns;

use App\Models\Category;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * The files a category keeps for its own home photo, and how to erase them.
 *
 * The uploaded picture of a category (its original and, when it exists, its
 * thumbnail) is data the category owns: a decision that stops using it has to
 * leave the disk looking as if it had never been stored, and a category that is
 * deleted cannot leave its photo behind either.
 *
 * The deletion happens once the database change that stopped using the files is
 * confirmed, never before. The two patterns that consume this, replacing a photo
 * and deleting a category, both capture the paths inside the transaction and
 * erase the files after it commits, so a rollback leaves the old files exactly
 * where they were.
 */
trait ReplacesCategoryHomeFiles
{
    /**
     * The original and the thumbnail a category stores for its own home photo.
     *
     * The thumbnail is created alongside the upload and a file can be missing
     * when the thumbnailer could not run, so the list filters the nulls out.
     *
     * @return list<string>
     */
    private function categoryHomeFiles(Category $category): array
    {
        return array_filter([$category->home_image_path, $category->home_image_thumbnail_path]);
    }

    /**
     * Erase category home files from the disk they live on.
     *
     * @param  list<string>  $paths
     */
    private function deleteCategoryHomeFiles(array $paths): void
    {
        $disk = Storage::disk(ProductImage::DISK);

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }
}
