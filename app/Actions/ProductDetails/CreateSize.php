<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\DuplicateSizeNameException;
use App\Models\Category;
use App\Models\Size;
use App\Support\Concerns\NormalizesNames;

/**
 * Add a size to what a category sells garments in.
 *
 * A size belongs to a category instead of to the store, so the name only has to be
 * free inside its own category: two categories can both sell `M`, and that is not a
 * duplicate, it is trousers and shirts.
 *
 * The position is not chosen here. A new size goes to the end of the list of its
 * category, which is what `nextOrderInCategory()` settles, so the store reorders the
 * list on purpose instead of getting a size dropped in the middle of it.
 */
class CreateSize
{
    use NormalizesNames;

    /**
     * Write a size at the end of the list of the category, active.
     *
     * The name comes out of `cleanName()`, so the same name written with different
     * spaces is stored the same and reads back the same as it is stored.
     */
    public function __invoke(Category $category, string $name): Size
    {
        $name = $this->cleanName($name);

        $this->guardNameIsFree($category, $name);

        return Size::query()->create([
            'category_id' => $category->getKey(),
            'name' => $name,
            'order' => Size::nextOrderInCategory((int) $category->getKey()),
            'is_active' => true,
        ]);
    }

    /**
     * Refuse a name this category already carries.
     *
     * The comparison is by key and not by the raw name, so `única` is refused against
     * `ÚNICA` on every driver instead of only on MySQL.
     */
    private function guardNameIsFree(Category $category, string $name): void
    {
        if ($this->nameIsTaken($category->sizes()->pluck('name'), $name)) {
            throw DuplicateSizeNameException::forName($category, $name);
        }
    }
}
