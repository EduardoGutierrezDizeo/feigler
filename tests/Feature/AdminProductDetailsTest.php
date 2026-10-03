<?php

use App\Enums\StoreSection;
use App\Livewire\Admin\ProductDetails\Colors;
use App\Livewire\Admin\ProductDetails\Index;
use App\Livewire\Admin\ProductDetails\Materials;
use App\Livewire\Admin\ProductDetails\Sizes;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * The four catalogs a product is built out of, each one its own Livewire component
 * behind a page that owns the tab being read.
 *
 * The tabs are mounted one at a time and the children are independent panels, so most
 * of what is checked here is wiring: which child is on screen, what the URL carries,
 * and that a call cannot leave the catalog it was made from. The rules of the catalogs
 * themselves are the actions' business and are covered by `SizeActionsTest`,
 * `ColorActionsTest` and `MaterialActionsTest`; what a refusal looks like once it has
 * travelled through a component is checked here.
 */
function panelDeDetalles(mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Index::class);
}

function panelDeTallas(mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Sizes::class);
}

function panelDeColores(mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Colors::class);
}

function panelDeMateriales(mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Materials::class);
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * A size of a category, written where the test needs it: a size only means something
 * inside a category, and `sizeOfProduct()` needs a product to find the category of.
 */
function sizeOfProductInto(Category $categoria, string $name): Size
{
    return Size::query()->firstOrCreate([
        'category_id' => $categoria->getKey(),
        'name' => $name,
    ]);
}

/*
|--------------------------------------------------------------------------
| La dirección anterior
|--------------------------------------------------------------------------
*/

test('the old categories address lands on the tab that replaced it', function () {
    $response = $this->actingAs(adminForPanel())->get('/admin/categories');

    $response->assertRedirect('/admin/product-details?tab=categorias');
});

