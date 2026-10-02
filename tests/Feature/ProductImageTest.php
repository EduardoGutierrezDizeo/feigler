<?php

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;

test('an image is either general for the product or tied to one color', function () {
    $product = Product::factory()->create();
    $color = Color::factory()->create();

    $general = ProductImage::factory()->for($product)->create()->refresh();
    $specific = ProductImage::factory()->for($product)->for($color, 'color')->create()->refresh();

    expect($general->color_id)->toBeNull()
        ->and($general->order)->toBe(0)
        ->and($specific->color_id)->toEqual($color->id)
        ->and($specific->product->is($product))->toBeTrue()
        ->and($specific->color->is($color))->toBeTrue();
});
