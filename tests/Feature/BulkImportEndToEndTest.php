<?php

use App\Enums\StoreSection;
use App\Exceptions\ImportFileTooLargeException;
use App\Exceptions\ImportHeadersMissingException;
use App\Exceptions\ImportRowLimitExceededException;
use App\Livewire\Admin\BulkUpload;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use App\Services\ProductImport\BulkImportFileStore;
use App\Services\ProductImport\BulkImportReport;
use App\Services\ProductImport\BulkProductImporter;
use App\Services\ProductImport\BulkProductImportValidator;
use App\Services\Storefront\ListingPage;
use App\Services\Storefront\ListingScope;
use App\Services\Storefront\ProductCards;
use App\Services\Storefront\SectionFilters;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
|--------------------------------------------------------------------------
| Escena
|--------------------------------------------------------------------------
|
| Un archivo de verdad, del tamaño de los que sube la tienda, con artículos
| válidos de las tres secciones y una colección de errores de todos los tipos
| que la carga sabe rechazar:
|
|   válidos   Camisa E2E            Hombre/Camisas     S-Negro(5) M-Azul(3) L-Rojo(0)
|             Pantalón E2E         Hombre/Pantalones  42-Negro(4) 44-Azul(2)
|             Vestido E2E          Mujer/Vestidos     S-Azul(1) M-Rojo(2)
|             Camisa Apagada OK    Hombre/Apagadas    M-Negro(1)   (categoría desactivada)
|             Camiseta Niño        Niños/Infantil     XS-Negro(2) S-Azul(1)
|             Camisa Marca Propia  Hombre/Camisas     S-Azul(1) M-Negro(1) L-Rojo(1)
|
|   errores   talla desactivada, talla inexistente, material inexistente,
|             material desactivado, composición que no suma 100, composición en
|             el orden inverso, materiales separados con coma, precio ilegible,
|             artículo ya existente, talla+color repetidos, categoría de otro
|             género, color inexistente, color desactivado, stock negativo y
|             fila sin nombre.
|
| El mismo contenido se prueba por CSV (separador «;», BOM) y por XLSX, y tiene
| que describir exactamente el mismo resultado por las dos puertas.
*/

/**
 * El catálogo contra el que se resuelven las filas: las categorías y tallas de
 * cada sección, los colores y materiales (uno de cada apagado), una categoría
 * desactivada y una prenda que ya existe para que el archivo la repita.
 *
 * @return array<string, Category>
 */
function e2eCatalog(): array
{
    $camisas = Category::factory()->section(StoreSection::Hombre)->create(['sku_prefix' => 'E2C', 'name' => 'Camisas']);
    $pantalones = Category::factory()->section(StoreSection::Hombre)->create(['sku_prefix' => 'E2P', 'name' => 'Pantalones']);
    $apagadas = Category::factory()->section(StoreSection::Hombre)->inactive()->create(['sku_prefix' => 'E2A', 'name' => 'Apagadas']);
    $vestidos = Category::factory()->section(StoreSection::Mujer)->create(['sku_prefix' => 'E2V', 'name' => 'Vestidos']);
    $infantil = Category::factory()->section(StoreSection::Ninos)->create(['sku_prefix' => 'E2I', 'name' => 'Infantil']);

    // Sizes, colors and materials are written straight to their tables and not through
    // their factories: the factories draw from a handful of `fake()->unique()` values,
    // and a scene that needs twelve sizes would exhaust them before it finished.
    foreach ([[$camisas, 'S', 1], [$camisas, 'M', 2], [$camisas, 'L', 3]] as [$category, $name, $order]) {
        e2eSize($category, $name, $order);
    }

    e2eSize($camisas, 'XXL', 4, active: false);

    foreach ([[$pantalones, '42', 1], [$pantalones, '44', 2], [$apagadas, 'M', 1], [$vestidos, 'S', 1], [$vestidos, 'M', 2], [$infantil, 'XS', 1], [$infantil, 'S', 2]] as [$category, $name, $order]) {
        e2eSize($category, $name, $order);
    }

    e2eColor('Negro', '#000000', 'NEG', 1);
    e2eColor('Azul', '#0000ff', 'AZU', 2);
    e2eColor('Rojo', '#ff0000', 'ROJ', 3);
    e2eColor('Verde', '#00ff00', 'VER', 4, active: false);

    e2eMaterial('Algodón', 1);
    e2eMaterial('Poliéster', 2);
    e2eMaterial('Lino', 3);
    e2eMaterial('Lana', 4, active: false);

    Product::factory()->for($camisas)->create(['name' => 'Camisa Existente', 'base_price' => 50000]);

    return ['camisas' => $camisas, 'pantalones' => $pantalones, 'vitrina' => $apagadas, 'vestidos' => $vestidos, 'infantil' => $infantil];
}