test('the old address is a door and not a page, so nothing links to it', function () {
    // Con nombre de ruta, `route('admin.categories.index')` volvería a compilar y el
    // panel acabaría enlazando una dirección que solo redirige.
    expect(Route::has('admin.categories.index'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Las pestañas
|--------------------------------------------------------------------------
*/

test('only the tab being read is mounted', function (string $tab, string $montada, string $ausente) {
    Livewire::withQueryParams(['tab' => $tab])
        ->actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSet('tab', $tab)
        ->assertSeeHtml('wire:key="details-panel-'.$montada.'"')
        ->assertDontSeeHtml('wire:key="details-panel-'.$ausente.'"');
})->with([
    'categorias' => ['categorias', 'categorias', 'tallas'],
    'tallas' => ['tallas', 'tallas', 'colores'],
    'colores' => ['colores', 'colores', 'materiales'],
    'materiales' => ['materiales', 'materiales', 'categorias'],
]);

test('the four catalogs are offered as tabs, and the one being read is the selected one', function () {
    panelDeDetalles()
        ->assertSeeHtml('wire:click="setTab(\'categorias\')"')
        ->assertSeeHtml('wire:click="setTab(\'tallas\')"')
        ->assertSeeHtml('wire:click="setTab(\'colores\')"')
        ->assertSeeHtml('wire:click="setTab(\'materiales\')"')
        ->assertSeeHtml('id="details-tab-categorias"')
        ->assertSeeHtml('aria-selected="true"', false);

    panelDeDetalles()
        ->call('setTab', 'materiales')
        ->assertSet('tab', 'materiales')
        ->assertSeeHtml('aria-controls="details-panel-materiales"');
});

test('the tab travels in the url, so the page can be shared and reloaded', function () {
    // El efecto `url` se declara al montar: de ahí saca el JavaScript qué propiedad
    // va en la barra de direcciones y con qué nombre se escribe ahí.
    $panel = panelDeDetalles();

    expect($panel->effects['url']['tab']['as'])->toBe('tab');

    $panel->call('setTab', 'colores')->assertSet('tab', 'colores');

    // Y una dirección con esa pestaña monta esa pestaña, no la de por defecto.
    Livewire::withQueryParams(['tab' => 'colores'])
        ->actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('wire:key="details-panel-colores"');
});

test('an address with a tab that does not exist falls back to categorias', function (string $tab) {
    Livewire::withQueryParams(['tab' => $tab])
        ->actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSet('tab', 'categorias')
        ->assertSeeHtml('wire:key="details-panel-categorias"');
})->with(['inventado', 'TALLAS', '', 'categorias ']);

/*
|--------------------------------------------------------------------------
| Tallas
|--------------------------------------------------------------------------
*/

test('the sizes tab reads one category, the one the url names', function () {
    $primera = numberedCategory('PL', 'Polos');
    $segunda = numberedCategory('PA', 'Pantalones');

    $sizeDePolos = sizeOfProductInto($primera, 'M');
    $sizeDePantalanes = sizeOfProductInto($segunda, 'M');

    // La categoría también va en la barra de direcciones: una lista de tallas es una
    // lista de la que merece la pena guardar el enlace.
    expect(panelDeTallas()->effects['url']['categoryId']['as'])->toBe('cat');

    Livewire::withQueryParams(['cat' => $segunda->getKey()])
        ->actingAs(adminForPanel())
        ->test(Sizes::class)
        ->assertSet('categoryId', $segunda->getKey())
        ->assertSeeHtml('wire:key="size-'.$sizeDePantalanes->getKey().'"');

    // Y al revés: la `M` de los pantalones no es la `M` de los polos. Cada categoría
    // trae la suya, y el id es lo único que las distingue en la URL.
    Livewire::withQueryParams(['cat' => $primera->getKey()])
        ->actingAs(adminForPanel())
        ->test(Sizes::class)
        ->assertSet('categoryId', $primera->getKey())
        ->assertSeeHtml('wire:key="size-'.$sizeDePolos->getKey().'"')
        ->assertDontSeeHtml('wire:key="size-'.$sizeDePantalanes->getKey().'"');
});

test('the sizes tab falls back to the first category when the url names none, or one that does not exist', function (?int $cat) {
    $primera = numberedCategory('PL', 'Polos');
    numberedCategory('PA', 'Pantalones');

    $parametros = $cat === null ? [] : ['cat' => $cat];

    Livewire::withQueryParams($parametros)
        ->actingAs(adminForPanel())
        ->test(Sizes::class)
        ->assertSet('categoryId', $primera->getKey());
})->with([
    'sin parametro' => [null],
    'categoria que no existe' => [9999],
]);

test('the category selector groups the categories by the section the panel lists them in', function () {
    numberedCategory('PL', 'Polos');
    numberedCategory('VE', 'Vestidos', StoreSection::Mujer);

    panelDeTallas()
        ->assertSeeHtml('<optgroup label="Hombre">')
        ->assertSeeHtml('<optgroup label="Mujer">');
});

test('a size of another category cannot be edited, deleted or moved from this tab', function (string $metodo) {
    $esta = numberedCategory('PL', 'Polos');
    $otra = numberedCategory('PA', 'Pantalones');

    $size = Size::query()->create([
        'category_id' => $otra->getKey(),
        'name' => 'M',
        'order' => 0,
        'is_active' => true,
    ]);

    panelDeTallas()
        ->call($metodo, $size->getKey())
        ->assertNotFound();

    expect($size->fresh()->name)->toBe('M')->and($size->fresh()->is_active)->toBeTrue();
})->with(['edit', 'delete', 'toggleActive', 'moveUp', 'moveDown']);

test('creates a size in the selected category and announces it', function () {
    $categoria = numberedCategory('PL', 'Polos');

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('create')
        ->set('name', 'XXL')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast', message: 'Talla creada correctamente.', tone: 'success');

    expect($categoria->sizes()->where('name', 'XXL')->exists())->toBeTrue();
});

test('the name of a size is required and is held to twenty characters', function () {
    $categoria = numberedCategory('PL', 'Polos');

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('create')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('create')
        ->set('name', str_repeat('X', 21))
        ->call('save')
        ->assertHasErrors(['name' => 'max']);

    expect($categoria->sizes()->count())->toBe(0);
});

test('the same name can be used in another category', function () {
    $primera = numberedCategory('PL', 'Polos');
    $segunda = numberedCategory('PA', 'Pantalones');

    panelDeTallas()->set('categoryId', $primera->getKey())->call('create')->set('name', 'M')->call('save');

    panelDeTallas()
        ->set('categoryId', $segunda->getKey())
        ->call('create')
        ->set('name', 'M')
        ->call('save')
        ->assertHasNoErrors();

    expect($primera->sizes()->count())->toBe(1)->and($segunda->sizes()->count())->toBe(1);
});

test('a size name that is part of a sku is refused as it arrived', function () {
    $categoria = numberedCategory('PL', 'Polos');
    $size = sizeOfProductInto($categoria, 'M');

    $producto = Product::factory()->for($categoria)->create();
    ProductVariant::factory()->for($producto)->create(['size_id' => $size->getKey()]);

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('edit', $size->getKey())
        ->set('name', 'L')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', tone: 'error');

    expect($size->fresh()->name)->toBe('M');
});

test('a size nothing is sold in can be deleted, and one in use says how many variants are in the way', function () {
    $categoria = numberedCategory('PL', 'Polos');
    $libre = Size::query()->create(['category_id' => $categoria->getKey(), 'name' => 'XXL', 'order' => 1, 'is_active' => true]);
    $usada = sizeOfProductInto($categoria, 'M');

    $producto = Product::factory()->for($categoria)->create();
    ProductVariant::factory()->for($producto)->create(['size_id' => $usada->getKey()]);

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('delete', $libre->getKey())
        ->assertDispatched('toast', message: 'Talla «XXL» eliminada correctamente.', tone: 'success');

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->call('delete', $usada->getKey())
        ->assertDispatched('toast', tone: 'error');

    expect(Size::query()->whereKey($libre->getKey())->exists())->toBeFalse()
        ->and($usada->fresh())->not->toBeNull();
});

test('a size can be turned off and back on without losing it', function () {
    $categoria = numberedCategory('PL', 'Polos');
    $size = sizeOfProductInto($categoria, 'M');

    panelDeTallas()->set('categoryId', $categoria->getKey())->call('toggleActive', $size->getKey());
    expect($size->fresh()->is_active)->toBeFalse();

    panelDeTallas()->set('categoryId', $categoria->getKey())->call('toggleActive', $size->getKey());
    expect($size->fresh()->is_active)->toBeTrue();
});

test('filters the sizes by name and says when there is nothing left', function () {
    $categoria = numberedCategory('PL', 'Polos');
    sizeOfProductInto($categoria, 'S');
    sizeOfProductInto($categoria, 'M');

    // `title` y no el nombre a secas: una `M` suelta también sale en «Modal» y en
    // cualquier otra palabra del panel.
    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->set('search', 's')
        ->assertSeeHtml('title="S"')
        ->assertDontSeeHtml('title="M"');

    panelDeTallas()
        ->set('categoryId', $categoria->getKey())
        ->set('search', 'xl')
        ->assertDontSeeHtml('wire:key="size-')
        ->assertSee('No se encontraron tallas que coincidan con «xl».', false);
});

test('switching the category discards the search and the form opened for the previous one', function () {
    $primera = numberedCategory('PL', 'Polos');
    $segunda = numberedCategory('PA', 'Pantalones');

    panelDeTallas()
        ->set('categoryId', $primera->getKey())
        ->set('search', 'M')
        ->call('create')
        ->set('name', 'M')
        ->set('categoryId', $segunda->getKey())
        ->assertSet('search', '')
        ->assertSet('showForm', false)
        ->assertSet('name', '')
        ->assertSet('editingId', null);
});

test('the sizes list costs the same with three sizes as with fifteen', function () {
    $admin = adminForPanel();

    $queriesFor = function (int $tallas) use ($admin): int {
        $categoria = numberedCategory('Q'.$tallas, 'Prenda '.$tallas);

        // Los nombres se escriben y no se dejan al azar: el factory los sortea de siete
        // valores únicos por proceso, y dieciocho filas agotan el juego entero.
        foreach (range(1, $tallas) as $numero) {
            sizeOfProductInto($categoria, 'T'.$numero);
        }

        // El guard de rol lee los permisos una sola vez y los cachea el registrador de
        // Spatie: se calienta antes de contar para que la medición sea sobre las tallas
        // y no sobre ese cacheo.
        $admin->hasRole('admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)->test(Sizes::class);

        $consultas = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    };

    $conTres = $queriesFor(3);
    $conQuince = $queriesFor(15);

    // Con el contador de variantes ya en la misma consulta, leer más filas no
    // cuesta más consultas: si la vista volviera a preguntar fila por fila, los dos
    // números se separarían.
    expect($conQuince)->toBe($conTres);
});

/*
|--------------------------------------------------------------------------
| Colores
|--------------------------------------------------------------------------
*/

test('the hexadecimal is written by the Alpine component and not by wire:model', function () {
    $color = Color::factory()->create(['name' => 'Azul', 'hex' => '#1A2B3C']);

    panelDeColores()
        ->call('edit', $color->getKey())
        ->assertSeeHtml("x-data=\"colorHexInput(\$wire, 'hex', '#1A2B3C')\"")
        // `wire:model` y `x-model` en el mismo input son dos dueños peleándose por
        // el valor del DOM: el texto se pierde a mitad de escribir.
        ->assertDontSeeHtml('wire:model="hex"');
});

test('creates a color and announces it', function () {
    panelDeColores()
        ->call('create')
        ->set('name', 'Verde')
        ->set('hex', '#00FF00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast', message: 'Color creado correctamente.', tone: 'success');

    expect(Color::query()->where('name', 'Verde')->sole()->hex)->toBe('#00FF00');
});

test('a hexadecimal has to be a hash and six digits', function (string $hex) {
    panelDeColores()
        ->call('create')
        ->set('name', 'Prueba')
        ->set('hex', $hex)
        ->call('save')
        ->assertHasErrors(['hex' => 'regex']);
})->with([
    'sin hash' => ['1A2B3C'],
    'tres digitos' => ['#ABC'],
    'letras fuera del hexadecimal' => ['#1A2B3Z'],
]);

test('an empty hexadecimal is asked for instead of refused', function () {
    panelDeColores()
        ->call('create')
        ->set('name', 'Prueba')
        ->set('hex', '')
        ->call('save')
        ->assertHasErrors(['hex' => 'required']);
});

test('the code of a color with variants is shown as read-only and is refused on save', function () {
    $color = Color::factory()->create(['name' => 'Rojo', 'code' => 'RJ']);
    $producto = Product::factory()->create();
    ProductVariant::factory()->for($producto)->create(['color_id' => $color->getKey()]);

    panelDeColores()
        ->call('edit', $color->getKey())
        ->assertSet('codeIsLocked', true)
        ->assertSee('RJ')
        // El código se muestra, no se escribe: ofrecer un campo que el catálogo va a
        // rechazar es peor que decir por qué no se puede cambiar.
        ->assertDontSeeHtml('wire:model="code"');

    panelDeColores()
        ->call('edit', $color->getKey())
        ->set('code', 'RR')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', tone: 'error');

    expect($color->fresh()->code)->toBe('RJ');
});

test('a color nothing is sold in keeps its code editable', function () {
    $color = Color::factory()->create(['name' => 'Rojo', 'code' => 'RJ']);

    panelDeColores()
        ->call('edit', $color->getKey())
        ->assertSet('codeIsLocked', false)
        ->assertSeeHtml('wire:model="code"')
        ->set('code', 'RR')
        ->call('save')
        ->assertHasNoErrors();

    // El código del catálogo siempre mide tres: `RR` se completa con `X`.
    expect($color->fresh()->code)->toBe('RRX');
});

test('a code is held to three characters', function () {
    panelDeColores()
        ->call('create')
        ->set('name', 'Rojo')
        ->set('hex', '#FF0000')
        ->set('code', 'ROJO')
        ->call('save')
        ->assertHasErrors(['code' => 'max']);
});

test('the same name is refused even when it only differs in accents and case', function (string $nombre) {
    Color::factory()->create(['name' => 'Ámbar']);

    panelDeColores()
        ->call('create')
        ->set('name', $nombre)
        ->set('hex', '#FFBF00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', tone: 'error');

    expect(Color::query()->count())->toBe(1);
})->with(['ambar', 'ÁMBAR']);

test('a color nothing points at can be deleted, and one in use says what is in the way', function () {
    $libre = Color::factory()->create(['name' => 'Libre', 'code' => 'LB']);
    $usado = Color::factory()->create(['name' => 'Usado', 'code' => 'US']);

    $producto = Product::factory()->create();
    ProductImage::factory()->for($producto)->create(['color_id' => $usado->getKey()]);

    panelDeColores()
        ->call('delete', $libre->getKey())
        ->assertDispatched('toast', message: 'Color «Libre» eliminado correctamente.', tone: 'success');

    panelDeColores()
        ->call('delete', $usado->getKey())
        ->assertDispatched('toast', tone: 'error');

    expect(Color::query()->whereKey($libre->getKey())->exists())->toBeFalse()
        ->and(Color::query()->whereKey($usado->getKey())->exists())->toBeTrue();
});

test('filters the colors by name', function () {
    Color::factory()->create(['name' => 'Azul']);
    Color::factory()->create(['name' => 'Rojo']);

    panelDeColores()
        ->set('search', 'az')
        ->assertSee('Azul')
        ->assertDontSee('Rojo');
});

test('the colors list counts its variants and pictures without a query per color', function () {
    $admin = adminForPanel();

    $queriesFor = function (int $colores) use ($admin): int {
        $producto = Product::factory()->create();

        foreach (Color::factory()->count($colores)->create() as $color) {
            ProductVariant::factory()->for($producto)->create(['color_id' => $color->getKey()]);
            ProductImage::factory()->for($producto)->create(['color_id' => $color->getKey()]);
        }

        $admin->hasRole('admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)->test(Colors::class);

        $consultas = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    };

    $conTres = $queriesFor(3);
    $conQuince = $queriesFor(15);

    expect($conQuince)->toBe($conTres);
});

/*
|--------------------------------------------------------------------------
| Materiales
|--------------------------------------------------------------------------
*/

test('creates a material and announces it', function () {
    panelDeMateriales()
        ->call('create')
        ->set('name', 'Algodón')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast', message: 'Material creado correctamente.', tone: 'success');

    expect(Material::query()->where('name', 'Algodón')->exists())->toBeTrue();
});

test('a material name is required and is held to a hundred characters', function () {
    panelDeMateriales()
        ->call('create')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);

    panelDeMateriales()
        ->call('create')
        ->set('name', str_repeat('A', 101))
        ->call('save')
        ->assertHasErrors(['name' => 'max']);

    expect(Material::query()->count())->toBe(0);
});

test('the same material name is refused even when it only differs in accents and case', function (string $nombre) {
    Material::factory()->create(['name' => 'Algodón']);

    panelDeMateriales()
        ->call('create')
        ->set('name', $nombre)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', tone: 'error');

    expect(Material::query()->count())->toBe(1);
})->with(['algodon', 'ALGODÓN']);

test('a material no garment describes can be deleted, and one in use says how many are in the way', function () {
    $libre = Material::factory()->create(['name' => 'Lino']);
    $usado = Material::factory()->create(['name' => 'Algodón']);

    Product::factory()->count(2)->create()->each(
        fn (Product $producto) => $producto->materials()->attach($usado, ['percentage' => 100])
    );

    panelDeMateriales()
        ->call('delete', $libre->getKey())
        ->assertDispatched('toast', message: 'Material «Lino» eliminado correctamente.', tone: 'success');

    panelDeMateriales()
        ->call('delete', $usado->getKey())
        ->assertDispatched('toast', tone: 'error');

    expect(Material::query()->whereKey($libre->getKey())->exists())->toBeFalse()
        ->and(Material::query()->whereKey($usado->getKey())->exists())->toBeTrue();
});

test('a material can be turned off, moved and filtered', function () {
    // Ni «Algodón» ni el nombre del formulario: el texto del panel también cuenta como
    // coincidencia, así que los datos se llaman de forma que no se pisen.
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 0]);
    $seda = Material::factory()->create(['name' => 'Seda', 'order' => 1]);

    panelDeMateriales()->call('toggleActive', $lino->getKey());
    expect($lino->fresh()->is_active)->toBeFalse();

    panelDeMateriales()->call('moveUp', $seda->getKey());
    expect(Material::query()->orderBy('order')->pluck('name')->all())->toBe(['Seda', 'Lino']);

    panelDeMateriales()
        ->set('search', 'lino')
        ->assertSee('Lino')
        ->assertDontSee('Seda');
});

test('the materials list counts its products without a query per material', function () {
    $admin = adminForPanel();

    $queriesFor = function (int $materiales) use ($admin): int {
        $producto = Product::factory()->create();

        foreach (Material::factory()->count($materiales)->create() as $material) {
            $producto->materials()->attach($material, ['percentage' => 100]);
        }

        $admin->hasRole('admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)->test(Materials::class);

        $consultas = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    };

    $conTres = $queriesFor(3);
    $conQuince = $queriesFor(15);

    expect($conQuince)->toBe($conTres);
});

/*
|--------------------------------------------------------------------------
| Los cuatro paneles, por igual
|--------------------------------------------------------------------------
*/

/**
 * `/livewire/update` no pasa por el middleware de la página que renderizó el snapshot,
 * así que el rol se vuelve a pedir dentro de cada componente. El 403 no deja snapshot
 * detrás, de ahí que cada método se compruebe por separado.
 */
test('no catalog accepts a user without the admin role', function (string $componente) {
    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    Livewire::actingAs($vendedor)
        ->test($componente)
        ->assertForbidden();
})->with([Index::class, Sizes::class, Colors::class, Materials::class]);

test('no catalog answers to a guest', function (string $componente) {
    Livewire::test($componente)->assertForbidden();
})->with([Index::class, Sizes::class, Colors::class, Materials::class]);

test('an admin who loses the role can no longer act on the page', function (string $componente, string $metodo, array $parametros) {
    $admin = adminForPanel();

    $panel = Livewire::actingAs($admin)->test($componente);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call($metodo, ...$parametros)->assertForbidden();
})->with([
    'la pagina cambia de pestaña' => [Index::class, 'setTab', ['tallas']],
    'tallas crean una talla' => [Sizes::class, 'save', []],
    'colores crean un color' => [Colors::class, 'save', []],
    'materiales crean un material' => [Materials::class, 'save', []],
]);
