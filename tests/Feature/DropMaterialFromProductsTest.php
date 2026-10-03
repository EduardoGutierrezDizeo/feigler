<?php

use App\Models\Material;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La migración que se lleva `products.material` por delante es la última de la cadena:
 * para cuando se corre, el catálogo ya vive en `material_product` y el texto es un
 * resumen que ya nadie tiene que leer. Lo que se prueba aquí es lo que una migración que
 * borra una columna no puede equivocarse en: que lo que se queda sea exactamente lo que
 * había, que un catálogo que aún no puede decirse entero se quede como estaba en vez de
 * perder el texto, y que un rollback devuelva una frase que se pueda leer.
 */
const MATERIAL_TEXT_MIGRATION = '2026_10_03_192936_drop_material_from_products.php';

/**
 * The shares a product carries, as `[material name, percentage]`, in catalog order.
 *
 * @return list<array{0: string, 1: int}>
 */
function sharesOf(Product $product): array
{
    return $product->materials()
        ->orderBy('materials.order')
        ->orderBy('materials.id')
        ->get()
        ->map(fn (Material $material): array => [$material->name, $material->pivot->percentage])
        ->all();
}

/**
 * The free text of a product, read without going through the model, because a model
 * that no longer has the column is the whole point of the migration.
 */
function legacyTextOf(Product $product): mixed
{
    return DB::table('products')->where('id', $product->getKey())->value('material');
}

/**
 * Every row of the pivot, as `[product, material, percentage]`, in a fixed order.
 *
 * It is the whole table and not one product because "nothing was written" is a claim
 * about every row, and a test that only looks at the product it is about would pass
 * just as happily if the copy had also touched the rest of the catalog.
 *
 * @return list<array{0: int, 1: string, 2: int}>
 */
function wholePivotTable(): array
{
    return DB::table('material_product')
        ->join('materials', 'materials.id', '=', 'material_product.material_id')
        ->orderBy('material_product.product_id')
        ->orderBy('materials.order')
        ->orderBy('materials.id')
        ->get(['material_product.product_id', 'materials.name', 'material_product.percentage'])
        ->map(fn (object $row): array => [(int) $row->product_id, $row->name, (int) $row->percentage])
        ->all();
}

test('the free text of a garment is not there once the migration has run', function () {
    expect(Schema::hasColumn('products', 'material'))->toBeFalse();
});

test('rolling the migration back names a garment made of one material, and running it again keeps the catalog', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $poliester = Material::factory()->create(['name' => 'Poliéster', 'order' => 2]);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->withMaterials([
        ['id' => $algodon->getKey(), 'percentage' => 100],
    ])->create(['name' => 'Polo de algodón']);

    $antes = sharesOf($producto);

    rollbackMigration(MATERIAL_TEXT_MIGRATION);

    // Un solo material se escribe como lo que era el texto: su nombre y nada más.
    expect(Schema::hasColumn('products', 'material'))->toBeTrue()
        ->and(legacyTextOf($producto))->toBe('Algodón');

    migrateAgain(MATERIAL_TEXT_MIGRATION);

    expect(Schema::hasColumn('products', 'material'))->toBeFalse()
        ->and(sharesOf($producto->fresh()))->toBe($antes)
        ->and(Material::count())->toBe(2);
});

test('a garment made of several materials goes back naming all of them with their shares', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $poliester = Material::factory()->create(['name' => 'Poliéster', 'order' => 2]);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->withMaterials([
        ['id' => $algodon->getKey(), 'percentage' => 80],
        ['id' => $poliester->getKey(), 'percentage' => 20],
    ])->create(['name' => 'Polo piqué']);

    rollbackMigration(MATERIAL_TEXT_MIGRATION);

    expect(legacyTextOf($producto))->toBe('Algodón 80 %, Poliéster 20 %');
});

test('a garment with no materials goes back to no text at all', function () {
    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create(['name' => 'Polo sin materiales']);

    rollbackMigration(MATERIAL_TEXT_MIGRATION);

    expect(legacyTextOf($producto))->toBeNull();
});

test('a text of nothing but spaces is not a reason to stop the migration', function () {
    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create(['name' => 'Polo sin materiales']);

    rollbackMigration(MATERIAL_TEXT_MIGRATION);

    DB::table('products')->where('id', $producto->getKey())->update(['material' => '   ']);

    migrateAgain(MATERIAL_TEXT_MIGRATION);

    expect(Schema::hasColumn('products', 'material'))->toBeFalse();
});

test('the migration stops and writes nothing when the text does not add up to a whole garment', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create(['name' => 'Polo a medio componer']);
    // Una prenda que ya lleva una parte de su composición y además nombra otro
    // material en el texto: el pivote acaba en 60 + 100, y el 40 % que falta no está
    // escrito en ninguna parte.
    $producto->materials()->attach($algodon->getKey(), ['percentage' => 60]);

    // Y otra que está bien, para que la copia tenga algo que escribir antes de
    // encontrarse con la que no cuadra: el freno tiene que deshacer lo que sí se
    // alcanzó a copiar, no sólo dejar de seguir.
    $sano = Product::factory()->for($polos)->create(['name' => 'Polo sano']);
    $sano->materials()->attach($algodon->getKey(), ['percentage' => 100]);

    rollbackMigration(MATERIAL_TEXT_MIGRATION);

    DB::table('products')->where('id', $producto->getKey())->update(['material' => 'Poliéster']);
    DB::table('products')->where('id', $sano->getKey())->update(['material' => 'Algodón']);

    // La foto del catálogo entero justo antes de intentarlo, para comparar con lo que
    // quede después en vez de con lo que el propio test pone en el escenario.
    $pivoteAntes = wholePivotTable();
    $materialesAntes = Material::count();

    $parada = rescue(
        fn () => test()->artisan('migrate', ['--path' => 'database/migrations/'.MATERIAL_TEXT_MIGRATION])->run(),
        fn (RuntimeException $exception) => $exception,
        report: false,
    );

    expect($parada)->toBeInstanceOf(RuntimeException::class)
        ->and($parada->getMessage())->toContain('no suma 100 %')
        // La columna sigue ahí, que es lo primero que tiene que estar: sin el texto no
        // hay forma de volver a componer la prenda.
        ->and(Schema::hasColumn('products', 'material'))->toBeTrue()
        ->and(legacyTextOf($producto))->toBe('Poliéster')
        ->and(legacyTextOf($sano))->toBe('Algodón')
        // El pivote entero, tal cual estaba antes: ni la fila que el backfill iba a
        // agregar al producto que no cuadra, ni un cambio en la del que sí estaba bien.
        ->and(wholePivotTable())->toBe($pivoteAntes)
        // Y el catálogo de materiales sin el que la copia alcanzó a crear.
        ->and(Material::count())->toBe($materialesAntes)
        ->and(Material::where('name', 'Poliéster')->exists())->toBeFalse()
        ->and(sharesOf($producto->fresh()))->toBe([['Algodón', 60]]);

    // La migración sigue pendiente, que es lo que la deja reintentable: una que se
    // hubiera marcado como corrida sobre un catálogo a medias no volvería a parar.
    expect(DB::table('migrations')->where('migration', MATERIAL_TEXT_MIGRATION)->exists())->toBeFalse();
});
