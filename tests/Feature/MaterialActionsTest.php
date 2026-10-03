<?php

use App\Actions\ProductDetails\CreateMaterial;
use App\Actions\ProductDetails\DeleteMaterial;
use App\Actions\ProductDetails\MoveMaterial;
use App\Actions\ProductDetails\ToggleMaterial;
use App\Actions\ProductDetails\UpdateMaterial;
use App\Exceptions\DuplicateMaterialNameException;
use App\Exceptions\MaterialInUseException;
use App\Models\Material;
use App\Models\Product;

test('a material is created at the end of the list and active', function () {
    $material = (new CreateMaterial)('Algodón');

    expect($material->name)->toBe('Algodón')
        ->and($material->order)->toBe(0)
        ->and($material->is_active)->toBeTrue();
});

test('a material name is stored without the spaces around it', function () {
    expect((new CreateMaterial)('  Poliéster  ')->name)->toBe('Poliéster');
});

test('a name the store already has is refused', function () {
    (new CreateMaterial)('Algodón');

    expect(fn () => (new CreateMaterial)('Algodón'))->toThrow(DuplicateMaterialNameException::class);

    expect(Material::query()->count())->toBe(1);
});

test('a name that repeats one only in case or accent is refused', function (string $name) {
    (new CreateMaterial)('Café');

    expect(fn () => (new CreateMaterial)($name))->toThrow(DuplicateMaterialNameException::class);
})->with(['cafe', 'CAFÉ', ' café ']);

test('the refusal names the repeated material', function () {
    (new CreateMaterial)('Algodón');

    expect(fn () => (new CreateMaterial)('algodón'))
        ->toThrow(DuplicateMaterialNameException::class, 'Ya existe un material llamado «algodón».');
});

test('a material can be renamed', function () {
    $material = (new CreateMaterial)('Algodón');

    expect((new UpdateMaterial)($material, 'Algodón peinado')->name)->toBe('Algodón peinado')
        ->and($material->fresh()->name)->toBe('Algodón peinado');
});

test('a rename does not move the material or turn it off', function () {
    $material = (new CreateMaterial)('Algodón');
    (new ToggleMaterial)($material);
    $order = $material->order;

    (new UpdateMaterial)($material, 'Algodón peinado');

    expect($material->fresh()->order)->toBe($order)
        ->and($material->fresh()->is_active)->toBeFalse();
});

/**
 * A material name does not travel into a SKU, which is built out of the category, the
 * size and the color, so renaming one is allowed even while products carry it.
 */
test('a material products carry can still be renamed', function () {
    $material = (new CreateMaterial)('Algodón');
    Product::factory()->create()->materials()->attach($material, ['percentage' => 100]);

    expect((new UpdateMaterial)($material, 'Algodón peinado')->name)->toBe('Algodón peinado');
});

test('a rename onto a name the store already has is refused', function () {
    (new CreateMaterial)('Algodón');
    $material = (new CreateMaterial)('Poliéster');

    expect(fn () => (new UpdateMaterial)($material, 'algodón'))
        ->toThrow(DuplicateMaterialNameException::class);

    expect($material->fresh()->name)->toBe('Poliéster');
});

test('writing the same name back is accepted', function (string $name) {
    $material = (new CreateMaterial)('Algodón');

    expect((new UpdateMaterial)($material, $name)->name)->toBe('Algodón');
})->with(['Algodón', 'algodón', ' Algodón ']);

test('a material can be turned off and on again with the same action', function () {
    $material = (new CreateMaterial)('Algodón');
    $toggle = new ToggleMaterial;

    expect($toggle($material)->is_active)->toBeFalse()
        ->and($toggle($material)->is_active)->toBeTrue();
});

test('turning a material off takes it out of the offer and keeps the products', function () {
    $material = (new CreateMaterial)('Algodón');
    Product::factory()->create()->materials()->attach($material, ['percentage' => 100]);

    (new ToggleMaterial)($material);

    expect(Material::listedActive()->pluck('name')->all())->toBe([])
        ->and($material->fresh()->products()->count())->toBe(1);
});

test('a material no product carries can be deleted', function () {
    $material = (new CreateMaterial)('Algodón');

    (new DeleteMaterial)($material);

    expect(Material::query()->whereKey($material->id)->exists())->toBeFalse();
});

test('a material a product carries cannot be deleted', function () {
    $material = (new CreateMaterial)('Algodón');
    Product::factory()->create()->materials()->attach($material, ['percentage' => 100]);

    expect(fn () => (new DeleteMaterial)($material))->toThrow(MaterialInUseException::class);

    expect(Material::query()->whereKey($material->id)->exists())->toBeTrue();
});

test('the refusal counts the products that hold the material and names the way out', function () {
    $material = (new CreateMaterial)('Algodón');
    $first = Product::factory()->create();
    Product::factory()->create()->materials()->attach($material, ['percentage' => 100]);
    Product::factory()->create()->materials()->attach($material, ['percentage' => 100]);
    expect($first->materials()->count())->toBe(0);

    expect(fn () => (new DeleteMaterial)($material))->toThrow(
        MaterialInUseException::class,
        'No se puede eliminar el material «Algodón» porque 2 productos lo usan'
    );
});

test('deleting a material in use leaves the composition of the products alone', function () {
    $material = (new CreateMaterial)('Algodón');
    $product = Product::factory()->create();
    $product->materials()->attach($material, ['percentage' => 100]);

    expect(fn () => (new DeleteMaterial)($material))->toThrow(MaterialInUseException::class);

    expect($product->fresh()->materials()->count())->toBe(1)
        ->and($product->materials()->sole()->pivot->percentage)->toBe(100);
});

test('a material moves up and down inside the store', function () {
    $algodon = (new CreateMaterial)('Algodón');
    $poliester = (new CreateMaterial)('Poliéster');
    $lino = (new CreateMaterial)('Lino');

    (new MoveMaterial)($lino, -1);

    expect(Material::listed()->pluck('name')->all())->toBe(['Algodón', 'Lino', 'Poliéster']);

    (new MoveMaterial)($algodon, 2);

    expect(Material::listed()->pluck('name')->all())->toBe(['Lino', 'Poliéster', 'Algodón'])
        ->and(Material::listed()->pluck('order')->all())->toBe([0, 1, 2]);
});

test('cannot move the first material up or the last one down', function () {
    $algodon = (new CreateMaterial)('Algodón');
    $lino = (new CreateMaterial)('Lino');

    (new MoveMaterial)($algodon, -1);
    (new MoveMaterial)($lino, 1);

    expect(Material::listed()->pluck('name')->all())->toBe(['Algodón', 'Lino']);
});

test('a material is added at the end of the list of the store', function () {
    (new CreateMaterial)('Algodón');
    (new CreateMaterial)('Poliéster');

    expect((new CreateMaterial)('Lino')->order)->toBe(2);
});

test('moving a material heals a list where two materials share a position', function () {
    $algodon = (new CreateMaterial)('Algodón');
    $poliester = (new CreateMaterial)('Poliéster');
    $algodon->update(['order' => 0]);
    $poliester->update(['order' => 0]);

    (new MoveMaterial)($algodon, 1);

    expect(Material::listed()->pluck('name')->all())->toBe(['Poliéster', 'Algodón'])
        ->and($algodon->fresh()->order)->toBe(1)
        ->and($poliester->fresh()->order)->toBe(0);
});
