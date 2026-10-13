<?php

use App\Exceptions\InvalidStoredImportPathException;
use App\Livewire\Admin\BulkUpload;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use App\Services\ProductImport\BulkImportFileStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * El endurecimiento del módulo de la carga masiva: que el estado no se pueda tocar
 * desde el navegador, que la carpeta privada no deje salir una ruta manipulada, que el
 * resumen no crezca con el número de artículos, que el informe completo de rechazados
 * quede en un archivo, y que una carga no se pueda disparar dos veces a la vez.
 *
 * El archivo se llama a sí mismo con helpers propios en vez de reutilizar los de
 * `AdminBulkUploadTest` a propósito: Pest solo carga el archivo que se le pide, así que
 * un helper definido allí no existiría al correr este archivo solo, y un nombre
 * idéntico chocaría al correr los dos juntos.
 */

/**
 * El catálogo mínimo que resuelven las filas del archivo: una categoría con sus tallas,
 * un color y un material.
 *
 * @return array{category: Category, color: Color, material: Material}
 */
function hardeningCatalog(): array
{
    $category = numberedCategory('IM', 'Importados');
    $color = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]);
    Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]);

    return ['category' => $category, 'color' => $color, 'material' => $material];
}

/** @return array<string, string> */
function hardeningLine(array $overrides = []): array
{
    return array_merge([
        'genero' => 'hombre',
        'categoria' => 'Importados',
        'nombre' => 'Prenda importada',
        'precio' => '85000',
        'materiales' => 'Algodón 100%',
        'talla' => 'M',
        'color' => 'Rojo',
        'stock' => '5',
    ], $overrides);
}

/** @param  list<array<string, string>>  $rows */
function hardeningFile(array $rows, string $name = 'importacion.csv'): UploadedFile
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

function hardeningPanel(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(BulkUpload::class);
}

/**
 * Confirma una carga de `$articles` artículos distintos y devuelve el tamaño real del
 * snapshot serializado que Livewire enviaría en la siguiente petición.
 *
 * Los nombres empiezan en `$offset` para que dos medidas en el mismo test no choquen
 * contra los artículos que la primera ya creó.
 */
function hardeningConfirmedSnapshotBytes(int $articles, int $offset = 0, string $size = 'M', string $stock = '0'): int
{
    $rows = [];

    for ($i = 1; $i <= $articles; $i++) {
        $rows[] = hardeningLine([
            'nombre' => 'Prenda '.($offset + $i),
            'talla' => $size,
            'stock' => $stock,
        ]);
    }

    $panel = hardeningPanel()
        ->set('file', hardeningFile($rows))
        ->call('review')
        ->call('confirm');

    return strlen(json_encode($panel->snapshot, JSON_UNESCAPED_UNICODE));
}

test('the state cannot be set from the client', function (string $property, mixed $value) {
    $this->seed(RoleSeeder::class);

    $panel = hardeningPanel();

    expect(fn () => $panel->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'step' => ['step', BulkUpload::STEP_RESULT],
    'storedPath' => ['storedPath', 'bulk-imports/'.str_repeat('a', 32).'.csv'],
    'originalName' => ['originalName', 'otro.csv'],
    'preview' => ['preview', ['totals' => ['ready_articles' => 99]]],
    'result' => ['result', ['totals' => ['created_articles' => 99]]],
    'reportPath' => ['reportPath', 'bulk-imports/'.str_repeat('b', 32).'.json'],
]);

test('the store refuses every path that is not a name of its own folder', function (string $storedPath) {
    Storage::fake('local');

    $store = new BulkImportFileStore;

    expect(fn () => $store->absolutePath($storedPath))
        ->toThrow(InvalidStoredImportPathException::class);

    $store->delete($storedPath);
})->with([
    'relative hop' => ['../x.csv'],
    'windows hop' => ['..\\x.csv'],
    'hop inside the folder' => ['bulk-imports/../x.csv'],
    'absolute path' => ['/etc/passwd'],
    'windows drive' => ['C:\\bulk-imports\\'.str_repeat('a', 32).'.csv'],
    'null byte' => ['bulk-imports/'.str_repeat('a', 32).".csv\0"],
    'sibling folder' => ['bulk-imports-otro/'.str_repeat('a', 32).'.csv'],
    'another folder' => ['otra/'.str_repeat('a', 32).'.csv'],
    'unknown extension' => ['bulk-imports/'.str_repeat('a', 32).'.txt'],
    'no stem' => ['bulk-imports/x.csv'],
]);

test('a delete attempt never reaches a file outside the folder', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $sibling = 'bulk-imports-otro/'.str_repeat('a', 32).'.csv';
    $traversal = '../afuera.csv';

    $disk->put($sibling, 'señuelo');
    $disk->put('afuera.csv', 'señuelo');

    $store = new BulkImportFileStore;
    $store->delete($sibling);
    $store->delete($traversal);

    expect($disk->exists($sibling))->toBeTrue()
        ->and($disk->exists('afuera.csv'))->toBeTrue();
});

test('a real stored file still resolves and deletes', function () {
    Storage::fake('local');

    $store = new BulkImportFileStore;
    $stored = $store->store(hardeningFile([hardeningLine()]));

    expect($stored)->toMatch('#^bulk-imports/[0-9a-f]{32}\.csv$#')
        ->and(is_file($store->absolutePath($stored)))->toBeTrue();

    $store->delete($stored);

    expect(Storage::disk('local')->exists($stored))->toBeFalse();
});

