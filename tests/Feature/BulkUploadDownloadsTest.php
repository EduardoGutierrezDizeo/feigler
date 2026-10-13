<?php

use App\Actions\Products\CreateProduct;
use App\Enums\StoreSection;
use App\Exceptions\DuplicateImportedProductException;
use App\Livewire\Admin\BulkUpload;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use App\Services\ProductImport\BulkImportReport;
use App\Services\ProductImport\BulkImportTemplate;
use App\Services\ProductImport\BulkProductImporter;
use App\Services\ProductImport\BulkProductImportValidator;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Las descargas de la carga masiva: la plantilla que se sube y el reporte de errores
 * que se corrige. Lo que se prueba es la pantalla y no los dos servicios de escritura
 * — el reporte y la plantilla — así que las aserciones miran el archivo que sale por
 * `assertFileDownloaded`, sus bytes decodificados y el estado que queda en el módulo.
 *
 * Los helpers se llaman distinto que los de los otros tests del módulo a propósito:
 * Pest carga los archivos de test en un mismo proceso, y dos funciones con el mismo
 * nombre en dos archivos no pueden convivir.
 */

/**
 * El catálogo que la plantilla lee: una categoría con sus tallas, una categoría
 * apagada, un color y un material activos y un color y un material apagados.
 *
 * @return array{category: Category}
 */
function bulkDownloadCatalog(): array
{
    $category = numberedCategory('DB', 'Camisetas');

    Category::factory()->section(StoreSection::Hombre)->create([
        'name' => 'Camisetas ocultas',
        'slug' => 'camisetas-ocultas',
        'sku_prefix' => 'DBA',
        'is_active' => false,
    ]);

    Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ']);
    Color::factory()->create(['name' => 'Amarillo', 'code' => 'AMA', 'is_active' => false]);

    Material::factory()->create(['name' => 'Algodón']);
    Material::factory()->create(['name' => 'Lino', 'is_active' => false]);

    Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]);
    Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]);
    Size::factory()->for($category)->create(['name' => 'XL', 'order' => 3, 'is_active' => false]);

    return ['category' => $category];
}

/** @return array<string, string> */
function bulkDownloadLine(array $overrides = []): array
{
    return array_merge([
        'genero' => 'hombre',
        'categoria' => 'Camisetas',
        'nombre' => 'Prenda descargable',
        'precio' => '85000',
        'materiales' => 'Algodón 100%',
        'talla' => 'M',
        'color' => 'Rojo',
        'stock' => '5',
    ], $overrides);
}

/** @param  list<array<string, string>>  $rows */
function bulkDownloadFile(array $rows, string $name = 'importacion.csv'): UploadedFile
{
    $headers = ['genero', 'categoria', 'nombre', 'precio', 'materiales', 'talla', 'color', 'stock'];
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            fn (string $header): string => (string) ($row[$header] ?? ''),
            $headers,
        ));
    }

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
}

function bulkDownloadPanel(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(BulkUpload::class);
}

/**
 * The bytes the browser would have received, taken from the download the request left.
 */
function bulkDownloadEffectsContent(mixed $panel, string $filename): string
{
    $panel->assertFileDownloaded($filename);

    return (string) base64_decode((string) data_get($panel->effects, 'download.content'), true);
}

/**
 * The CSV report split into its cells, the byte order mark and the separator taken off.
 *
 * @return list<list<string>>
 */
function bulkDownloadCsvRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = array_values(array_filter(explode("\n", trim($csv)), fn (string $line): bool => $line !== ''));

    return array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), $lines);
}

/**
 * The sheets of a workbook, each one as the rows of cells it holds, keyed by sheet name.
 *
 * @return array<string, list<list<mixed>>>
 */
function bulkDownloadSheetRows(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'plantilla-descargada-');
    file_put_contents($path, $contents);

    $reader = new XlsxReader;
    $reader->open($path);

    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    @unlink($path);

    return $sheets;
}

test('only administrators can download the template and the report', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    Role::findOrCreate('cliente', 'web');
    $customer = User::factory()->create();
    $customer->assignRole('cliente');

    Livewire::actingAs($customer)->test(BulkUpload::class)->assertForbidden();

    $admin = adminForPanel();
    $template = bulkDownloadPanel($admin);
    $report = bulkDownloadPanel($admin);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $template->call('downloadTemplate')->assertForbidden();
    $report->call('downloadErrorReport')->assertForbidden();
});

