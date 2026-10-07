<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\CategoryInUseException;
use App\Models\Category;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a category that is not selling anything, taking its sizes with it.
 *
 * The sizes of a category were rows added by the migration that moved them out of a
 * constant, and they hang off the category with a foreign key that restricts the
 * delete: from the moment every category carries its eight sizes, no category can be
 * deleted at all, not even one that was created minutes ago and sells nothing. The
 * sizes are deleted first so the category can go, and only when there is nothing in
 * the way.
 *
 * The rule that used to hold is not weakened. A category with products is still
 * refused with the same message and without touching its sizes, because the products
 * point at the category and moving them elsewhere is a decision for the admin, not
 * something that happens as a side effect of deleting.
 */
class DeleteCategory
{
    /**
     * Delete the category and the sizes that were its own.
     *
     * The two deletions are one unit of work: a category that is refused has to keep
     * its sizes, and a category whose sizes are not gone would leave rows pointing at
     * a category that no longer exists.
     *
     * A category also owns the files of its home photo, and it takes them with it:
     * the paths are captured inside the same unit of work and erased only once it is
     * confirmed, so a rollback leaves both the row and the files exactly where they
     * were, and a category that is gone leaves no orphan pictures on the disk.
     */
    public function __invoke(Category $category): void
    {
        $paths = DB::transaction(function () use ($category): array {
            $fresh = $category->newQuery()->findOrFail($category->getKey());

            $this->guardCategoryIsEmpty($fresh);

            // The order and the id are the columns this relation reads, and neither is
            // needed to delete, but the relation is the one that knows which rows
            // belong to this category.
            $fresh->sizes()->reorder()->delete();

            $paths = array_filter([$fresh->home_image_path, $fresh->home_image_thumbnail_path]);

            $fresh->delete();

            return $paths;
        });

        $disk = Storage::disk(ProductImage::DISK);

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }

    /**
     * Refuse a category that still has products in it.
     *
     * The foreign key of `products.category_id` restricts the delete as well, so the
     * row could not be removed even without this check; it is here so the admin is
     * told what is in the way instead of reading a driver error about a foreign key.
     */
    private function guardCategoryIsEmpty(Category $category): void
    {
        if ($category->products()->exists()) {
            throw CategoryInUseException::forProducts($category);
        }
    }
}
