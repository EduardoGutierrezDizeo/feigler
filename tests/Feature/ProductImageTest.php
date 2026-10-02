<?php

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;

test('an image belongs to a product and shows one color', function () {
    $product = Product::factory()->create();
    $color = Color::factory()->create();

    $image = ProductImage::factory()->for($product)->for($color, 'color')->create()->refresh();

    expect($image->color_id)->toEqual($color->id)
        ->and($image->order)->toBe(0)
        ->and($image->is_primary)->toBeFalse()
        ->and($image->product->is($product))->toBeTrue()
        ->and($image->color->is($color))->toBeTrue();
});

test('an image cannot be stored without a color', function () {
    $product = Product::factory()->create();

    expect(fn () => ProductImage::factory()->for($product)->create(['color_id' => null]))
        ->toThrow(QueryException::class);
});
