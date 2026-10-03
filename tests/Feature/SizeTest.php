<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Database\QueryException;

test('a size belongs to its category and a category lists its sizes in order', function () {
    $category = Category::factory()->create();
    $shirt = Size::factory()->for($category)->create(['name' => 'M', 'order' => 2]);
    $trousers = Size::factory()->for($category)->create(['name' => '42', 'order' => 1]);

    expect($shirt->category->is($category))->toBeTrue()
        ->and($category->sizes()->pluck('name')->all())->toBe(['42', 'M'])
        ->and($trousers->category->is($category))->toBeTrue();
});

test('the same size name can be repeated in another category but not in the same one', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();

    Size::factory()->for($category)->create(['name' => 'M']);
    Size::factory()->for($other)->create(['name' => 'M']);

    expect(Size::where('name', 'M')->count())->toBe(2);

    $this->expectException(QueryException::class);

    Size::factory()->for($category)->create(['name' => 'M']);
});

test('a variant points at the size of its category', function () {
    $product = Product::factory()->create();
    $size = Size::factory()->for($product->category)->create(['name' => 'M']);

    $variant = ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect($variant->refresh()->size->is($size))->toBeTrue()
        ->and($variant->size->category_id)->toBe($product->category_id);
});

test('a variant is always sold in a size of the category of its product', function () {
    $variant = ProductVariant::factory()->create();

    expect($variant->refresh()->size)->not->toBeNull()
        ->and($variant->size->category_id)->toBe($variant->product->category_id);
});

test('a variant cannot be written without a size', function () {
    $product = Product::factory()->create();

    $this->expectException(QueryException::class);

    $product->variants()->create(['color_id' => Color::factory()->create()->id]);
});

test('a size cannot be deleted while a variant is sold in it', function () {
    $product = Product::factory()->create();
    $size = Size::factory()->for($product->category)->create(['name' => 'M']);
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    $this->expectException(QueryException::class);

    $size->delete();
});

test('a size cannot be deleted while a variant of another product is sold in it', function () {
    $size = Size::factory()->for(Category::factory())->create(['name' => 'M']);
    $variant = ProductVariant::factory()->for(Product::factory())->create([
        'size_id' => $size->id,
        'color_id' => Color::factory()->create()->id,
    ]);

    expect($size->variants()->whereKey($variant->id)->exists())->toBeTrue();

    $this->expectException(QueryException::class);

    $size->delete();
});

test('a size that was turned off is left out of the active scope', function () {
    $category = Category::factory()->create();
    $offered = Size::factory()->for($category)->create(['name' => 'M']);
    $retired = Size::factory()->for($category)->inactive()->create(['name' => 'L']);

    expect($offered->is_active)->toBeTrue()
        ->and($retired->is_active)->toBeFalse()
        ->and(Size::active()->pluck('name')->all())->toBe(['M']);
});
