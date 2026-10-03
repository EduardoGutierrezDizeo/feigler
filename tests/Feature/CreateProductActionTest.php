<?php

use App\Actions\Products\CreateProduct;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidProductNameException;
use App\Exceptions\InvalidProductStatusException;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * La acción que escribe el producto, probada sin el panel delante: es la que va a
 * crear las filas de una carga masiva, y tiene que numerar, slugar, poner la marca
 * y el estado exactamente como lo hace el formulario.
 */

test('a product is created with the next reference of its category', function () {
    $polos = numberedCategory('PL', 'Polos');

    $primero = (new CreateProduct)($polos, ['name' => 'Polo clásico piqué', 'base_price' => '89900']);
    $segundo = (new CreateProduct)($polos, ['name' => 'Polo de algodón', 'base_price' => '39900']);

    expect($primero->reference)->toBe('PL-001')
        ->and($segundo->reference)->toBe('PL-002')
        ->and($primero->category_id)->toBe($polos->id)
        ->and($primero->name)->toBe('Polo clásico piqué')
        ->and($primero->slug)->toBe('polo-clasico-pique')
        ->and($primero->base_price)->toEqual('89900.00')
        ->and($primero->section)->toBe($polos->section);
});

test('a reference that is not prefix-digits is left out of the count', function () {
    $polos = numberedCategory('PL', 'Polos');

    // Una referencia vieja que no sigue el formato de la serie no mueve el
    // contador: el primer producto que crea la acción sigue siendo el 001.
    Product::factory()->for($polos)->create(['reference' => 'PL-ABC']);

    expect((new CreateProduct)($polos, ['name' => 'Polo clásico', 'base_price' => '89900'])->reference)
        ->toBe('PL-001');
});

test('the counter keeps counting past three digits', function () {
    $polos = numberedCategory('PL', 'Polos');

    Product::factory()->for($polos)->create(['reference' => 'PL-999']);

    expect((new CreateProduct)($polos, ['name' => 'Polo clásico', 'base_price' => '89900'])->reference)
        ->toBe('PL-1000');
});

test('a repeated name gets a slug of its own instead of refusing to save', function () {
    $polos = numberedCategory('PL', 'Polos');

    Product::factory()->for($polos)->create(['name' => 'Polo básico', 'slug' => 'polo-basico']);

    $primero = (new CreateProduct)($polos, ['name' => 'Polo básico', 'base_price' => '39900']);
    $segundo = (new CreateProduct)($polos, ['name' => 'Polo básico', 'base_price' => '39900']);

    expect($primero->slug)->toBe('polo-basico-2')
        ->and($segundo->slug)->toBe('polo-basico-3')
        ->and(Product::query()->where('slug', 'polo-basico')->count())->toBe(1);
});

test('a name with nothing to build a slug from is refused', function () {
    $polos = numberedCategory('PL', 'Polos');

    expect(fn () => (new CreateProduct)($polos, ['name' => '¿¿? ', 'base_price' => '19900']))
        ->toThrow(InvalidProductNameException::class);

    expect(Product::query()->count())->toBe(0);
});

test('a product with no brand of its own is stored as a Feigler one', function () {
    $polos = numberedCategory('PL', 'Polos');

    $sinMarca = (new CreateProduct)($polos, ['name' => 'Polo clásico', 'base_price' => '89900']);
    $enBlanco = (new CreateProduct)($polos, ['name' => 'Polo piqué', 'base_price' => '89900', 'brand' => '   ']);
    $conMarca = (new CreateProduct)($polos, ['name' => 'Polo de lino', 'base_price' => '89900', 'brand' => ' Otra marca ']);

    expect($sinMarca->brand)->toBe(CreateProduct::DEFAULT_BRAND)
        ->and($sinMarca->brand)->toBe('Feigler')
        ->and($enBlanco->brand)->toBe('Feigler')
        ->and($conMarca->brand)->toBe('Otra marca');
});

test('a product is born active and keeps the status it is given', function () {
    $polos = numberedCategory('PL', 'Polos');

    $nuevo = (new CreateProduct)($polos, ['name' => 'Polo clásico', 'base_price' => '89900']);
    $inactivo = (new CreateProduct)($polos, ['name' => 'Polo retirado', 'base_price' => '89900', 'status' => 'inactive']);

    expect($nuevo->status)->toBe(CreateProduct::INITIAL_STATUS)
        ->and($nuevo->status)->toBe('active')
        ->and($nuevo->display_status)->toBe('no_variants')
        ->and($inactivo->status)->toBe('inactive');
});