function e2eSize(Category $category, string $name, int $order, bool $active = true): void
{
    Size::query()->create([
        'category_id' => $category->getKey(),
        'name' => $name,
        'order' => $order,
        'is_active' => $active,
    ]);
}

function e2eColor(string $name, string $hex, string $code, int $order, bool $active = true): void
{
    Color::query()->create([
        'name' => $name,
        'hex' => $hex,
        'code' => $code,
        'order' => $order,
        'is_active' => $active,
    ]);
}

function e2eMaterial(string $name, int $order, bool $active = true): void
{
    Material::query()->create([
        'name' => $name,
        'order' => $order,
        'is_active' => $active,
    ]);
}

/** @return list<string> */
function e2eHeaders(): array
{
    return ['genero', 'categoria', 'nombre', 'marca', 'precio', 'materiales', 'talla', 'color', 'stock'];
}

/** Una fila del archivo, con todas sus columnas. @return array<string, string> */
function e2eLine(string $genero, string $categoria, string $nombre, string $marca, string $precio, string $materiales, string $talla, string $color, string $stock): array
{
    return compact('genero', 'categoria', 'nombre', 'marca', 'precio', 'materiales', 'talla', 'color', 'stock');
}

/** @return list<array<string, string>> */
function e2eRows(): array
{
    return [
        // válidas
        e2eLine('Hombre', 'Camisas', 'Camisa E2E', 'Feigler', '85000', 'Algodón 80% | Poliéster 20%', 'S', 'Negro', '5'),
        e2eLine('Hombre', 'Camisas', 'Camisa E2E', 'Feigler', '85000', 'Algodón 80% | Poliéster 20%', 'M', 'Azul', '3'),
        e2eLine('Hombre', 'Camisas', 'Camisa E2E', 'Feigler', '85000', 'Algodón 80% | Poliéster 20%', 'L', 'Rojo', '0'),
        e2eLine('Hombre', 'Pantalones', 'Pantalón E2E', '', '150000', 'Lino 100%', '42', 'Negro', '4'),
        e2eLine('Hombre', 'Pantalones', 'Pantalón E2E', '', '150000', 'Lino 100%', '44', 'Azul', '2'),
        e2eLine('Mujer', 'Vestidos', 'Vestido E2E', 'Marquita', '45000', 'Algodón 60% | Poliéster 40%', 'S', 'Azul', '1'),
        e2eLine('Mujer', 'Vestidos', 'Vestido E2E', 'Marquita', '45000', 'Algodón 60% | Poliéster 40%', 'M', 'Rojo', '2'),
        e2eLine('Hombre', 'Apagadas', 'Camisa Apagada OK', '', '30000', 'Algodón 100%', 'M', 'Negro', '1'),
        e2eLine('Niños', 'Infantil', 'Camiseta Niño', '', '25000', 'Algodón 100%', 'XS', 'Negro', '2'),
        e2eLine('Niños', 'Infantil', 'Camiseta Niño', '', '25000', 'Algodón 100%', 'S', 'Azul', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Marca Propia', 'Zara', '99000', 'Algodón 100%', 'S', 'Azul', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Marca Propia', 'Zara', '99000', 'Algodón 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Marca Propia', 'Zara', '99000', 'Algodón 100%', 'L', 'Rojo', '1'),

        // errores (cada fila de aquí abajo es un artículo que no se puede crear)
        e2eLine('Hombre', 'Camisas', 'Camisa Talla Apagada', '', '50000', 'Algodón 100%', 'XXL', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Talla Fantasma', '', '50000', 'Algodón 100%', 'ZZ', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Material Malo', '', '50000', 'Seda 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Material Apagado', '', '50000', 'Lana 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Suma Mala', '', '50000', 'Algodón 60% | Poliéster 30%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Orden Malo', '', '50000', '80% Algodón | 20% Poliéster', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Coma Separador', '', '50000', 'Algodón 50%, Poliéster 50%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Precio Malo', '', 'abc', 'Algodón 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Existente', '', '50000', 'Algodón 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Repetida', '', '50000', 'Algodón 100%', 'M', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Repetida', '', '50000', 'Algodón 100%', 'M', 'Negro', '2'),
        e2eLine('Hombre', 'Vestidos', 'Vestido Genero Malo', '', '50000', 'Algodón 100%', 'S', 'Negro', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Color Malo', '', '50000', 'Algodón 100%', 'M', 'Naranja', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Color Apagado', '', '50000', 'Algodón 100%', 'M', 'Verde', '1'),
        e2eLine('Hombre', 'Camisas', 'Camisa Stock Malo', '', '50000', 'Algodón 100%', 'M', 'Negro', '-3'),
        e2eLine('Hombre', 'Camisas', '', '', '50000', 'Algodón 100%', 'M', 'Negro', '1'),
    ];
}