test('the preview report carries every refusal, not only the slice on screen', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $rows = [];

    for ($i = 1; $i <= 130; $i++) {
        $rows[] = bulkDownloadLine(['nombre' => "Prenda {$i}", 'talla' => 'ZZZ']);
    }

    $panel = bulkDownloadPanel()
        ->set('file', bulkDownloadFile($rows))
        ->call('review')
        ->assertSet('preview.totals.rejected_articles', 130)
        ->assertSet('preview.totals.ready_articles', 0);

    expect($panel->get('preview.rejected'))->toHaveCount(100);

    $panel->call('downloadErrorReport');

    $csv = bulkDownloadEffectsContent($panel, BulkImportReport::FILENAME);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('fila;artículo;columna;motivo');

    $parsed = bulkDownloadCsvRows($csv);

    expect($parsed)->toHaveCount(131)
        ->and($parsed[0])->toBe(['fila', 'artículo', 'columna', 'motivo'])
        ->and($parsed[1][2])->toBe('talla');
});

test('the result report carries both the validation refusals and a creation refusal', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $this->app->bind(BulkProductImporter::class, fn ($app): BulkProductImporter => new BulkProductImporter(
        $app->make(BulkProductImportValidator::class),
        new class extends CreateProduct
        {
            public function __invoke(Category $category, array $data, array $composition = []): Product
            {
                throw DuplicateImportedProductException::forName($data['name'], $category);
            }
        },
    ));

    $panel = bulkDownloadPanel()
        ->set('file', bulkDownloadFile([
            bulkDownloadLine(['nombre' => 'Prenda sin talla', 'talla' => 'ZZZ']),
            bulkDownloadLine(['nombre' => 'Prenda que no se crea']),
        ]))
        ->call('review')
        ->assertSet('preview.totals.ready_articles', 1)
        ->assertSet('preview.totals.rejected_articles', 1)
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT)
        ->assertSet('result.totals.created_articles', 0)
        ->assertSet('result.totals.rejected_articles', 2);

    expect($panel->get('reportPath'))->not->toBeNull();

    $panel->call('downloadErrorReport');

    $parsed = bulkDownloadCsvRows(bulkDownloadEffectsContent($panel, BulkImportReport::FILENAME));
    $columns = array_map(fn (array $row): string => $row[2], array_slice($parsed, 1));

    expect($columns)->toContain('talla')
        ->and($columns)->toContain(BulkProductImporter::CREATION_COLUMN);
});

test('a value that looks like a formula is neutralized in the report', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $panel = bulkDownloadPanel()
        ->set('file', bulkDownloadFile([
            bulkDownloadLine(['nombre' => '=PELIGRO()', 'talla' => 'ZZZ']),
        ]))
        ->call('review');

    $panel->call('downloadErrorReport');

    $parsed = bulkDownloadCsvRows(bulkDownloadEffectsContent($panel, BulkImportReport::FILENAME));

    expect($parsed[1][1])->toBe("'=PELIGRO()");
});

test('the report link is not offered when nothing was refused', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    bulkDownloadPanel()
        ->set('file', bulkDownloadFile([bulkDownloadLine()]))
        ->call('review')
        ->assertSet('preview.totals.rejected_articles', 0)
        ->assertDontSee('Descargar reporte de errores')
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT)
        ->assertSet('reportPath', null)
        ->assertDontSee('Descargar reporte de errores');
});

test('a report download with a file that is gone is a notice and not a broken screen', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $panel = bulkDownloadPanel()
        ->set('file', bulkDownloadFile([bulkDownloadLine(['nombre' => 'Sin talla', 'talla' => 'ZZZ'])]))
        ->call('review')
        ->assertSet('preview.totals.rejected_articles', 1);

    Storage::disk('local')->delete($panel->get('storedPath'));

    $panel->call('downloadErrorReport')
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', fn (string $notice): bool => str_contains($notice, 'Vuelve a subirlo'));
});

