<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

test('a variant belongs to its product and owns its images', function () {
    $variant = ProductVariant::factory()->create();
    $image = ProductImage::factory()
        ->for($variant->product, 'product')
        ->for($variant, 'productVariant')
        ->create();

    expect($image->product->is($variant->product))->toBeTrue()
        ->and($image->productVariant->is($variant))->toBeTrue()
        ->and($variant->images()->count())->toBe(1);
});

test('a variant cannot reuse the sku of another variant', function () {
    $variant = ProductVariant::factory()->create();

    $this->expectException(QueryException::class);

    ProductVariant::factory()->create(['sku' => $variant->sku]);
});

test('a product cannot repeat the same size and color in two variants', function () {
    $product = Product::factory()->create();

    ProductVariant::factory()->for($product)->create(['size' => 'M', 'color' => 'red']);

    $this->expectException(QueryException::class);

    ProductVariant::factory()->for($product)->create(['size' => 'M', 'color' => 'red']);
});

test('a variant has no stock and stays active until it says otherwise', function () {
    $variant = ProductVariant::factory()->create()->refresh();

    expect($variant->stock)->toBe(0)
        ->and($variant->is_active)->toBeTrue()
        ->and($variant->price_override)->toBeNull();
});