/** Los artículos que el archivo tiene que crear, con la cuenta de sus variantes. @return array<string, int> */
function e2eValidArticles(): array
{
    return [
        'Camisa E2E' => 3,
        'Pantalón E2E' => 2,
        'Vestido E2E' => 2,
        'Camisa Apagada OK' => 1,
        'Camiseta Niño' => 2,
        'Camisa Marca Propia' => 3,
    ];
}

/** Los nombres de los artículos que el archivo tiene que rechazar. @return list<string> */
function e2eInvalidNames(): array
{
    return [
        'Camisa Talla Apagada', 'Camisa Talla Fantasma', 'Camisa Material Malo', 'Camisa Material Apagado',
        'Camisa Suma Mala', 'Camisa Orden Malo', 'Camisa Coma Separador', 'Camisa Precio Malo',
        'Camisa Existente', 'Camisa Repetida', 'Vestido Genero Malo', 'Camisa Color Malo',
        'Camisa Color Apagado', 'Camisa Stock Malo', '(sin nombre)',
    ];
}

/**
 * Los motivos que el archivo tiene que reportar, como pares [fila, columna]
 * ordenados por la fila del archivo.
 *
 * @return list<array{0: int, 1: string}>
 */
function e2eExpectedErrors(): array
{
    return [
        [15, 'talla'], [16, 'talla'], [17, 'materiales'], [18, 'materiales'],
        [19, 'materiales'], [20, 'materiales'], [21, 'materiales'], [22, 'precio'],
        [23, 'nombre'], [25, 'color'], [26, 'categoria'], [27, 'color'],
        [28, 'color'], [29, 'stock'], [30, 'nombre'],
    ];
}

function e2eCsvContent(array $rows): string
{
    $lines = [implode(';', e2eHeaders())];

    foreach ($rows as $row) {
        $lines[] = implode(';', array_map(
            fn (string $header): string => (string) ($row[$header] ?? ''),
            e2eHeaders(),
        ));
    }

    return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
}

function e2eTempPath(string $extension): string
{
    $GLOBALS['e2eFiles'][] = $path = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'feigler-e2e-'.bin2hex(random_bytes(8)).'.'.$extension;

    return $path;
}

function e2eCsvFile(array $rows): string
{
    $path = e2eTempPath('csv');
    file_put_contents($path, e2eCsvContent($rows));

    return $path;
}

function e2eXlsxFile(array $rows): string
{
    $path = e2eTempPath('xlsx');

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(e2eHeaders()));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues(array_map(
            fn (string $header): string => (string) ($row[$header] ?? ''),
            e2eHeaders(),
        )));
    }

    $writer->close();

    return $path;
}

