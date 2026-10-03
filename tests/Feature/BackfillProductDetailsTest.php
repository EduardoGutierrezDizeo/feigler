<?php

use App\Actions\ProductDetails\BackfillProductDetails;
use App\Exceptions\DuplicateNormalizedSizeException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;

/**
 * The migration that took `product_variants.size` away, which the tests that read
 * that text need rolled back and which therefore cannot be referred to by hand: a
 * date is written down twice on purpose, because the file name is also its name.
 */
const MIGRATION_THAT_DROPS_THE_SIZE_TEXT = '2026_10_03_021113_replace_product_variants_size_with_size_id.php';

/**
 * The migration that took `products.material` away, for the same reason and in the same
 * way as the one above: these tests read a text column the schema no longer has.
 */
const MIGRATION_THAT_DROPS_THE_MATERIAL_TEXT = '2026_10_03_192936_drop_material_from_products.php';

/**
 * A variant as the store carried it before the sizes moved into a table: free text
 * and no row behind it.
 *
 * The factory hands every variant a size of its category, which is right for the panel
 * and useless here, so it is taken away afterwards together with the size it created:
 * what these tests look at is the text and not the row, and a category that started
 * with no sizes has to be left that way.
 */
function varianteConTalla(Product $product, string $text, ?Color $color = null): ProductVariant
{
    $variant = ProductVariant::factory()->for($product)->create([
        'size' => $text,
        ...$color === null ? [] : ['color_id' => $color->getKey()],
    ]);

    $variant->update(['size_id' => null]);
    $product->category->sizes()->delete();

    return $variant;
}

test('every category is given the sizes the store sells', function () {
    $category = Category::factory()->create();

    $report = app(BackfillProductDetails::class)->sizes();

    expect($category->sizes()->pluck('name')->all())->toBe(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'ÚNICA'])
        ->and($category->sizes()->pluck('order')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($report['created'])->toBe(8);
});

describe('con la columna de texto todavia en su sitio', function () {
    beforeEach(function (): void {
        // La accion lee el texto de la variante, y ese texto vive en la columna
        // que la ultima migracion se llevo. Estas pruebas corren sobre el
        // esquema de antes de ella, que es el unico donde la copia tiene algo
        // que hacer; el resto del archivo, sobre el esquema de ahora.
        test()->artisan('migrate:rollback', [
            '--batch' => 1,
            '--path' => 'database/migrations/'.MIGRATION_THAT_DROPS_THE_SIZE_TEXT,
        ])->assertSuccessful();
    });

    test('a variant is pointed at the size of its own category and keeps its size text', function () {
        $category = Category::factory()->create();
        $variant = varianteConTalla(Product::factory()->for($category)->create(), 'M');

        app(BackfillProductDetails::class)->sizes();

        $size = Size::where('category_id', $category->id)->where('name', 'M')->sole();

        expect($variant->refresh()->size_id)->toBe($size->id)
            ->and($variant->size)->toBe('M')
            ->and($size->category->is($category))->toBeTrue();
    });

    test('a size typed in lowercase and with spaces is the size already catalogued', function () {
        $category = Category::factory()->create();
        $variant = varianteConTalla(Product::factory()->for($category)->create(), '  m ');

        app(BackfillProductDetails::class)->sizes();

        expect($variant->refresh()->size_id)->toBe(
            Size::where('category_id', $category->id)->where('name', 'M')->sole()->id
        )
            ->and($category->sizes()->count())->toBe(8);
    });

    test('a size outside the standard list is created in the category that sells it', function () {
        $category = Category::factory()->create();
        $variant = varianteConTalla(Product::factory()->for($category)->create(), '42');

        app(BackfillProductDetails::class)->sizes();

        expect($variant->refresh()->size_id)->toBe(
            Size::where('category_id', $category->id)->where('name', '42')->sole()->id
        )
            ->and($category->sizes()->where('name', '42')->sole()->order)->toBe(9);
    });

    test('the same size is catalogued once per category, not once per product', function () {
        $category = Category::factory()->create();
        varianteConTalla(Product::factory()->for($category)->create(), 'M');
        Product::factory()->for($category)->create();

        app(BackfillProductDetails::class)->sizes();

        expect(Size::where('name', 'M')->count())->toBe(1)
            ->and(Size::count())->toBe(8);
    });

    test('copying the sizes twice leaves the catalog as it was', function () {
        $category = Category::factory()->create();
        $variant = varianteConTalla(Product::factory()->for($category)->create(), 'M');

        app(BackfillProductDetails::class)->sizes();
        $second = app(BackfillProductDetails::class)->sizes();

        expect($second['created'])->toBe(0)
            ->and($second['assigned'])->toBe(0)
            ->and($second['skipped'])->toBe(0)
            ->and(Size::count())->toBe(8)
            ->and($variant->refresh()->size_id)->not->toBeNull();
    });

    test('a variant whose size is blank is left without a size', function () {
        $product = Product::factory()->create();
        varianteConTalla($product, '   ');

        $report = app(BackfillProductDetails::class)->sizes();

        expect(ProductVariant::sole()->refresh()->size_id)->toBeNull()
            ->and($report['skipped'])->toBe(1)
            ->and($report['assigned'])->toBe(0);
    });

    test('two sizes of the same product and color that differ only in writing stop the copy', function () {
        $product = Product::factory()->create();
        $color = Color::factory()->create();
        varianteConTalla($product, 'M', $color);
        varianteConTalla($product, ' m ', $color);

        app(BackfillProductDetails::class)->sizes();
    })->throws(DuplicateNormalizedSizeException::class);

    test('a catalog with two sizes of one product written two ways is left untouched', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $color = Color::factory()->create();
        $first = varianteConTalla($product, 'M', $color);
        $second = varianteConTalla($product, ' m ', $color);

        // The copy gives up on purpose, so the two variants are reviewed by hand. Here
        // the point is what it leaves behind when it does.
        $refused = rescue(
            fn () => app(BackfillProductDetails::class)->sizes(),
            fn (DuplicateNormalizedSizeException $exception) => $exception,
            report: false,
        );

        expect($refused)->toBeInstanceOf(DuplicateNormalizedSizeException::class)
            ->and(Size::count())->toBe(0)
            ->and($first->refresh()->size_id)->toBeNull()
            ->and($second->refresh()->size_id)->toBeNull()
            ->and($category->sizes()->count())->toBe(0);
    });

    test('the same size in two colors of the same product is not a conflict', function () {
        $product = Product::factory()->create();
        varianteConTalla($product, 'M', Color::factory()->create());
        varianteConTalla($product, 'M', Color::factory()->create());

        app(BackfillProductDetails::class)->sizes();

        expect(ProductVariant::whereNotNull('size_id')->count())->toBe(2);
    });
});

