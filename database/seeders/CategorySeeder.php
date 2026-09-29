<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Register the initial catalog categories.
     */
    public function run(): void
    {
        $categories = ['Camisetas', 'Polos', 'Jeans', 'Gorras'];

        foreach ($categories as $index => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'order' => $index],
            );
        }
    }
}