afterEach(function () {
    foreach ($GLOBALS['e2eFiles'] ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $GLOBALS['e2eFiles'] = [];
});

/**
 * Las comprobaciones que el archivo tiene que pasar, sea por CSV o por XLSX.
 */
function e2eAssertResult(array $result, User $admin): void
{
    expect($result['totals'])->toBe([
        'created_articles' => 6,
        'created_variants' => 13,
        'rejected_articles' => 15,
        'rejected_rows' => 16,
    ]);

    // Los artículos creados, con la referencia que les tocó en su serie: las
    // referencias de una misma categoría van seguidas y no repiten.
    expect(array_map(
        fn (array $article): array => [$article['name'], $article['reference']],
        $result['created'],
    ))->toBe([
        ['Camisa E2E', 'E2C-002'],
        ['Pantalón E2E', 'E2P-001'],
        ['Vestido E2E', 'E2V-001'],
        ['Camisa Apagada OK', 'E2A-001'],
        ['Camiseta Niño', 'E2I-001'],
        ['Camisa Marca Propia', 'E2C-003'],
    ]);

    // «Camisa Existente» ya estaba; los demás rechazados no dejan ni un producto.
    $shouldNotExist = array_diff(e2eInvalidNames(), ['Camisa Existente', '(sin nombre)']);

    expect(Product::query()->whereIn('name', $shouldNotExist)->count())->toBe(0)
        ->and(Product::query()->where('name', 'Camisa Repetida')->count())->toBe(0);

    // Cada artículo creado nace inactivo y con sus variantes activas.
    foreach (e2eValidArticles() as $name => $variants) {
        $product = Product::query()->where('name', $name)->sole();

        expect($product->status)->toBe('inactive')
            ->and($product->variants()->count())->toBe($variants)
            ->and($product->variants()->where('is_active', true)->count())->toBe($variants);
    }

    expect(Product::query()->where('name', 'Pantalón E2E')->sole()->brand)->toBe('Feigler')
        ->and(Product::query()->where('name', 'Vestido E2E')->sole()->brand)->toBe('Marquita')
        ->and(Product::query()->where('name', 'Camisa Marca Propia')->sole()->brand)->toBe('Zara');

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();

    expect((int) $camisa->materials()->where('name', 'Algodón')->sole()->pivot->percentage)->toBe(80)
        ->and((int) $camisa->materials()->where('name', 'Poliéster')->sole()->pivot->percentage)->toBe(20);

    // El stock inicial deja un movimiento por variante con stock, del admin que confirmó.
    $movements = InventoryMovement::query()->get();

    expect($movements)->toHaveCount(12)
        ->and($movements->every(fn (InventoryMovement $movement): bool => $movement->user_id === $admin->getKey()))->toBeTrue()
        ->and((int) $movements->sum('quantity'))->toBe(24);

    // Los motivos del rechazo, uno por celda rota, en la fila que le toca.
    $errors = collect($result['rejected'])
        ->flatMap(fn (array $article): array => $article['errors'])
        ->map(fn (array $error): array => [$error['row'], $error['column']])
        ->all();
    usort($errors, fn (array $a, array $b): int => $a[0] <=> $b[0]);

    expect($errors)->toBe(e2eExpectedErrors());

    // El reporte descargable dice exactamente lo mismo.
    $report = (new BulkImportReport)->csv($result['rejected']);
    $lines = e2eReportLines($report);

    expect($lines)->toHaveCount(15)
        ->and(array_map(fn (array $line): array => [(int) $line[0], $line[2]], $lines))->toBe(e2eExpectedErrors());
}

/**
 * Las líneas de datos del reporte CSV, sin la cabecera ni el BOM.
 *
 * @return list<array<int, string|null>>
 */
function e2eReportLines(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = array_values(array_filter(explode("\n", trim($csv)), fn (string $line): bool => $line !== ''));

    array_shift($lines);

    return array_map(fn (string $line): array => str_getcsv($line, ';'), $lines);
}

test('el archivo de verdad entra entero por CSV: lo válido se crea y lo inválido no deja rastro', function () {
    e2eCatalog();
    $admin = User::factory()->create();

    e2eAssertResult((new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin), $admin);
});

test('el mismo archivo por XLSX describe exactamente el mismo resultado', function () {
    e2eCatalog();
    $admin = User::factory()->create();

    e2eAssertResult((new BulkProductImporter)->import(e2eXlsxFile(e2eRows()), $admin), $admin);
});

test('una categoría desactivada sí recibe la carga, igual que el formulario', function () {
    $scene = e2eCatalog();
    $admin = User::factory()->create();

    $file = e2eCsvFile([
        e2eLine('Hombre', 'Apagadas', 'Solo Apagada', '', '20000', 'Algodón 100%', 'M', 'Negro', '2'),
    ]);

    $result = (new BulkProductImporter)->import($file, $admin);

    expect($result['totals']['created_articles'])->toBe(1);

    $product = Product::query()->where('name', 'Solo Apagada')->sole();

    expect($product->category_id)->toBe($scene['vitrina']->getKey())
        ->and($product->status)->toBe('inactive')
        ->and($product->variants()->count())->toBe(1);
});

test('la carga pasa por la pantalla de principio a fin y no deja el archivo temporal', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    e2eCatalog();

    $admin = adminForPanel();

    $panel = Livewire::actingAs($admin)->test(BulkUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent('carga.csv', e2eCsvContent(e2eRows())))
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->assertSet('preview.totals.ready_articles', 6)
        ->assertSet('preview.totals.ready_variants', 13)
        ->assertSet('preview.totals.rejected_articles', 15)
        ->assertSet('preview.totals.rejected_rows', 16);

    $stored = $panel->get('storedPath');

    expect($stored)->not->toBeNull()
        ->and(Storage::disk('local')->exists($stored))->toBeTrue();

    $panel->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT)
        ->assertSet('result.totals.created_articles', 6)
        ->assertSet('result.totals.created_variants', 13)
        ->assertSet('result.totals.rejected_articles', 15)
        ->assertSet('result.totals.rejected_rows', 16);

    expect(Storage::disk('local')->exists($stored))->toBeFalse()
        ->and(Product::query()->where('name', 'Camisa E2E')->sole()->variants()->count())->toBe(3);
});

