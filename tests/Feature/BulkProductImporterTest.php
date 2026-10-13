<?php

use App\Actions\Products\CreateProduct;
use App\Actions\Products\CreateProductVariant;
use App\Exceptions\DuplicateImportedProductException;
use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Services\ProductImport\BulkProductImporter;
use App\Services\ProductImport\BulkProductImportValidator;

/*
 * La mitad que escribe de la carga masiva: el archivo ya leído se crea en la base
 * con las mismas acciones que usa el formulario. Aquí se prueba que un artículo de
 * la carga y uno escrito a mano acaban siendo la misma fila, que un artículo que
 * falla no deja nada detrás, y que el mismo archivo confirmado dos veces no
 * duplica nada.
 */

/**
 * Una categoría con las tallas, el color y el material que escriben las filas,
 * para que el archivo tenga contra qué resolverse.
 *
 * @return array{category: Category, color: Color, material: Material, sizes: array<string, Size>}
 */
function bulkFixture(): array
{
    $category = numberedCategory('PO', 'Polos');
    $color = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    return [
        'category' => $category,
        'color' => $color,
        'material' => $material,
        'sizes' => [
            'M' => Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]),
            'L' => Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]),
        ],
    ];
}

/** @return list<string> */
function bulkHeaders(): array
{
    return ['genero', 'categoria', 'nombre', 'precio', 'materiales', 'talla', 'color', 'stock'];
}

/** @return array<string, mixed> */
function bulkRow(array $overrides = []): array
{
    return array_merge([
        'genero' => 'hombre',
        'categoria' => 'Polos',
        'nombre' => 'Polo básico',
        'precio' => '85000',
        'materiales' => 'Algodón 100%',
        'talla' => 'M',
        'color' => 'Rojo',
        'stock' => '5',
    ], $overrides);
}

/** @param  list<array<string, mixed>>  $rows */
function bulkCsv(array $rows, ?array $headers = null): string
{
    $headers ??= bulkHeaders();
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            fn (string $header): string => (string) ($row[$header] ?? ''),
            $headers,
        ));
    }

    return implode("\n", $lines)."\n";
}

/** A real file in the temp directory, remembered so the tests clean it up. */
function bulkFile(array $rows, ?array $headers = null): string
{
    $GLOBALS['bulkImportFiles'][] = $path = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'feigler-bulk-'.bin2hex(random_bytes(8)).'.csv';

    file_put_contents($path, bulkCsv($rows, $headers));

    return $path;
}

afterEach(function () {
    foreach ($GLOBALS['bulkImportFiles'] ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $GLOBALS['bulkImportFiles'] = [];
});

test('a file creates its article inactive, with active variants, their SKU and their stock', function () {
    bulkFixture();
    $file = bulkFile([
        bulkRow(['talla' => 'M', 'stock' => '5']),
        bulkRow(['talla' => 'L', 'stock' => '0']),
    ]);

    $result = (new BulkProductImporter)->import($file);

    expect($result['totals'])->toBe([
        'created_articles' => 1,
        'created_variants' => 2,
        'rejected_articles' => 0,
        'rejected_rows' => 0,
    ])
        ->and($result['created'])->toBe([[
            'row' => 2,
            'name' => 'Polo básico',
            'reference' => 'PO-001',
            'category' => 'Polos',
            'variants' => 2,
        ]]);

    $product = Product::query()->sole();

    expect($product->status)->toBe('inactive')
        ->and($product->reference)->toBe('PO-001')
        ->and($product->brand)->toBe('Feigler')
        ->and($product->base_price)->toEqual('85000.00')
        ->and($product->materials()->pluck('materials.name')->all())->toBe(['Algodón'])
        ->and((int) $product->materials()->first()->pivot->percentage)->toBe(100);

    $variants = $product->variants()->orderBy('size_id')->get();

    expect($variants->pluck('sku')->all())->toBe(['PO-001-M-ROJ', 'PO-001-L-ROJ'])
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->is_active))->toBeTrue()
        ->and($variants->pluck('stock')->all())->toBe([5, 0]);
});

test('the initial stock leaves a movement the same way the form does, and a stock of zero leaves none', function () {
    bulkFixture();
    $user = User::factory()->create();
    $file = bulkFile([
        bulkRow(['talla' => 'M', 'stock' => '7']),
        bulkRow(['talla' => 'L', 'stock' => '0']),
    ]);

    (new BulkProductImporter)->import($file, $user);

    $conStock = ProductVariant::query()->where('sku', 'PO-001-M-ROJ')->sole();
    $sinStock = ProductVariant::query()->where('sku', 'PO-001-L-ROJ')->sole();

    expect($conStock->inventoryMovements()->get())->toHaveCount(1)
        ->and($conStock->inventoryMovements()->sole()->only([
            'type', 'quantity', 'user_id', 'note',
        ]))->toBe([
            'type' => 'ajuste_entrada',
            'quantity' => 7,
            'user_id' => $user->getKey(),
            'note' => 'Stock inicial',
        ])
        ->and($sinStock->inventoryMovements()->count())->toBe(0);
});

