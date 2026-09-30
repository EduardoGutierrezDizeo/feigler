<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;

test('a category nests its subcategories one level deep', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->for($parent, 'parent')->create();

    expect($child->parent->is($parent))->toBeTrue()
        ->and($parent->children()->count())->toBe(1)
        ->and($parent->children->first()->is($child))->toBeTrue();
});

test('a category cannot reuse the slug of another category', function () {
    $category = Category::factory()->create();

    $this->expectException(QueryException::class);

    Category::factory()->create(['slug' => $category->slug]);
});

test('a category is active and parentless until it says otherwise', function () {
    $category = Category::factory()->create()->refresh();

    expect($category->order)->toBe(0)
        ->and($category->is_active)->toBeTrue()
        ->and($category->parent_id)->toBeNull();
});

test('a category with a product cannot be deleted', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    expect(fn () => $category->delete())->toThrow(QueryException::class);

    expect($category->exists)->toBeTrue()
        ->and($product->exists)->toBeTrue();
});
