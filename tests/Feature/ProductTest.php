<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

test('a product belongs to a category and owns its variants and images', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $image = ProductImage::factory()->for($product)->create();

    expect($product->category->is($category))->toBeTrue()
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants->first()->is($variant))->toBeTrue()
        ->and($product->images()->count())->toBe(1)
        ->and($product->images->first()->is($image))->toBeTrue();
});

test('a product cannot reuse the slug of another product', function () {
    $product = Product::factory()->create();

    $this->expectException(QueryException::class);

    Product::factory()->create(['slug' => $product->slug]);
});

test('a product defaults to the Feigler brand and the active status', function () {
    $product = Product::factory()->create()->refresh();

    expect($product->brand)->toBe('Feigler')
        ->and($product->status)->toBe('active');
});
