<?php

use App\Exceptions\ImportFileEmptyException;
use App\Exceptions\ImportFileTooLargeException;
use App\Exceptions\ImportHeadersMissingException;
use App\Exceptions\ImportRowLimitExceededException;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Exceptions\UnknownColorException;
use App\Exceptions\UnknownMaterialException;
use App\Exceptions\UnsupportedImportFormatException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Services\ProductImport\BulkProductImportValidator;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A category of the store with the sizes, the color and the material the fixtures
 * write about, so a valid row has something to resolve to.
 *
 * @return array{category: Category, color: Color, material: Material, sizes: array<string, Size>}
 */
function importCatalog(): array
{
    $category = numberedCategory('PO', 'Polos');
    $color = Color::factory()->create(['name' => 'Rojo']);
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

/**
 * The column names a file has to name.
 *
 * @return list<string>
 */
function importHeaders(): array
{
    return ['genero', 'categoria', 'nombre', 'precio', 'materiales', 'talla', 'color', 'stock'];
}

/**
 * A row that resolves against the fixtures of `importCatalog`.
 *
 * @return array<string, mixed>
 */
function importRow(array $overrides = []): array
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

/**
 * The values of a row in the order the columns ask for them.
 *
 * @param  list<string>  $headers
 * @param  array<string, mixed>  $row
 * @return list<mixed>
 */
function orderedValues(array $headers, array $row): array
{
    return array_map(fn (string $header): mixed => $row[$header] ?? '', $headers);
}

/**
 * The text of a CSV with the headers stated and one line per row.
 *
 * @param  list<string>  $headers
 * @param  list<array<string, mixed>>  $rows
 */
function csvFrom(array $headers, array $rows, string $delimiter = ','): string
{
    $lines = [implode($delimiter, $headers)];

    foreach ($rows as $row) {
        $lines[] = implode($delimiter, array_map(
            fn (mixed $value): string => (string) $value,
            orderedValues($headers, $row),
        ));
    }

    return implode("\n", $lines)."\n";
}

/** A real file in the temp directory, remembered so the tests clean it up. */
function importFile(string $extension, string $contents): string
{
    $GLOBALS['importFiles'][] = $path = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'feigler-import-'.bin2hex(random_bytes(8)).'.'.$extension;

    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    foreach ($GLOBALS['importFiles'] ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $GLOBALS['importFiles'] = [];
});

test('every row of one article becomes a variant of it', function () {
    $catalog = importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['talla' => 'M']),
        importRow(['talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals'])->toBe([
        'ready_articles' => 1,
        'ready_variants' => 2,
        'rejected_articles' => 0,
        'rejected_rows' => 0,
    ])
        ->and($preview['rejected'])->toBe([])
        ->and($preview['ready'])->toHaveCount(1);

    $article = $preview['ready'][0];

    expect($article['section'])->toBe('hombre')
        ->and($article['section_label'])->toBe('Hombre')
        ->and($article['category'])->toBe('Polos')
        ->and($article['name'])->toBe('Polo básico')
        ->and($article['brand'])->toBe('Feigler')
        ->and($article['price'])->toBe('85000')
        ->and($article['materials'])->toBe([
            ['id' => $catalog['material']->getKey(), 'name' => 'Algodón', 'percentage' => 100],
        ])
        ->and(array_column($article['variants'], 'size'))->toBe(['M', 'L'])
        ->and(array_column($article['variants'], 'row'))->toBe([2, 3])
        ->and(array_column($article['variants'], 'stock'))->toBe([5, 5]);
});

test('rows of one article are still one article when another sits between them', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['nombre' => 'Polo A', 'talla' => 'M']),
        importRow(['nombre' => 'Polo B', 'talla' => 'M']),
        importRow(['nombre' => 'Polo A', 'talla' => 'L']),
        importRow(['nombre' => 'Polo B', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals'])->toBe([
        'ready_articles' => 2,
        'ready_variants' => 4,
        'rejected_articles' => 0,
        'rejected_rows' => 0,
    ])
        ->and(array_column($preview['ready'], 'name'))->toBe(['Polo A', 'Polo B'])
        ->and(count($preview['ready'][1]['variants']))->toBe(2);
});