test('the stock of a variant joins the inventory movements of the store like any other', function () {
    bulkFixture();
    $file = bulkFile([bulkRow(['stock' => '3'])]);

    (new BulkProductImporter)->import($file);

    expect(InventoryMovement::query()->sum('quantity'))->toBe(3);
});

test('an article with a mistake is left out and the rest of the file is still created', function () {
    bulkFixture();
    $file = bulkFile([
        bulkRow(['nombre' => 'Polo A', 'talla' => 'M']),
        bulkRow(['nombre' => 'Polo B', 'talla' => 'XXL']),
    ]);

    $result = (new BulkProductImporter)->import($file);

    expect($result['totals'])->toBe([
        'created_articles' => 1,
        'created_variants' => 1,
        'rejected_articles' => 1,
        'rejected_rows' => 1,
    ])
        ->and(array_column($result['created'], 'name'))->toBe(['Polo A'])
        ->and($result['rejected'][0]['name'])->toBe('Polo B')
        ->and($result['rejected'][0]['errors'][0]['column'])->toBe('talla')
        ->and(Product::query()->pluck('name')->all())->toBe(['Polo A']);
});

test('a variant that cannot be created takes the product and the other variants down with it', function () {
    bulkFixture();
    $file = bulkFile([
        bulkRow(['talla' => 'M', 'stock' => '4']),
        bulkRow(['talla' => 'L', 'stock' => '2']),
    ]);

    $importer = new BulkProductImporter(
        new BulkProductImportValidator,
        new CreateProduct,
        new BulkImporterFailingOnSecondVariant,
    );

    $result = $importer->import($file);

    expect($result['totals'])->toBe([
        'created_articles' => 0,
        'created_variants' => 0,
        'rejected_articles' => 1,
        'rejected_rows' => 2,
    ])
        ->and($result['rejected'][0]['name'])->toBe('Polo básico')
        ->and($result['rejected'][0]['rows'])->toBe([2, 3])
        ->and($result['rejected'][0]['errors'][0])->toBe([
            'row' => 2,
            'column' => BulkProductImporter::CREATION_COLUMN,
            'message' => 'Fallo simulado al crear la segunda variante',
        ]);

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('the same file confirmed twice is refused the second time and duplica nada', function () {
    bulkFixture();
    $file = bulkFile([bulkRow()]);

    $primero = (new BulkProductImporter)->import($file);
    $segundo = (new BulkProductImporter)->import($file);

    expect($primero['totals']['created_articles'])->toBe(1)
        ->and($segundo['totals']['created_articles'])->toBe(0)
        ->and($segundo['totals']['rejected_articles'])->toBe(1)
        ->and($segundo['rejected'][0]['errors'][0]['column'])->toBe('nombre')
        ->and($segundo['rejected'][0]['errors'][0]['message'])
        ->toBe('Ya existe un producto llamado «Polo básico» en la categoría «Polos».')
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);
});

test('the references of one load are numbered in a row and never repeat', function () {
    bulkFixture();
    $file = bulkFile([
        bulkRow(['nombre' => 'Polo A']),
        bulkRow(['nombre' => 'Polo B']),
        bulkRow(['nombre' => 'Polo C']),
    ]);

    $result = (new BulkProductImporter)->import($file);

    expect(array_column($result['created'], 'reference'))->toBe(['PO-001', 'PO-002', 'PO-003'])
        ->and(Product::query()->orderBy('id')->pluck('reference')->all())->toBe(['PO-001', 'PO-002', 'PO-003']);
});

test('a product of a load is in the catalog but not in the storefront', function () {
    bulkFixture();
    $file = bulkFile([bulkRow()]);

    (new BulkProductImporter)->import($file);

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->visible()->count())->toBe(0);
});

/**
 * A variant action that writes the first variant and then refuses the second, so a
 * test can see a load that breaks half way through an article.
 */
class BulkImporterFailingOnSecondVariant extends CreateProductVariant
{
    public int $calls = 0;

    public function __invoke(
        Product $product,
        int $sizeId,
        Color $color,
        ?string $price = null,
        int $stock = 0,
        ?User $user = null,
    ): ProductVariant {
        $this->calls++;

        if ($this->calls === 2) {
            throw new RuntimeException('Fallo simulado al crear la segunda variante');
        }

        return parent::__invoke($product, $sizeId, $color, $price, $stock, $user);
    }
}

/**
 * DuplicateImportedProductException y el validador tienen que decir lo mismo de un
 * nombre repetido: es el mismo motivo dicho en dos momentos de la carga.
 */
test('the word for a duplicate is the same in the preview and in the confirmation', function () {
    $fixture = bulkFixture();

    expect(DuplicateImportedProductException::forName('Polo básico', $fixture['category'])->getMessage())
        ->toBe('Ya existe un producto llamado «Polo básico» en la categoría «Polos».');
});