test('un archivo de más de dos mil filas se rechaza sin leer la fila que sobra', function () {
    e2eCatalog();

    $rows = [];

    for ($n = 1; $n <= 2001; $n++) {
        $rows[] = e2eLine('Hombre', 'Camisas', 'Relleno '.$n, '', '1000', 'Algodón 100%', 'S', 'Negro', '1');
    }

    expect(fn (): array => (new BulkProductImportValidator)->validate(e2eCsvFile($rows)))
        ->toThrow(ImportRowLimitExceededException::class);
});

test('un archivo de más de dos megabytes se rechaza por peso antes de abrirlo', function () {
    $path = e2eTempPath('csv');
    file_put_contents($path, str_repeat('x', 2 * 1024 * 1024 + 1024));

    expect(filesize($path))->toBeGreaterThan(2 * 1024 * 1024)
        ->and(fn (): array => (new BulkProductImportValidator)->validate($path))
        ->toThrow(ImportFileTooLargeException::class);
});

test('a un archivo que no nombra una columna obligatoria se le dice cuál falta', function () {
    $headers = array_values(array_diff(e2eHeaders(), ['precio']));
    $row = e2eLine('Hombre', 'Camisas', 'Sin Precio', '', '80000', 'Algodón 100%', 'S', 'Negro', '1');

    $lines = [implode(';', $headers)];

    foreach ($headers as $header) {
        $lines[] = $row[$header] ?? '';
    }

    $path = e2eTempPath('csv');
    file_put_contents($path, implode("\r\n", $lines)."\r\n");

    expect(fn (): array => (new BulkProductImportValidator)->validate($path))
        ->toThrow(ImportHeadersMissingException::class);
});

test('un artículo cargado nace oculto en la tienda y se publica al activarlo desde el panel', function () {
    $this->app->instance('env', 'local');
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();

    expect($camisa->status)->toBe('inactive');

    $this->get('/')->assertOk()->assertDontSee($camisa->name);
    $this->get('/hombre')->assertOk()->assertDontSee($camisa->name);
    $this->get('/novedades')->assertOk()->assertDontSee($camisa->name);
    $this->get('/tienda')->assertOk()->assertDontSee($camisa->name);
    $this->get('/buscar?q='.urlencode($camisa->name))->assertOk()->assertDontSee(route('storefront.product', $camisa->slug));
    $this->get(route('storefront.product', $camisa->slug))->assertNotFound();

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('toggleActive', $camisa->getKey());

    expect($camisa->refresh()->status)->toBe('active');

    $this->get(route('storefront.product', $camisa->slug))->assertOk()->assertSee($camisa->name);
    $this->get('/tienda')->assertOk()->assertSee($camisa->name);
    $this->get('/buscar?q='.urlencode($camisa->name))->assertOk()->assertSee(route('storefront.product', $camisa->slug));
});

test('el artículo cargado aparece en el panel como inactivo, con su stock y su marca', function () {
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();

    expect($camisa->status)->toBe('inactive')
        ->and($camisa->brand)->toBe('Feigler')
        ->and($camisa->stock_total)->toBe(8)
        ->and($camisa->display_status)->toBe('inactive');

    Livewire::actingAs($admin)->test(ProductsIndex::class)
        ->set('search', 'Camisa E2E')
        ->assertSee('Camisa E2E');
});