test('a name written with other letters is still the same article', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['nombre' => 'Polo  basico', 'talla' => 'M']),
        importRow(['nombre' => 'POLO BÁSICO', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1)
        ->and($preview['totals']['ready_variants'])->toBe(2);
});

test('one bad row refuses the whole article', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['talla' => 'M']),
        importRow(['talla' => 'XXL']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['ready'])->toBe([])
        ->and($preview['rejected'])->toHaveCount(1)
        ->and($preview['rejected'][0]['name'])->toBe('Polo básico')
        ->and($preview['rejected'][0]['rows'])->toBe([2, 3])
        ->and($preview['rejected'][0]['errors'][0])->toBe(['row' => 3, 'column' => 'talla', 'message' => 'La talla «XXL» no existe en la categoría «Polos». Tallas disponibles: M, L.']);
});

test('a row with no name is refused on its own', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['talla' => 'M']),
        importRow(['nombre' => '', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals'])->toBe([
        'ready_articles' => 1,
        'ready_variants' => 1,
        'rejected_articles' => 1,
        'rejected_rows' => 1,
    ])
        ->and($preview['rejected'][0]['name'])->toBe('(sin nombre)')
        ->and($preview['rejected'][0]['rows'])->toBe([3]);
});

test('a gender that is not one of the three is refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['genero' => 'Marciano'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['rejected_articles'])->toBe(1)
        ->and($preview['rejected'][0]['errors'][0]['column'])->toBe('genero');
});

test('a category that is not in the gender is refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['categoria' => 'Zapatos'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['rejected_articles'])->toBe(1)
        ->and($preview['rejected'][0]['errors'][0]['column'])->toBe('categoria');
});

test('a color that does not exist is refused with the name that came', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['color' => 'Fucsia'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'color',
        'message' => UnknownColorException::forColor('Fucsia')->getMessage(),
    ]);
});

test('a size that was turned off is refused with the message of the panel', function () {
    $catalog = importCatalog();
    $xl = Size::factory()->for($catalog['category'])->inactive()->create(['name' => 'XL']);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['talla' => 'XL'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'talla',
        'message' => InactiveVariantSizeException::forSize($xl)->getMessage(),
    ]);
});

test('a material that does not exist is refused with the name that came', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['materiales' => 'Lino 100%'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => UnknownMaterialException::forMaterial('Lino')->getMessage(),
    ]);
});

test('a composition that does not add up to a whole is refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['materiales' => 'Algodón 80%'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => IncompleteMaterialCompositionException::forTotal(80)->getMessage(),
    ]);
});

test('a share that is not a whole percent is refused', function () {
    $catalog = importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['materiales' => 'Algodón 150%'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => InvalidMaterialPercentageException::forPercentage($catalog['material'], '150')->getMessage(),
    ]);
});

test('a material named twice is refused once, without also being accused of not adding up', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['materiales' => 'Algodón 50% | Algodón 50%'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'])->toHaveCount(1)
        ->and($preview['rejected'][0]['errors'][0]['message'])->toBe(
            RepeatedProductMaterialException::forMaterial(Material::where('name', 'Algodón')->firstOrFail())->getMessage(),
        );
});

test('a price that cannot be a price is refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [importRow(['precio' => 'sin precio'])]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0]['column'])->toBe('precio');
});

test('two rows of one article that do not agree on a price are refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['precio' => '85000', 'talla' => 'M']),
        importRow(['precio' => '90000', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'precio',
        'message' => 'El precio no coincide entre las filas del mismo artículo.',
    ]);
});

test('two rows writing the same amount in different ways are not a disagreement', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['precio' => '85000', 'talla' => 'M']),
        importRow(['precio' => '85.000', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1);
});

