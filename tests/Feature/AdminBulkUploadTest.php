<?php

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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * El módulo de la carga masiva en el panel: elegir el archivo, leer la vista previa
 * y confirmar. Lo que se prueba aquí es la pantalla y no el importador — esa mitad ya
 * tiene sus tests — así que las aserciones miran el paso que se muestra, lo que queda
 * en el estado, el archivo temporal y las filas que acaban en la base.
 *
 * Los helpers se llaman distinto que los del test del importador a propósito: Pest
 * carga los archivos de test en un mismo proceso, y dos funciones con el mismo nombre
 * en dos archivos no pueden convivir.
 */

/**
 * El catálogo mínimo que resuelven las filas del archivo: una categoría con sus
 * tallas, un color y un material.
 *
 * @return array{category: Category, color: Color, material: Material}
 */
function bulkUploadCatalog(): array
{
    $category = numberedCategory('IM', 'Importados');
    $color = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]);
    Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]);

    return ['category' => $category, 'color' => $color, 'material' => $material];
}

/** @return array<string, string> */
function bulkUploadLine(array $overrides = []): array
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
function bulkUploadContent(array $rows, ?array $headers = null): string
{
    $headers ??= ['genero', 'categoria', 'nombre', 'precio', 'materiales', 'talla', 'color', 'stock'];
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            fn (string $header): string => (string) ($row[$header] ?? ''),
            $headers,
        ));
    }

    return implode("\n", $lines)."\n";
}

/** @param  list<array<string, string>>  $rows */
function bulkUploadFile(array $rows, string $name = 'importacion.csv', ?array $headers = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, bulkUploadContent($rows, $headers));
}

function bulkUploadPanel(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(BulkUpload::class);
}

test('only administrators can open the bulk load', function (?string $role) {
    $this->seed(RoleSeeder::class);
    Role::findOrCreate('cliente', 'web');

    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    $this->actingAs($user)->get(route('admin.bulk-upload'))->assertForbidden();
})->with([
    'vendedor' => ['vendedor'],
    'cliente' => ['cliente'],
    'sin rol' => [null],
]);

test('the bulk load re-checks the admin role on every request', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $panel = Livewire::actingAs($admin)->test(BulkUpload::class);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->refresh()->assertForbidden();
});

test('the panel links to the bulk load and the module names itself', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())->get(route('admin.bulk-upload'))
        ->assertOk()
        ->assertSee('Carga masiva')
        ->assertSee(route('admin.bulk-upload'), false);
});

test('the upload step teaches the composition format the parser accepts', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())->get(route('admin.bulk-upload'))
        ->assertOk()
        ->assertSee('Algodón 80% | Poliéster 20%');
});

test('a file that is neither csv nor xlsx is refused before anything is stored', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadPanel()
        ->set('file', UploadedFile::fake()->create('documento.pdf', 10))
        ->call('review')
        ->assertHasErrors(['file' => 'extensions'])
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('storedPath', null);

    expect(Storage::disk('local')->files(BulkImportFileStore::DIRECTORY))->toBe([]);
});

test('a file goes from the upload to the preview and to the created, inactive articles', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadCatalog();

    $panel = bulkUploadPanel()
        ->set('file', bulkUploadFile([
            bulkUploadLine(['talla' => 'M', 'stock' => '5']),
            bulkUploadLine(['talla' => 'L', 'stock' => '2']),
        ]))
        ->call('review')
        ->assertHasNoErrors()
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->assertSet('originalName', 'importacion.csv')
        ->assertSet('preview.totals.ready_articles', 1)
        ->assertSet('preview.totals.ready_variants', 2)
        ->assertSee('Prenda importada');

    $stored = $panel->get('storedPath');

    expect($stored)->not->toBeNull()
        ->and(Storage::disk('local')->exists($stored))->toBeTrue();

    $panel->call('confirm')
        ->assertSet('step', BulkUpload::STEP_RESULT)
        ->assertSet('result.totals.created_articles', 1)
        ->assertSet('result.totals.created_variants', 2)
        ->assertSee('IM-001');

    $product = Product::query()->sole();

    expect($product->status)->toBe('inactive')
        ->and($product->variants()->count())->toBe(2)
        ->and(Storage::disk('local')->exists($stored))->toBeFalse();
});

test('the confirmation does nothing when the file has no ready article', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadCatalog();

    bulkUploadPanel()
        ->set('file', bulkUploadFile([bulkUploadLine(['talla' => 'XXL'])]))
        ->call('review')
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->assertSet('preview.totals.ready_articles', 0)
        ->assertSee('Ningún artículo del archivo se puede crear')
        ->call('confirm')
        ->assertSet('step', BulkUpload::STEP_PREVIEW)
        ->assertSet('result', []);

    expect(Product::query()->count())->toBe(0);
});

test('confirming twice does not create the same articles again', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadCatalog();

    $panel = bulkUploadPanel()
        ->set('file', bulkUploadFile([bulkUploadLine()]))
        ->call('review');

    $panel->call('confirm')->assertSet('result.totals.created_articles', 1);
    $panel->call('confirm')->assertSet('result.totals.created_articles', 1);

    expect(Product::query()->count())->toBe(1);
});

test('a file missing a required column is refused with a notice and leaves nothing behind', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadPanel()
        ->set('file', bulkUploadFile(
            [['nombre' => 'Prenda', 'precio' => '1000']],
            'incompleto.csv',
            ['nombre', 'precio'],
        ))
        ->call('review')
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('storedPath', null)
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', fn (string $notice): bool => str_contains($notice, 'columnas obligatorias'));

    expect(Storage::disk('local')->files(BulkImportFileStore::DIRECTORY))->toBe([]);
});

test('a file that is not a readable workbook is told as unreadable and does not break the screen', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadPanel()
        ->set('file', UploadedFile::fake()->createWithContent('roto.xlsx', 'esto no es un libro de Excel'))
        ->call('review')
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('storedPath', null)
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', 'No se pudo leer el archivo. Comprueba que sea un CSV o un Excel válido.');

    expect(Storage::disk('local')->files(BulkImportFileStore::DIRECTORY))->toBe([]);
});

test('cancelling drops the reviewed file and returns to the first step', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');

    bulkUploadCatalog();

    $panel = bulkUploadPanel()
        ->set('file', bulkUploadFile([bulkUploadLine()]))
        ->call('review')
        ->assertSet('step', BulkUpload::STEP_PREVIEW);

    $stored = $panel->get('storedPath');

    $panel->call('cancel')
        ->assertSet('step', BulkUpload::STEP_UPLOAD)
        ->assertSet('storedPath', null)
        ->assertSet('originalName', '')
        ->assertSet('preview', []);

    expect(Storage::disk('local')->exists($stored))->toBeFalse();
});

test('the cleanup command deletes only the files that nobody confirmed a day ago', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $disk->put(BulkImportFileStore::DIRECTORY.'/viejo.csv', 'x');
    $disk->put(BulkImportFileStore::DIRECTORY.'/nuevo.csv', 'x');

    touch($disk->path(BulkImportFileStore::DIRECTORY.'/viejo.csv'), now()->subHours(25)->getTimestamp());
    touch($disk->path(BulkImportFileStore::DIRECTORY.'/nuevo.csv'), now()->getTimestamp());

    $this->artisan('bulk-imports:prune')->assertSuccessful();

    expect($disk->exists(BulkImportFileStore::DIRECTORY.'/viejo.csv'))->toBeFalse()
        ->and($disk->exists(BulkImportFileStore::DIRECTORY.'/nuevo.csv'))->toBeTrue();
});
