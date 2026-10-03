<?php

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Database\QueryException;

test('a variant belongs to its product and shows the gallery of its color', function () {
    $variant = ProductVariant::factory()->create();
    $image = ProductImage::factory()
        ->for($variant->product, 'product')
        ->for($variant->color, 'color')
        ->create();

    expect($image->product->is($variant->product))->toBeTrue()
        ->and($image->color->is($variant->color))->toBeTrue()
        ->and($variant->gallery()->count())->toBe(1);
});

test('a variant cannot reuse the sku of another variant', function () {
    $variant = ProductVariant::factory()->create();

    $this->expectException(QueryException::class);

    ProductVariant::factory()->create(['sku' => $variant->sku]);
});

test('a product cannot repeat the same size and color in two variants', function () {
    $product = Product::factory()->create();
    $color = Color::factory()->create();
    $size = Size::factory()->for($product->category)->create(['name' => 'M']);

    ProductVariant::factory()->for($product)->create(['size_id' => $size->id, 'color_id' => $color->id]);

    $this->expectException(QueryException::class);

    ProductVariant::factory()->for($product)->create(['size_id' => $size->id, 'color_id' => $color->id]);
});

test('a variant has no stock and stays active until it says otherwise', function () {
    $variant = ProductVariant::factory()->create()->refresh();

    expect($variant->stock)->toBe(0)
        ->and($variant->is_active)->toBeTrue()
        ->and($variant->price_override)->toBeNull();
});