test('the same size and color twice in one article is refused', function () {
    importCatalog();
    $file = importFile('csv', csvFrom(importHeaders(), [
        importRow(['talla' => 'M']),
        importRow(['talla' => 'M']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 3,
        'column' => 'color',
        'message' => 'La talla «M» con el color «Rojo» está repetida en este artículo.',
    ]);
});

test('a name a product of the same category already uses is refused', function () {
    $catalog = importCatalog();
    Product::factory()->for($catalog['category'])->create(['name' => 'Polo básico']);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'nombre',
        'message' => 'Ya existe un producto llamado «Polo básico» en la categoría «Polos».',
    ]);
});

test('the same name in another category is a different article', function () {
    importCatalog();
    numberedCategory('BU', 'Buzos');
    Product::factory()->for(Category::where('name', 'Buzos')->firstOrFail())->create(['name' => 'Polo básico']);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1);
});

test('rows of one article that disagree on a column are refused', function () {
    importCatalog();
    $headers = [...importHeaders(), 'marca'];
    $file = importFile('csv', csvFrom($headers, [
        importRow(['marca' => 'Feigler', 'talla' => 'M']),
        importRow(['marca' => 'Nike', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 3,
        'column' => 'marca',
        'message' => 'Este dato no coincide con la primera fila de la misma prenda (fila 2).',
    ]);
});

test('a row that leaves the brand out agrees with one that writes the brand of the store', function () {
    importCatalog();
    $headers = [...importHeaders(), 'marca'];
    $file = importFile('csv', csvFrom($headers, [
        importRow(['marca' => 'Feigler', 'talla' => 'M']),
        importRow(['marca' => '', 'talla' => 'L']),
    ]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1);
});

test('a file that does not name a column the import needs is refused', function () {
    importCatalog();
    $headers = importHeaders();
    array_splice($headers, 3, 1);
    $file = importFile('csv', csvFrom($headers, [importRow()]));

    expect(fn () => (new BulkProductImportValidator)->validate($file))
        ->toThrow(ImportHeadersMissingException::class);
});

test('a file with nothing in it is refused', function () {
    expect(fn () => (new BulkProductImportValidator)->validate(importFile('csv', '')))
        ->toThrow(ImportFileEmptyException::class);
});

test('a file the import does not know how to open is refused', function () {
    expect(fn () => (new BulkProductImportValidator)->validate(importFile('txt', 'a,b')))
        ->toThrow(UnsupportedImportFormatException::class);
});

test('a file over the size limit is refused', function () {
    config(['bulk_import.max_file_size_kb' => 1]);

    expect(fn () => (new BulkProductImportValidator)->validate(importFile('csv', str_repeat('a', 2048))))
        ->toThrow(ImportFileTooLargeException::class);
});

test('a file with more rows than allowed is refused', function () {
    importCatalog();
    config(['bulk_import.max_rows' => 3]);
    $rows = [];

    foreach (range(1, 4) as $number) {
        $rows[] = importRow(['nombre' => "Polo {$number}"]);
    }

    $file = importFile('csv', csvFrom(importHeaders(), $rows));

    expect(fn () => (new BulkProductImportValidator)->validate($file))
        ->toThrow(ImportRowLimitExceededException::class);
});

test('a CSV that a Spanish Excel would export, semicolons and Windows-1252, is read', function () {
    $catalog = importCatalog();
    $csv = csvFrom(importHeaders(), [importRow(['nombre' => 'Polo básico'])], ';');
    $file = importFile('csv', mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1)
        ->and($preview['ready'][0]['name'])->toBe('Polo básico')
        ->and($preview['ready'][0]['materials'][0]['name'])->toBe('Algodón');
});

test('a CSV with a UTF-8 BOM is read', function () {
    importCatalog();
    $file = importFile('csv', "\xEF\xBB\xBF".csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1);
});

test('an Excel file is read like a CSV, numbers and all', function () {
    importCatalog();
    $headers = importHeaders();
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'feigler-import-'.bin2hex(random_bytes(8)).'.xlsx';
    $GLOBALS['importFiles'][] = $path;

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));
    $writer->addRow(Row::fromValues(orderedValues($headers, importRow(['precio' => 85000, 'stock' => 5]))));
    $writer->close();

    $preview = (new BulkProductImportValidator)->validate($path);

    expect($preview['totals']['ready_articles'])->toBe(1)
        ->and($preview['ready'][0]['price'])->toBe('85000')
        ->and($preview['ready'][0]['variants'][0]['stock'])->toBe(5);
});

test('reading five hundred rows asks the database a handful of queries, not one per row', function () {
    importCatalog();
    $rows = [];

    foreach (range(1, 500) as $number) {
        $rows[] = importRow(['nombre' => "Polo {$number}"]);
    }

    $file = importFile('csv', csvFrom(importHeaders(), $rows));

    DB::enableQueryLog();
    $preview = (new BulkProductImportValidator)->validate($file);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($preview['totals']['ready_articles'])->toBe(500)
        ->and($preview['totals']['ready_variants'])->toBe(500)
        ->and($queries)->toBeLessThanOrEqual(10);
});

test('a file whose headers are written with accents, capitals and spaces is read', function () {
    importCatalog();
    $csv = implode(',', ['  Género', 'CATEGORÍA', 'Nombre ', 'Precio', 'MATERIALES', 'Talla', 'Color', 'Stock'])."\n"
        .'hombre,Polos,Polo básico,85000,Algodón 100%,M,Rojo,5'."\n";
    $file = importFile('csv', $csv);

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1)
        ->and($preview['ready'][0]['name'])->toBe('Polo básico');
});

test('an article in a category that was turned off is accepted, the way the panel still offers it', function () {
    $catalog = importCatalog();
    $catalog['category']->update(['is_active' => false]);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['totals']['ready_articles'])->toBe(1)
        ->and($preview['ready'][0]['category'])->toBe('Polos');
});

