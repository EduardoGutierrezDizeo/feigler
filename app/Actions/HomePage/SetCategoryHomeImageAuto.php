<?php

namespace App\Actions\HomePage;

use App\Actions\HomePage\Concerns\ReplacesCategoryHomeFiles;
use App\Enums\HomeImageSource;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Put a category back on the automatic home photo rule.
 *
 * The automatic rule is how every category was born: the home page shows the
 * thumbnail of the most recent visible product of the category. A decision to
 * stop using a custom photo is a decision to forget it, so this action also
 * clears the columns that keep the custom photo (re-reading them as they are
 * stored, not as the object the caller may have edited) and erases its files.
 *
 * The files are erased after the transaction is confirmed, so a rollback of the
 * change keeps them exactly where they were. See the trait that owns the
 * erasure for why the timing is the point.
 */
class SetCategoryHomeImageAuto
{
    use ReplacesCategoryHomeFiles;

    public function __invoke(Category $category): void
    {
        $paths = DB::transaction(function () use ($category): array {
            $fresh = $category->newQuery()->findOrFail($category->getKey());

            $paths = $this->categoryHomeFiles($fresh);

            $fresh->update([
                'home_image_source' => HomeImageSource::Auto,
                'home_image_path' => null,
                'home_image_thumbnail_path' => null,
                'home_image_product_image_id' => null,
            ]);

            return $paths;
        });

        $this->deleteCategoryHomeFiles($paths);
    }
}