test('la pantalla no guarda nada cuando el archivo supera el máximo', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    e2eCatalog();

    Livewire::actingAs(adminForPanel())->test(BulkUpload::class)
        ->set('file', UploadedFile::fake()->create('grande.csv', 3000))
        ->call('review')
        ->assertHasErrors(['file' => 'max'])
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('storedPath', null);

    expect(Storage::disk('local')->files(BulkImportFileStore::DIRECTORY))->toBe([]);
});

test('un artículo cargado no aparece en su sección y aparece al activarlo, sin colarse en las otras', function () {
    $this->app->instance('env', 'local');
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();
    $vestido = Product::query()->where('name', 'Vestido E2E')->sole();
    $camiseta = Product::query()->where('name', 'Camiseta Niño')->sole();

    // Cada prenda nace oculta en su propia sección, y ninguna asoma en otra.
    $this->get('/hombre')->assertOk()->assertDontSee($camisa->name);
    $this->get('/mujer')->assertOk()->assertDontSee($vestido->name);
    $this->get('/ninos')->assertOk()->assertDontSee($camiseta->name);
    $this->get('/tienda')->assertOk()->assertDontSee($camisa->name);

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('toggleActive', $vestido->getKey());

    // Publicada la de mujer, su sección la muestra y las demás siguen sin verla.
    $this->get('/mujer')->assertOk()->assertSee($vestido->name);
    $this->get('/hombre')->assertOk()->assertDontSee($vestido->name);
    $this->get('/ninos')->assertOk()->assertDontSee($vestido->name);
});

test('los relacionados de una ficha no ofrecen prendas sin publicar', function () {
    $this->app->instance('env', 'local');
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();
    $hermana = Product::query()->where('name', 'Camisa Marca Propia')->sole();

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('toggleActive', $camisa->getKey());

    // La hermana de categoría sigue inactiva: no es una relacionada.
    $this->get(route('storefront.product', $camisa->slug))
        ->assertOk()
        ->assertSee($camisa->name)
        ->assertDontSee($hermana->name);

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('toggleActive', $hermana->getKey());

    $this->get(route('storefront.product', $camisa->slug))
        ->assertOk()
        ->assertSee('Más de')
        ->assertSee($hermana->name);
});

test('los conteos de los filtros no cuentan los artículos cargados mientras están inactivos', function () {
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisas = Category::query()->where('name', 'Camisas')->sole();
    $listing = app(ListingPage::class);
    $hombre = fn (): array => $listing->for(
        ListingScope::section(StoreSection::Hombre),
        SectionFilters::fromRequest(Request::create('/hombre')),
    );

    // Ninguna prenda publicada: el listado está vacío y no ofrece categorías.
    $page = $hombre();

    expect($page['total'])->toBe(0)
        ->and($page['filters']['categories'])->toBe([]);

    Livewire::actingAs($admin)->test(ProductsIndex::class)
        ->call('toggleActive', Product::query()->where('name', 'Camisa E2E')->sole()->getKey());

    // Una publicada: el total y el conteo de su categoría suben a 1, y no a las
    // tres variantes del artículo ni a las otras camisas del archivo.
    $page = $hombre();
    $option = collect($page['filters']['categories'])->firstWhere('value', (string) $camisas->getKey());

    expect($page['total'])->toBe(1)
        ->and($option['count'])->toBe(1);
});

test('un artículo publicado sin fotos se ve con su hueco de imagen en tienda, ficha y carrito', function () {
    $this->seed(RoleSeeder::class);
    e2eCatalog();

    $admin = adminForPanel();

    (new BulkProductImporter)->import(e2eCsvFile(e2eRows()), $admin);

    $camisa = Product::query()->where('name', 'Camisa E2E')->sole();

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('toggleActive', $camisa->getKey());

    // La tarjeta no trae foto (queda el degradado), pero sí el nombre.
    expect(ProductCards::make($camisa->fresh())['image'])->toBeNull();
    $this->get('/tienda')->assertOk()->assertSee($camisa->name);

    // La ficha se pinta completa y avisa que el color elegido no tiene fotos.
    $this->get(route('storefront.product', $camisa->slug))
        ->assertOk()
        ->assertSee($camisa->name)
        ->assertSee('Este color todavía no tiene fotos.');

    // Agregar al carrito con una variante con stock y verlo en la página del carrito.
    $variant = $camisa->variants()->where('stock', '>', 0)->firstOrFail();

    $this->postJson('/carrito/agregar', ['variant_id' => $variant->getKey(), 'quantity' => 1])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->get('/carrito')
        ->assertOk()
        ->assertSee($camisa->name);
});
