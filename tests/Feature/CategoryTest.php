<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;

test('a category belongs to one section of the store', function () {
    $category = Category::factory()->section(StoreSection::Mujer)->create();

    expect($category->section)->toBe(StoreSection::Mujer)
        ->and($category->section->label())->toBe('Mujer')
        ->and(Category::inSection(StoreSection::Mujer)->pluck('id')->all())->toBe([$category->id])
        ->and(Category::inSection(StoreSection::Hombre)->count())->toBe(0);
});

test('a category can reuse the name of another one in a different section', function () {
    $hombre = Category::factory()->create(['name' => 'Polos', 'slug' => 'polos']);

    $mujer = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Polos', 'slug' => 'polos']);

    expect($mujer->is($hombre))->toBeFalse()
        ->and($mujer->name)->toBe('Polos');
});

test('a category cannot reuse the name or the slug of another one in the same section', function (string $column) {
    $category = Category::factory()->create();

    $this->expectException(QueryException::class);

    Category::factory()->create([
        'section' => $category->section,
        $column => $category->{$column},
        'sku_prefix' => 'ZZZ',
    ]);
})->with(['name', 'slug']);

test('a category cannot reuse the sku prefix of another one anywhere in the store', function () {
    $mujer = Category::factory()->section(StoreSection::Mujer)->create(['sku_prefix' => 'PLH']);

    $this->expectException(QueryException::class);

    Category::factory()->create(['sku_prefix' => 'PLH']);
});

test('a category is active and the panel appends it at the end of its section', function () {
    $primera = Category::factory()->create();
    $segunda = Category::factory()->create(['order' => Category::nextOrderFor(StoreSection::Hombre)]);

    expect($segunda->fresh()->order)->toBe(1)
        ->and($segunda->fresh()->is_active)->toBeTrue()
        ->and(Category::nextOrderFor(StoreSection::Hombre))->toBe(2)
        ->and(Category::nextOrderFor(StoreSection::Mujer))->toBe(0)
        ->and($primera->fresh()->order)->toBe(0);
});

test('a category with a product cannot be deleted', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    expect(fn () => $category->delete())->toThrow(QueryException::class);

    expect($category->exists)->toBeTrue()
        ->and($product->exists)->toBeTrue();
});