test('the template is a two-sheet workbook built from the live catalog', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $panel = bulkDownloadPanel()->call('downloadTemplate');

    $sheets = bulkDownloadSheetRows(bulkDownloadEffectsContent($panel, BulkImportTemplate::FILENAME));

    expect(array_keys($sheets))->toBe(['Plantilla', 'Valores válidos']);

    $lines = $sheets['Plantilla'];

    expect($lines[0])->toBe(['genero', 'categoria', 'nombre', 'marca', 'precio', 'materiales', 'talla', 'color', 'stock'])
        ->and($lines)->toHaveCount(3)
        ->and($lines[1][5])->toBe('Algodón 100%');

    $values = implode("\n", array_map(
        fn (array $row): string => implode('|', $row),
        $sheets['Valores válidos'],
    ));

    expect($values)->toContain('Camisetas')
        ->and($values)->toContain('Camisetas ocultas')
        ->and($values)->toContain('M, L')
        ->and($values)->toContain('Rojo')
        ->and($values)->toContain('Algodón')
        ->and($values)->not->toContain('XL')
        ->and($values)->not->toContain('Amarillo')
        ->and($values)->not->toContain('Lino');
});

test('the template falls back to placeholders when the catalog is empty', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    $panel = bulkDownloadPanel()->call('downloadTemplate');

    $sheets = bulkDownloadSheetRows(bulkDownloadEffectsContent($panel, BulkImportTemplate::FILENAME));

    expect(array_keys($sheets))->toBe(['Plantilla', 'Valores válidos']);

    $lines = implode("\n", array_map(
        fn (array $row): string => implode('|', $row),
        $sheets['Plantilla'],
    ));

    expect($lines)->toContain('EJEMPLO');
});

test('the downloaded template loads without a single refusal', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $panel = bulkDownloadPanel()->call('downloadTemplate');

    $contents = bulkDownloadEffectsContent($panel, BulkImportTemplate::FILENAME);

    bulkDownloadPanel()
        ->set('file', UploadedFile::fake()->createWithContent('plantilla-carga-masiva.xlsx', $contents))
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->assertSet('preview.totals.rejected_articles', 0)
        ->assertSet('preview.totals.ready_articles', 1);
});

test('the two example rows of the template teach the composition order the parser reads', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    $category = numberedCategory('EX', 'Camisetas de ejemplo');

    Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ']);
    Material::factory()->create(['name' => 'Algodón']);
    Material::factory()->create(['name' => 'Poliéster']);

    Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]);
    Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]);

    $panel = bulkDownloadPanel()->call('downloadTemplate');

    $contents = bulkDownloadEffectsContent($panel, BulkImportTemplate::FILENAME);
    $lines = bulkDownloadSheetRows($contents)['Plantilla'];

    // Con dos materiales activos la plantilla ofrece la composición repartida, y la
    // escribe con el nombre primero y el porcentaje al final: el orden que
    // `ProductDetailsCatalog` lee. Al revés se leería un material llamado «80».
    expect($lines[1][5])->toBe('Algodón 80% | Poliéster 20%')
        ->and($lines[2][5])->toBe('Algodón 80% | Poliéster 20%');

    // Y el archivo tal como sale es una carga que entra entera: dos variantes de un
    // mismo artículo, sin un solo rechazo.
    bulkDownloadPanel()
        ->set('file', UploadedFile::fake()->createWithContent('plantilla-ejemplos.xlsx', $contents))
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('preview.totals.rejected_articles', 0)
        ->assertSet('preview.totals.ready_articles', 1)
        ->assertSet('preview.totals.ready_variants', 2);
});

test('starting another review drops a report that would otherwise be left behind', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    bulkDownloadCatalog();

    $panel = bulkDownloadPanel()
        ->set('file', bulkDownloadFile([
            bulkDownloadLine(['nombre' => 'Se crea']),
            bulkDownloadLine(['nombre' => 'No se crea', 'talla' => 'ZZZ']),
        ]))
        ->call('review')
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT);

    $reportPath = $panel->get('reportPath');

    expect($reportPath)->not->toBeNull()
        ->and(Storage::disk('local')->exists($reportPath))->toBeTrue();

    $panel->call('cancel')
        ->assertSet('reportPath', null)
        ->assertSet('step', BulkUpload::STEP_UPLOAD);

    expect(Storage::disk('local')->exists($reportPath))->toBeFalse();
});
