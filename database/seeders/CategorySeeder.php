<?php

namespace Database\Seeders;

use App\Enums\StoreSection;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * The initial catalog categories of the men's section, each with the SKU prefix
     * its products are numbered with.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'Camisetas' => 'CMH',
        'Polos' => 'PLH',
        'Jeans' => 'JNH',
        'Gorras' => 'GRH',
    ];

    /**
     * Register the initial catalog categories.
     *
     * `firstOrCreate()` leaves an existing row completely alone, prefix included:
     * running the seeder over a catalog somebody has been editing by hand can
     * never quietly renumber their products.
     */
    public function run(): void
    {
        foreach (array_keys(self::CATEGORIES) as $index => $name) {
            $slug = Str::slug($name);

            Category::firstOrCreate(
                [
                    'section' => StoreSection::Hombre,
                    'slug' => $slug,
                ],
                [
                    'name' => $name,
                    'order' => $index,
                    'sku_prefix' => self::CATEGORIES[$name],
                ],
            );
        }
    }
}
