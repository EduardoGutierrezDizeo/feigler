<?php

use App\Actions\ProductDetails\SyncProductMaterials;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\MaterialNotFoundException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Models\Material;
use App\Models\Product;

test('a product is made of the materials of the composition given to it', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 60],
        ['id' => $poliester->id, 'percentage' => 40],
    ]);

    expect($product->fresh()->materials()->count())->toBe(2)
        ->and($product->materials()->pluck('materials.id')->all())->toBe([$algodon->id, $poliester->id]);
});

test('the percentages are stored as they came', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $poliester = Material::factory()->create(['name' => 'Poliéster', 'order' => 2]);
    $elastano = Material::factory()->create(['name' => 'Elastano', 'order' => 3]);

    (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 70],
        ['id' => $poliester->id, 'percentage' => 25],
        ['id' => $elastano->id, 'percentage' => 5],
    ]);

    expect($product->materials()->get()->map(fn ($material): int => (int) $material->pivot->percentage)->all())
        ->toBe([70, 25, 5]);
});

test('a composition of one material holds all of it', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    (new SyncProductMaterials)($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect($product->materials()->sole()->pivot->percentage)->toBe(100);
});

/**
 * The list is the whole composition, so a product is never left with the union of two
 * saves that each said what it wanted.
 */
test('a second sync replaces the previous composition', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $sync = new SyncProductMaterials;

    $sync($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => $poliester->id, 'percentage' => 50],
    ]);

    $sync($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect($product->materials()->count())->toBe(1)
        ->and($product->materials()->sole()->is($algodon))->toBeTrue();
});

test('an empty composition leaves the product made of nothing', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $sync = new SyncProductMaterials;

    $sync($product, [['id' => $algodon->id, 'percentage' => 100]]);
    $sync($product, []);

    expect($product->materials()->count())->toBe(0);
});

test('a product with no composition at all is left alone', function () {
    $product = Product::factory()->create();

    (new SyncProductMaterials)($product, []);

    expect($product->materials()->count())->toBe(0);
});

test('the materials of the product come back in the order of the catalog', function () {
    $product = Product::factory()->create();
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 1]);
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 2]);

    (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => $lino->id, 'percentage' => 50],
    ]);

    expect($product->materials()->pluck('name')->all())->toBe(['Lino', 'Algodón']);
});

test('the same material listed twice is refused', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => $algodon->id, 'percentage' => 50],
    ]))->toThrow(RepeatedProductMaterialException::class);
});

test('the refusal names the repeated material', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => $algodon->id, 'percentage' => 50],
    ]))->toThrow(RepeatedProductMaterialException::class, 'El material «Algodón» está repetido');
});

test('the same material given as a string is still the same material', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => (string) $algodon->id, 'percentage' => 50],
    ]))->toThrow(RepeatedProductMaterialException::class);
});

test('a percentage below one or above a hundred is refused', function (int $percentage) {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => $percentage],
    ]))->toThrow(InvalidMaterialPercentageException::class);
})->with([0, -10, 101, 1000]);

test('the refusal names the material and the percentage that came', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 0],
    ]))->toThrow(
        InvalidMaterialPercentageException::class,
        'El porcentaje del material «Algodón» debe ser un número entero entre 1 y 100; se ha recibido 0.'
    );
});

/**
 * The panel sends the percentage as it was typed, so a decimal there means the form and
 * the action want different things, and rounding it would save a composition nobody
 * asked for.
 */
test('a percentage that is not a whole number is refused', function (int|float|string $percentage) {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => $percentage],
    ]))->toThrow(InvalidMaterialPercentageException::class);
})->with([50.5, '50.5', '50,5', '50 %', 'mucho']);

test('the refusal says the percentage that came as a decimal', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 50.5],
    ]))->toThrow(
        InvalidMaterialPercentageException::class,
        'El porcentaje del material «Algodón» debe ser un número entero entre 1 y 100; se ha recibido 50.5.'
    );
});

test('a whole percentage written as text is the same share', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => '60'],
        ['id' => $poliester->id, 'percentage' => '40'],
    ]);

    expect($product->materials()->get()->map(fn ($material): int => (int) $material->pivot->percentage)->all())
        ->toBe([60, 40]);
});

/**
 * A garment is made of what it is made of, so the shares have to account for all of it.
 */
