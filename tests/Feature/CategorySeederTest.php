<?php

use App\Models\Category;
use Database\Seeders\CategorySeeder;

test('category seeder creates the four initial categories', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(4)
        ->and(Category::orderBy('order')->pluck('slug')->all())->toBe([
            'camisetas',
            'polos',
            'jeans',
            'gorras',
        ])
        ->and(Category::whereNotNull('parent_id')->count())->toBe(0);
});

test('category seeder runs twice without duplicating categories', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(4);
});