test('the colors are numbered in the order the panel reads them', function () {
    $rojo = Color::factory()->create(['name' => 'Rojo']);
    $azul = Color::factory()->create(['name' => 'Azul']);
    Color::factory()->create(['name' => 'Blanco']);

    $report = app(BackfillProductDetails::class)->colors();

    expect($azul->refresh()->order)->toBe(1)
        ->and($rojo->refresh()->order)->toBe(3)
        ->and(Color::orderBy('order')->pluck('name')->all())->toBe(['Azul', 'Blanco', 'Rojo'])
        ->and(Color::active()->count())->toBe(3)
        ->and($report['assigned'])->toBe(3);
});

test('numbering the colors twice keeps the numbers already given', function () {
    $azul = Color::factory()->create(['name' => 'Azul']);

    app(BackfillProductDetails::class)->colors();
    $second = app(BackfillProductDetails::class)->colors();

    expect($second['assigned'])->toBe(0)
        ->and($azul->refresh()->order)->toBe(1);
});

describe('con la columna de texto del material todavia en su sitio', function () {
    beforeEach(function (): void {
        // La accion lee el texto del producto, y ese texto vive en la columna que la
        // ultima migracion se llevo, por el mismo motivo que arriba.
        test()->artisan('migrate:rollback', [
            '--batch' => 1,
            '--path' => 'database/migrations/'.MIGRATION_THAT_DROPS_THE_MATERIAL_TEXT,
        ])->assertSuccessful();
    });

    test('materials that differ only in writing are catalogued as one material', function () {
        $sinAcento = Product::factory()->create(['material' => 'Algodon']);
        $conAcento = Product::factory()->create(['material' => 'algodón']);
        $conEspacios = Product::factory()->create(['material' => '  ALGODÓN  ']);

        app(BackfillProductDetails::class)->materials();

        expect(Material::count())->toBe(1)
            ->and(Material::sole()->name)->toBe('Algodon')
            ->and($sinAcento->materials()->count())->toBe(1)
            ->and($conAcento->materials()->get()[0]->is(Material::sole()))->toBeTrue()
            ->and($conEspacios->materials()->get()[0]->is(Material::sole()))->toBeTrue();
    });

    test('a product that names two materials is copied whole and reported for review', function () {
        $product = Product::factory()->create(['material' => 'Algodón/Poliéster']);

        $report = app(BackfillProductDetails::class)->materials();

        expect($product->materials()->pluck('name')->all())->toBe(['Algodón/Poliéster'])
            ->and($report['listed'])->toBe(['Algodón/Poliéster'])
            ->and($report['created'])->toBe(1);
    });

    test('a product whose material carries its own percentages is reported for review', function () {
        Product::factory()->create(['material' => '80% algodón, 20% poliéster']);

        $report = app(BackfillProductDetails::class)->materials();

        expect($report['listed'])->toBe(['80% algodón, 20% poliéster']);
    });

    test('a product that names no material is left out', function () {
        Product::factory()->create(['material' => '   ']);

        $report = app(BackfillProductDetails::class)->materials();

        expect(Material::count())->toBe(0)
            ->and($report['assigned'])->toBe(0);
    });

    test('every product carries its whole material', function () {
        $denim = Product::factory()->create(['material' => 'Denim']);

        app(BackfillProductDetails::class)->materials();

        expect($denim->materials()->sole()->name)->toBe('Denim')
            ->and($denim->materials()->sole()->pivot->percentage)->toBe(100);
    });

    test('copying the materials twice attaches each one a single time', function () {
        $product = Product::factory()->create(['material' => 'Denim']);

        app(BackfillProductDetails::class)->materials();
        $second = app(BackfillProductDetails::class)->materials();

        expect(Material::count())->toBe(1)
            ->and($product->materials()->count())->toBe(1)
            ->and($second['created'])->toBe(0)
            ->and($second['assigned'])->toBe(0);
    });

    test('a product that already carries a material keeps the share it was given', function () {
        $linen = Material::factory()->create(['name' => 'Lino']);
        $product = Product::factory()->create(['material' => 'Lino']);
        $product->materials()->attach($linen->id, ['percentage' => 40]);

        app(BackfillProductDetails::class)->materials();

        expect($product->materials()->count())->toBe(1)
            ->and($product->materials()->sole()->pivot->percentage)->toBe(40);
    });
});