test('a color that was turned off is refused with the message of the panel', function () {
    $catalog = importCatalog();
    $catalog['color']->update(['is_active' => false]);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'color',
        'message' => InactiveVariantColorException::forColor($catalog['color'])->getMessage(),
    ]);
});

test('a material that was turned off is refused with the message of the panel', function () {
    $catalog = importCatalog();
    $catalog['material']->update(['is_active' => false]);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => InactiveProductMaterialException::forMaterial($catalog['material'])->getMessage(),
    ]);
});

test('a composition written with commas is refused and told to write it with | or /', function () {
    importCatalog();
    $csv = implode(',', importHeaders())."\n"
        .'hombre,Polos,Polo básico,85000,"Algodón 80%, Poliéster 20%",M,Rojo,5'."\n";
    $file = importFile('csv', $csv);

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => UnknownMaterialException::forMaterial('Algodón 80, Poliéster')->getMessage()
            .' Separa los materiales con | o / (ejemplo: «80% Algodón | 20% Poliéster»).',
    ]);
});

test('a composition whose shares are decimals written with a comma is refused, with the comma hint', function () {
    $catalog = importCatalog();
    Material::factory()->create(['name' => 'Poliéster']);
    $csv = implode(',', importHeaders())."\n"
        .'hombre,Polos,Polo básico,85000,"Algodón 62,5% / Poliéster 37,5%",M,Rojo,5'."\n";
    $file = importFile('csv', $csv);

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'materiales',
        'message' => InvalidMaterialPercentageException::forPercentage($catalog['material'], '62,5')->getMessage()
            .' Separa los materiales con | o / (ejemplo: «80% Algodón | 20% Poliéster»).',
    ]);
});

test('a name a product of the same category already uses is refused even when that product is turned off', function () {
    $catalog = importCatalog();
    Product::factory()->for($catalog['category'])->inactive()->create(['name' => 'Polo básico']);
    $file = importFile('csv', csvFrom(importHeaders(), [importRow()]));

    $preview = (new BulkProductImportValidator)->validate($file);

    expect($preview['rejected'][0]['errors'][0])->toBe([
        'row' => 2,
        'column' => 'nombre',
        'message' => 'Ya existe un producto llamado «Polo básico» en la categoría «Polos».',
    ]);
});
