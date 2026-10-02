<?php

use App\Enums\StoreSection;
use App\Models\Category;
use Database\Seeders\CategorySeeder;

test('category seeder creates the four initial categories in the men section', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(4)
        ->and(Category::orderBy('order')->pluck('slug')->all())->toBe([
            'camisetas',
            'polos',
            'jeans',
            'gorras',
        ])
        ->and(Category::pluck('section')->unique()->all())->toBe([StoreSection::Hombre])
        ->and(Category::orderBy('order')->pluck('sku_prefix')->all())->toBe(['CMH', 'PLH', 'JNH', 'GRH']);
});

test('category seeder runs twice without duplicating categories', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(4);
});

test('category seeder does not take a prefix that another category already owns', function () {
    Category::factory()->create(['slug' => 'polos', 'sku_prefix' => 'OTRA']);

    $this->seed(CategorySeeder::class);

    // La fila existente se deja tal cual, con su prefijo: el seeder no renumera
    // los productos que ya se construyeron a partir de él.
    expect(Category::where('slug', 'polos')->sole()->sku_prefix)->toBe('OTRA')
        ->and(Category::count())->toBe(4);
});
