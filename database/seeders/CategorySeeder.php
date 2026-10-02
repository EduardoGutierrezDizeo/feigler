<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * The initial catalog categories, each with the SKU prefix its products are
     * numbered with.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'Camisetas' => 'CM',
        'Polos' => 'PL',
        'Jeans' => 'JN',
        'Gorras' => 'GR',
    ];

    /**
     * Register the initial catalog categories.
     */
    public function run(): void
    {
        foreach (array_keys(self::CATEGORIES) as $index => $name) {
            $category = Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'order' => $index],
            );

            $this->assignSkuPrefix($category, self::CATEGORIES[$name]);
        }
    }

    /**
     * Give the category its prefix, leaving it alone when it already has one.
     *
     * A prefix that another category already owns is skipped rather than stolen or
     * duplicated, so running the seeder over a catalog somebody has been editing by
     * hand can never quietly renumber their products.
     */
    private function assignSkuPrefix(Category $category, string $skuPrefix): void
    {
        if ($category->sku_prefix !== null) {
            return;
        }

        $takenByOther = Category::query()
            ->where('sku_prefix', $skuPrefix)
            ->where('id', '!=', $category->getKey())
            ->exists();

        if ($takenByOther) {
            return;
        }

        $category->forceFill(['sku_prefix' => $skuPrefix])->save();
    }
}