test('the confirmed state does not grow with the number of articles', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $few = hardeningConfirmedSnapshotBytes(60);
    $many = hardeningConfirmedSnapshotBytes(1000, offset: 1000);

    expect($many)->toBeLessThan(40_000)
        ->and(abs($many - $few))->toBeLessThan(1_000);
});

test('the report keeps every refused article, not only the ones the screen shows', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $rows = [hardeningLine(['nombre' => 'Prenda válida'])];

    for ($i = 1; $i <= 300; $i++) {
        $rows[] = hardeningLine(['nombre' => 'Prenda rechazada '.$i, 'talla' => 'XXL']);
    }

    $panel = hardeningPanel()
        ->set('file', hardeningFile($rows))
        ->call('review')
        ->call('confirm')
        ->assertSet('result.totals.created_articles', 1)
        ->assertSet('result.totals.rejected_articles', 300);

    $reportPath = $panel->get('reportPath');

    expect($reportPath)->toMatch('#^bulk-imports/[0-9a-f]{32}\.json$#');

    $report = (new BulkImportFileStore)->readReport($reportPath);

    expect($report)->toHaveCount(300)
        ->and($report[0])->toHaveKeys(['name', 'rows', 'errors'])
        ->and($panel->get('result')['rejected'])->toHaveCount(BulkUpload::REJECTED_PREVIEW_LIMIT);
});

test('the report also carries a refusal that came from the creation', function () {
    Storage::fake('local');

    $store = new BulkImportFileStore;

    $rejected = [
        [
            'name' => 'Prenda',
            'rows' => [3],
            'errors' => [['row' => 3, 'column' => 'creacion', 'message' => 'No se pudo crear el artículo.']],
        ],
    ];

    $path = $store->storeReport($rejected);

    expect($path)->toMatch('#^bulk-imports/[0-9a-f]{32}\.json$#')
        ->and($store->readReport($path))->toBe($rejected);
});

test('no report is written when the load refuses nothing', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $panel = hardeningPanel()
        ->set('file', hardeningFile([hardeningLine()]))
        ->call('review')
        ->call('confirm');

    expect($panel->get('reportPath'))->toBeNull()
        ->and(Storage::disk('local')->files(BulkImportFileStore::DIRECTORY))->toBe([]);
});

test('the cleanup command also sweeps the report files', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $store = new BulkImportFileStore;
    $old = $store->storeReport([['name' => 'A', 'rows' => [1], 'errors' => []]]);
    $recent = $store->storeReport([['name' => 'B', 'rows' => [2], 'errors' => []]]);

    touch($disk->path($old), now()->subHours(25)->getTimestamp());
    touch($disk->path($recent), now()->getTimestamp());

    $this->artisan('bulk-imports:prune')->assertSuccessful();

    expect($disk->exists($old))->toBeFalse()
        ->and($disk->exists($recent))->toBeTrue();
});

test('a load that is already running writes nothing and says so', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $admin = adminForPanel();
    $panel = hardeningPanel($admin)
        ->set('file', hardeningFile([hardeningLine()]))
        ->call('review');

    $held = Cache::lock('bulk-import:'.$admin->getKey(), 60);
    expect($held->get())->toBeTrue();

    try {
        $panel->call('confirm')
            ->assertSet('noticeType', 'error')
            ->assertSet('step', BulkUpload::STEP_PREVIEW)
            ->assertSet('result', []);
    } finally {
        $held->release();
    }

    expect(Product::query()->count())->toBe(0);
});

test('a load that finished releases its lock', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $admin = adminForPanel();

    hardeningPanel($admin)
        ->set('file', hardeningFile([hardeningLine()]))
        ->call('review')
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT);

    $free = Cache::lock('bulk-import:'.$admin->getKey(), 1);

    expect($free->get())->toBeTrue();

    $free->release();
});

test('a load that fails releases its lock', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $admin = adminForPanel();

    $panel = hardeningPanel($admin)
        ->set('file', hardeningFile([hardeningLine()]))
        ->call('review');

    // El archivo revisado desaparece de la carpeta: confirmar falla al resolverlo y
    // la pantalla vuelve al primer paso, pero el bloqueo no puede quedarse tomado.
    Storage::disk('local')->delete($panel->get('storedPath'));

    $panel->call('confirm')
        ->assertSet('noticeType', 'error')
        ->assertSet('step', BulkUpload::STEP_UPLOAD);

    $free = Cache::lock('bulk-import:'.$admin->getKey(), 1);

    expect($free->get())->toBeTrue();

    $free->release();
});

test('the whole flow still runs end to end', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    hardeningCatalog();

    $panel = hardeningPanel()
        ->set('file', hardeningFile([
            hardeningLine(['nombre' => 'Lista', 'talla' => 'M']),
            hardeningLine(['nombre' => 'Rechazada', 'talla' => 'XXL']),
        ]))
        ->call('review')
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT)
        ->assertSet('result.totals.created_articles', 1)
        ->assertSet('result.totals.rejected_articles', 1);

    expect(Product::query()->count())->toBe(1)
        ->and($panel->get('reportPath'))->not->toBeNull();
});