test('a computed status is never stored', function () {
    $polos = numberedCategory('PL', 'Polos');

    // «Agotado» es lo que calcula el stock de las variantes: escribirlo dejaría la
    // fila mintiendo después del siguiente movimiento.
    expect(fn () => (new CreateProduct)($polos, [
        'name' => 'Agotado a mano',
        'base_price' => '19900',
        'status' => 'out_of_stock',
    ]))->toThrow(InvalidProductStatusException::class);

    expect(Product::query()->count())->toBe(0);
});

test('a sentence left empty is stored as no value at all', function () {
    $polos = numberedCategory('PL', 'Polos');

    $vacia = (new CreateProduct)($polos, ['name' => 'Polo clásico', 'base_price' => '89900']);
    $conTextos = (new CreateProduct)($polos, [
        'name' => 'Polo piqué',
        'base_price' => '89900',
        'description' => '  Piqué de algodón peinado.  ',
        'brand' => '  Feigler  ',
    ]);

    expect($vacia->description)->toBeNull()
        ->and($conTextos->description)->toBe('Piqué de algodón peinado.')
        ->and($conTextos->brand)->toBe('Feigler');
});

/**
 * La composición no es un dato más de la fila: se escribe dentro de la transacción que
 * la crea, así que una prenda nunca queda en el catálogo diciendo unos materiales que la
 * acción acaba de rechazar.
 */
test('a product is created with the composition it is given, and none at all when it is given none', function () {
    $polos = numberedCategory('PL', 'Polos');
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    $compuesta = (new CreateProduct)(
        $polos,
        ['name' => 'Polo piqué', 'base_price' => '89900'],
        [
            ['id' => $algodon->getKey(), 'percentage' => 80],
            ['id' => $poliester->getKey(), 'percentage' => 20],
        ],
    );

    $sinMateriales = (new CreateProduct)($polos, ['name' => 'Polo básico', 'base_price' => '79900']);

    expect($compuesta->materials()->orderBy('materials.id')->pluck('materials.name')->all())->toBe(['Algodón', 'Poliéster'])
        ->and(array_map(
            fn (Material $material): int => $material->pivot->percentage,
            $compuesta->materials()->orderBy('materials.id')->get()->all(),
        ))->toBe([80, 20])
        ->and($sinMateriales->materials()->count())->toBe(0);
});

test('a composition the action refuses leaves no product behind', function () {
    $polos = numberedCategory('PL', 'Polos');
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new CreateProduct)(
        $polos,
        ['name' => 'Polo piqué', 'base_price' => '89900'],
        [['id' => $algodon->getKey(), 'percentage' => 80]],
    ))->toThrow(IncompleteMaterialCompositionException::class);

    expect(Product::query()->count())->toBe(0)
        ->and($algodon->products()->count())->toBe(0);
});

/**
 * El caso que va a necesitar la carga masiva: varias filas de la misma categoría
 * dentro de una transacción.
 *
 * La acción anida su transacción en la de afuera, así que el cerrojo que toma la
 * serie sigue vivo entre una creación y la siguiente: leídas por separado, las
 * dos verían el mismo contador.
 */
test('two products created inside the same transaction are numbered in a row', function () {
    $polos = numberedCategory('PL', 'Polos');
    $crear = new CreateProduct;

    $referencias = DB::transaction(fn (): array => [
        $crear($polos, ['name' => 'Polo clásico', 'base_price' => '89900'])->reference,
        $crear($polos, ['name' => 'Polo piqué', 'base_price' => '89900'])->reference,
    ]);

    expect($referencias)->toBe(['PL-001', 'PL-002'])
        ->and(Product::query()->orderBy('id')->pluck('reference')->all())->toBe(['PL-001', 'PL-002']);
});

test('a transaction that is rolled back leaves neither the product nor its reference', function () {
    $polos = numberedCategory('PL', 'Polos');
    $crear = new CreateProduct;

    expect(fn () => DB::transaction(function () use ($polos, $crear): void {
        $crear($polos, ['name' => 'Polo clásico', 'base_price' => '89900']);

        throw new RuntimeException('Fallo simulado a mitad de la carga');
    }))->toThrow(RuntimeException::class);

    expect(Product::query()->count())->toBe(0);

    // La referencia reservada se va con la transacción: el contador se cuenta
    // sobre lo que existe, así que el siguiente producto vuelve a ser el 001.
    expect((new CreateProduct)($polos, ['name' => 'Polo piqué', 'base_price' => '89900'])->reference)
        ->toBe('PL-001');
});
