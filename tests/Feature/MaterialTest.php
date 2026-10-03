<?php

use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a product carries the materials it is made of with the share of each one', function () {
    $cotton = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $elastane = Material::factory()->create(['name' => 'Elastano', 'order' => 2]);
    $product = Product::factory()->create();

    $product->materials()->attach([$cotton->id => ['percentage' => 95], $elastane->id => ['percentage' => 5]]);

    expect($product->materials()->pluck('name')->all())->toBe(['Algodón', 'Elastano'])
        ->and($product->materials()->get()->pluck('pivot.percentage', 'name')->all())->toBe([
            'Algodón' => 95,
            'Elastano' => 5,
        ]);
});

test('a product carries its materials in the order of the material, not of the pivot', function () {
    $linen = Material::factory()->create(['name' => 'Lino', 'order' => 1]);
    $denim = Material::factory()->create(['name' => 'Mezclilla', 'order' => 2]);
    $product = Product::factory()->create();

    // Attached the other way around on purpose: the pivot has no order of its own.
    $product->materials()->attach([$denim->id => ['percentage' => 100], $linen->id => ['percentage' => 100]]);

    expect($product->materials()->pluck('name')->all())->toBe(['Lino', 'Mezclilla']);
});

test('a product cannot carry the same material twice', function () {
    $product = Product::factory()->create();
    $material = Material::factory()->create();
    $product->materials()->attach($material->id, ['percentage' => 100]);

    $this->expectException(QueryException::class);

    $product->materials()->attach($material->id, ['percentage' => 100]);
});

test('a material cannot be deleted while a product is made of it', function () {
    $material = Material::factory()->create();
    Product::factory()->create()->materials()->attach($material->id, ['percentage' => 100]);

    $this->expectException(QueryException::class);

    $material->delete();
});

test('deleting a product takes its materials with it and leaves the materials', function () {
    $material = Material::factory()->create();
    $product = Product::factory()->create();
    $product->materials()->attach($material->id, ['percentage' => 100]);

    $product->delete();

    expect(DB::table('material_product')->where('material_id', $material->id)->exists())->toBeFalse()
        ->and($material->exists())->toBeTrue();
});

test('a material that was turned off is left out of the active scope', function () {
    $offered = Material::factory()->create(['name' => 'Algodón']);
    $retired = Material::factory()->inactive()->create(['name' => 'Elastano']);

    expect($offered->is_active)->toBeTrue()
        ->and($retired->is_active)->toBeFalse()
        ->and(Material::active()->pluck('name')->all())->toBe(['Algodón']);
});