test('a composition that does not add up to a hundred is refused', function (int $total) {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => $total - 5],
        ['id' => $poliester->id, 'percentage' => 5],
    ]))->toThrow(IncompleteMaterialCompositionException::class);
})->with([95, 105, 50]);

test('the refusal says how much the percentages added up to', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $algodon->id, 'percentage' => 60],
        ['id' => $poliester->id, 'percentage' => 30],
    ]))->toThrow(
        IncompleteMaterialCompositionException::class,
        'Los porcentajes de los materiales suman 90 y deben sumar exactamente 100.'
    );
});

test('a material the store has turned off cannot be added', function () {
    $product = Product::factory()->create();
    $inactivo = Material::factory()->inactive()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $inactivo->id, 'percentage' => 100],
    ]))->toThrow(InactiveProductMaterialException::class);
});

test('the refusal names the material that is off', function () {
    $product = Product::factory()->create();
    $inactivo = Material::factory()->inactive()->create(['name' => 'Algodón']);

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => $inactivo->id, 'percentage' => 100],
    ]))->toThrow(
        InactiveProductMaterialException::class,
        'El material «Algodón» está desactivado y no se puede añadir a un producto.'
    );
});

/**
 * Turning a material off is how the store stops offering it without taking it away from
 * the products that already say they are made of it.
 */
test('a product can keep a material the store has turned off', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $sync = new SyncProductMaterials;

    $sync($product, [
        ['id' => $algodon->id, 'percentage' => 60],
        ['id' => $poliester->id, 'percentage' => 40],
    ]);

    $algodon->update(['is_active' => false]);

    $sync($product, [
        ['id' => $algodon->id, 'percentage' => 40],
        ['id' => $poliester->id, 'percentage' => 60],
    ]);

    expect($product->materials()->count())->toBe(2);
});

test('a material that is off and new to the product is still refused', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $lino = Material::factory()->inactive()->create(['name' => 'Lino']);
    $sync = new SyncProductMaterials;

    $sync($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect(fn () => $sync($product, [
        ['id' => $algodon->id, 'percentage' => 50],
        ['id' => $lino->id, 'percentage' => 50],
    ]))->toThrow(InactiveProductMaterialException::class);
});

test('a material the store does not have is refused', function () {
    $product = Product::factory()->create();

    expect(fn () => (new SyncProductMaterials)($product, [
        ['id' => 987654, 'percentage' => 100],
    ]))->toThrow(MaterialNotFoundException::class);
});

/**
 * Every rule is asked before anything is written, so a refused composition leaves the
 * product with the one it already had instead of with half of the new one.
 */
test('a refused composition leaves the previous one alone', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $sync = new SyncProductMaterials;

    $sync($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect(fn () => $sync($product, [
        ['id' => $algodon->id, 'percentage' => 40],
        ['id' => $poliester->id, 'percentage' => 40],
    ]))->toThrow(IncompleteMaterialCompositionException::class);

    expect($product->materials()->count())->toBe(1)
        ->and($product->materials()->sole()->is($algodon))->toBeTrue()
        ->and($product->materials()->sole()->pivot->percentage)->toBe(100);
});

test('a refused percentage leaves the previous composition alone', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $sync = new SyncProductMaterials;

    $sync($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect(fn () => $sync($product, [
        ['id' => $algodon->id, 'percentage' => 0],
        ['id' => $poliester->id, 'percentage' => 100],
    ]))->toThrow(InvalidMaterialPercentageException::class);

    expect($product->materials()->count())->toBe(1)
        ->and($product->materials()->sole()->pivot->percentage)->toBe(100);
});

test('the composition of one product does not leak into another', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $first = Product::factory()->create();
    $second = Product::factory()->create();

    (new SyncProductMaterials)($first, [['id' => $algodon->id, 'percentage' => 100]]);

    expect($first->materials()->count())->toBe(1)
        ->and($second->materials()->count())->toBe(0);
});

test('a product carries a material twice only once in the pivot table', function () {
    $product = Product::factory()->create();
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    (new SyncProductMaterials)($product, [['id' => $algodon->id, 'percentage' => 100]]);

    expect(DB::table('material_product')->where('product_id', $product->id)->count())->toBe(1);
});

test('a material can be given to many products', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $sync = new SyncProductMaterials;

    $sync($first, [['id' => $algodon->id, 'percentage' => 100]]);
    $sync($second, [['id' => $algodon->id, 'percentage' => 100]]);

    expect($algodon->fresh()->products()->count())->toBe(2);
});
