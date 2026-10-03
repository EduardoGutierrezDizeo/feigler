<?php

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration that took `product_variants.size` away is reversible, and a rollback
 * that loses a catalog is worse than no rollback at all: what it puts back has to be
 * the catalog that was there, name by name, without a single variant or size lost.
 */
const MIGRATION_UNDER_TEST = '2026_10_03_021113_replace_product_variants_size_with_size_id.php';

/**
 * The migration rolled back, so that the column is there to be read again.
 */
function rollbackDelEsquema(): void
{
    rollbackMigration(MIGRATION_UNDER_TEST);
}

/**
 * The migration run again, on top of a catalog that has just been rolled back.
 */
function migrarDelEsquema(): void
{
    migrateAgain(MIGRATION_UNDER_TEST);
}

/**
 * A product of a category of its own, with the given variants already sold.
 *
 * @param  array<string, array{size: string, stock: int}>  $variantes
 */
function productoConVariantes(array $variantes): Product
{
    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    foreach ($variantes as $sku => $variante) {
        ProductVariant::factory()->for($producto)->for($color, 'color')->create([
            'size_id' => Size::query()->firstOrCreate([
                'category_id' => $producto->category_id,
                'name' => $variante['size'],
            ])->getKey(),
            'sku' => $sku,
            'stock' => $variante['stock'],
        ]);
    }

    return $producto;
}

test('the size text is gone and the size id is not', function () {
    expect(Schema::hasColumn('product_variants', 'size'))->toBeFalse()
        ->and(Schema::hasColumn('product_variants', 'size_id'))->toBeTrue();
});

test('the combination of a product, a size and a color is unique', function () {
    $producto = productoConVariantes(['PL-001-M-AZU' => ['size' => 'M', 'stock' => 3]]);
    $color = $producto->variants()->sole()->color;
    $size = $producto->variants()->sole()->size;

    expect(fn () => $producto->variants()->create([
        'size_id' => $size->getKey(),
        'color_id' => $color->getKey(),
        'sku' => 'PL-001-M-AZU-2',
    ]))->toThrow(QueryException::class);
});

test('rolling the migration back puts the size text back and keeps the catalog', function () {
    $producto = productoConVariantes([
        'PL-001-M-AZU' => ['size' => 'M', 'stock' => 3],
        'PL-001-XL-ROJ' => ['size' => 'XL', 'stock' => 7],
    ]);

    $antes = $producto->variants()->count();

    rollbackDelEsquema();

    expect(Schema::hasColumn('product_variants', 'size'))->toBeTrue()
        ->and($producto->variants()->count())->toBe($antes)
        ->and($producto->variants()->orderBy('sku')->pluck('size')->all())->toBe(['M', 'XL'])
        ->and($producto->variants()->orderBy('sku')->pluck('sku')->all())->toBe(['PL-001-M-AZU', 'PL-001-XL-ROJ'])
        ->and(Size::count())->toBe(2);

    // The text is back and the sizes stay where they were, so a catalog rolled back
    // is a catalog that can be rolled forward without anything being decided again.
    $producto->variants()->get()->each(
        fn (ProductVariant $variant) => expect($variant->size_id)->not->toBeNull()
    );
});

test('the size id is nullable again once the text is back', function () {
    $producto = productoConVariantes(['PL-001-M-AZU' => ['size' => 'M', 'stock' => 3]]);

    rollbackDelEsquema();

    expect(DB::table('product_variants')->where('id', $producto->variants()->sole()->getKey())->value('size_id'))
        ->not->toBeNull();

    $producto->variants()->sole()->update(['size_id' => null]);

    expect($producto->variants()->sole()->refresh()->size_id)->toBeNull();
});

test('running the migration again takes the text away without touching the catalog', function () {
    $producto = productoConVariantes([
        'PL-001-M-AZU' => ['size' => 'M', 'stock' => 3],
        'PL-001-XL-ROJ' => ['size' => 'XL', 'stock' => 7],
    ]);

    $skus = $producto->variants()->orderBy('sku')->pluck('sku')->all();
    $tallas = $producto->variants()->orderBy('sku')->get(['size_id'])->map->size->map->getKey()->all();

    rollbackDelEsquema();
    migrarDelEsquema();

    expect(Schema::hasColumn('product_variants', 'size'))->toBeFalse()
        ->and($producto->variants()->count())->toBe(2)
        ->and($producto->variants()->orderBy('sku')->pluck('sku')->all())->toBe($skus)
        // Las mismas filas de `sizes`, con los mismos identificadores: volver a correr
        // la migración no crea tallas nuevas para lo que ya estaba apuntado.
        ->and($producto->variants()->orderBy('sku')->get(['size_id'])->map->size->map->getKey()->all())
        ->toBe($tallas)
        ->and($producto->category->sizes()->pluck('name')->all())->toEqualCanonicalizing(Size::STANDARD_NAMES);
});

test('the migration stops and writes nothing when a variant cannot be attributed', function () {
    $producto = productoConVariantes(['PL-001-M-AZU' => ['size' => 'M', 'stock' => 3]]);

    rollbackDelEsquema();

    // Una variante sin texto no tiene de dónde sacar su talla, y una migración que
    // se lleva la columna por delante la deja sin poder administrarla.
    DB::table('product_variants')->where('id', $producto->variants()->sole()->getKey())->update([
        'size' => '   ',
        'size_id' => null,
    ]);

    $tallasAntes = Size::count();

    // La excepción sale del comando de migraciones en vez de un código de salida: lo
    // que importa es que pare antes de tocar el esquema, y eso es lo que se mira.
    $parada = rescue(
        fn () => test()->artisan('migrate', ['--path' => 'database/migrations/'.MIGRATION_UNDER_TEST])->run(),
        fn (RuntimeException $exception) => $exception,
        report: false,
    );

    expect($parada)->toBeInstanceOf(RuntimeException::class)
        ->and($parada->getMessage())->toContain('siguen sin «size_id»')
        ->and(Schema::hasColumn('product_variants', 'size'))->toBeTrue()
        ->and($producto->variants()->count())->toBe(1)
        // Y lo que el backfill alcanzó a escribir también desapareció con él.
        ->and(Size::count())->toBe($tallasAntes)
        ->and($producto->variants()->sole()->refresh()->size_id)->toBeNull();
});
