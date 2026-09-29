<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

test('an image is either general for the product or tied to one variant', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $general = ProductImage::factory()->for($product)->create()->refresh();
    $specific = ProductImage::factory()->for($product)->for($variant)->create()->refresh();

    expect($general->product_variant_id)->toBeNull()
        ->and($general->order)->toBe(0)
        ->and($specific->product_variant_id)->toEqual($variant->id)
        ->and($specific->product->is($product))->toBeTrue()
        ->and($specific->productVariant->is($variant))->toBeTrue();
});
