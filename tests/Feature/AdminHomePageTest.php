<?php

use App\Actions\HomePage\UploadCategoryHomeImage;
use App\Enums\HomeImageSource;
use App\Enums\StoreSection;
use App\Livewire\Admin\HomePage\Categories;
use App\Livewire\Admin\HomePage\Index;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\get;

/**
 * Los tres orígenes de una foto de portada: la regla automática, una subida
 * propia y una elegida de los productos. Cada escena fabrica un color, una
 * categoría de la sección pedida y un producto visible con su foto, todo con
 * claves que no chocan dentro de la misma prueba.
 */
function escenaInicioPanel(string $prefixo, string $nombre, StoreSection $seccion, string $colorCode): array
{
    $color = Color::factory()->create([
        'is_active' => true,
        'name' => 'Color '.$colorCode,
        'hex' => '#000000',
        'code' => $colorCode,
    ]);
    $categoria = Category::factory()->section($seccion)->create(['name' => $nombre, 'sku_prefix' => $prefixo]);

    $producto = productoConFotoPortada($categoria, $color, 'Prenda '.$prefixo, 'prenda-'.mb_strtolower($prefixo));

    return compact('color', 'categoria', 'producto');
}

/**
 * Un producto activo de la categoría, con su variante visible y una foto
 * primaria con sus dos archivos en el disco.
 */
function productoConFotoPortada(Category $categoria, Color $color, string $nombre, string $slug): Product
{
    $producto = Product::factory()->create([
        'category_id' => $categoria->getKey(),
        'name' => $nombre,
        'slug' => $slug,
        'status' => 'active',
        'base_price' => 10000,
    ]);

    $talla = $categoria->sizes()->firstOrCreate(['name' => 'M'], ['is_active' => true]);

    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $color->getKey(),
        'size_id' => $talla->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    $thumbnail = 'panel/'.$slug.'-thumb.webp';

    Storage::disk(ProductImage::DISK)->put('panel/'.$slug.'.jpg', 'foto');
    Storage::disk(ProductImage::DISK)->put($thumbnail, 'thumb');

    return $producto->images()->create([
        'color_id' => $color->getKey(),
        'path' => 'panel/'.$slug.'.jpg',
        'thumbnail_path' => $thumbnail,
        'order' => 0,
        'is_primary' => true,
    ])->product;
}

/**
 * La pestaña «Categorías» abierta por un administrador.
 */
function panelDeInicio(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Categories::class);
}

/**
 * La página contenedora abierta por un administrador.
 */
function contenedorDeInicio(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Index::class);
}

/**
 * Lee el objeto con el que Alpine recibe las secciones de la portada de la raíz.
 */
function portadaDeInicioPayload(string $html): array
{
    $pattern = '/'.preg_quote('categoryCarousel', '/').'\(JSON\.parse\('."'(.*?)'".'\)\)"/s';

    preg_match($pattern, $html, $matches);

    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"');
    expect($json)->toBeString();

    $payload = json_decode($json, true);
    expect($payload)->toBeArray();

    return $payload;
}

beforeEach(function () {
    $this->app->instance('env', 'local');

    Storage::fake(ProductImage::DISK);
});

test('se llega a la vista principal desde /admin/home-page', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())
        ->get('/admin/home-page')
        ->assertOk()
        ->assertSee('Vista principal')
        ->assertSee('Categorías');
});

test('el contenedor monta la pestaña de categorías y corrige un tab desconocido', function () {
    $this->seed(RoleSeeder::class);

    contenedorDeInicio()
        ->assertSet('tab', 'categorias')
        ->assertSee('Vista principal')
        ->assertSeeHtml('wire:key="home-panel-categorias"')
        ->call('setTab', 'no-existe')
        ->assertSet('tab', 'categorias');
});

test('una categoría en modo subido enseña esa foto y la etiqueta «Foto propia»', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('UP1', 'Camisas', StoreSection::Hombre, 'NEG');

    (new UploadCategoryHomeImage)($escena['categoria'], UploadedFile::fake()->image('propia.jpg'));

    $url = Storage::disk(ProductImage::DISK)->url($escena['categoria']->fresh()->home_image_thumbnail_path);

    panelDeInicio()
        ->assertSeeHtml('src="'.$url.'"')
        ->assertSee('Foto propia');
});

test('a user without the admin role cannot mount the tab', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    Livewire::actingAs($vendedor)
        ->test(Categories::class)
        ->assertForbidden();
});

test('even a guest cannot mount the tab', function () {
    Livewire::test(Categories::class)->assertForbidden();
});

test('an admin who loses the role can no longer act on the tab', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $categoria = escenaInicioPanel('RO1', 'Camisas', StoreSection::Hombre, 'NEG')['categoria'];

    $panel = panelDeInicio($admin);
    $panel->call('changePhoto', $categoria->getKey());

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call('useAutomatic')->assertForbidden();
});

test('la foto que enseña el panel es la misma que pinta la portada', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('EQ1', 'Camisas', StoreSection::Hombre, 'NEG');

    (new UploadCategoryHomeImage)($escena['categoria'], UploadedFile::fake()->image('propia.jpg'));

    $url = Storage::disk(ProductImage::DISK)->url($escena['categoria']->fresh()->home_image_thumbnail_path);

    $categoriaPortada = portadaDeInicioPayload(get('/')->getContent())['sections'][0]['categories'][0];

    expect($categoriaPortada['image'])->toBe($url);

    panelDeInicio()->assertSeeHtml('src="'.$url.'"');
});

test('volver a la regla automática desde el formulario', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('AU1', 'Camisas', StoreSection::Hombre, 'NEG');

    (new UploadCategoryHomeImage)($escena['categoria'], UploadedFile::fake()->image('propia.jpg'));

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->assertSet('photoOption', 'upload')
        ->call('useAutomatic')
        ->assertSet('notice', 'La categoría vuelve a usar su foto automática.')
        ->assertSet('showForm', false);

    $fresh = $escena['categoria']->fresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Auto)
        ->and($fresh->home_image_path)->toBeNull()
        ->and($fresh->home_image_thumbnail_path)->toBeNull();
});

test('subir una foto desde el formulario la guarda y cierra el modal', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('UP2', 'Camisas', StoreSection::Hombre, 'NEG');

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->set('imageUpload', UploadedFile::fake()->image('nueva.jpg'))
        ->call('saveImage')
        ->assertSet('notice', 'Foto de portada guardada correctamente.')
        ->assertSet('showForm', false);

    $fresh = $escena['categoria']->refresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Upload)
        ->and($fresh->home_image_path)->toContain('home/categories/');

    Storage::disk(ProductImage::DISK)->assertExists($fresh->home_image_path);
});

test('el formulario rechaza una subida vacía', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('EV1', 'Camisas', StoreSection::Hombre, 'NEG');

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->call('saveImage')
        ->assertHasErrors(['imageUpload' => 'required'])
        ->assertSee('Selecciona una imagen para subir.')
        ->assertSet('showForm', true);

    expect($escena['categoria']->refresh()->home_image_source)->toBe(HomeImageSource::Auto);
});

test('el formulario rechaza un archivo que no es una imagen y sigue abierto', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('EV2', 'Camisas', StoreSection::Hombre, 'NEG');

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->set('imageUpload', UploadedFile::fake()->create('ficha.pdf', 10))
        ->call('saveImage')
        ->assertHasErrors(['imageUpload'])
        ->assertSet('notice', 'La imagen «ficha.pdf» no tiene un formato admitido; los formatos permitidos son: jpg, jpeg, png, webp.')
        ->assertSet('showForm', true);

    expect($escena['categoria']->refresh()->home_image_source)->toBe(HomeImageSource::Auto);
});

test('el selector solo ofrece fotos de productos visibles de la categoría', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('SL1', 'Camisas', StoreSection::Hombre, 'NEG');
    $otra = escenaInicioPanel('SL2', 'Vestidos', StoreSection::Mujer, 'AZU');

    $oculta = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Abrigos', 'sku_prefix' => 'SL3']);
    $productoOculto = productoConFotoPortada($oculta, $escena['color'], 'Ab rigo', 'abrigo');
    $productoOculto->update(['status' => 'inactive']);

    $visibleA = $escena['producto']->images()->sole();
    $visibleB = $otra['producto']->images()->sole();
    $inactiva = $productoOculto->images()->sole();

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'product')
        ->assertSeeHtml('wire:key="picker-photo-'.$visibleA->getKey().'"')
        ->assertDontSeeHtml('wire:key="picker-photo-'.$visibleB->getKey().'"')
        ->assertDontSeeHtml('wire:key="picker-photo-'.$inactiva->getKey().'"');
});

test('el selector recorta la lista a las 48 más recientes y lo dice', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('LB1', 'Camisas', StoreSection::Hombre, 'NEG');
    $categoria = $escena['categoria'];

    $ultimo = null;

    foreach (range(2, 49) as $i) {
        $ultimo = productoConFotoPortada($categoria, $escena['color'], 'Prenda LB'.$i, 'prenda-lb-'.$i);
    }

    $masReciente = $ultimo->images()->sole();

    panelDeInicio()
        ->call('changePhoto', $categoria->getKey())
        ->set('photoOption', 'product')
        ->assertSee('Se muestran las 48 más recientes.')
        ->assertSeeHtml('wire:key="picker-photo-'.$masReciente->getKey().'"');
});

test('elegir una foto de producto la guarda como portada de la categoría', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('FP1', 'Camisas', StoreSection::Hombre, 'NEG');
    $foto = $escena['producto']->images()->sole();

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'product')
        ->set('selectedImageId', $foto->getKey())
        ->call('useProductPhoto', $foto->getKey())
        ->assertSet('notice', 'Foto de portada guardada correctamente.')
        ->assertSet('showForm', false);

    $fresh = $escena['categoria']->refresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Product)
        ->and($fresh->home_image_product_image_id)->toBe($foto->getKey())
        ->and($fresh->home_image_path)->toBeNull();
});

test('elegir una foto que ya no existe avisa y no toca la categoría', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('FP2', 'Camisas', StoreSection::Hombre, 'NEG');

    panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'product')
        ->call('useProductPhoto', 999999)
        ->assertSet('notice', 'La foto elegida ya no está disponible.')
        ->assertSet('showForm', true);

    expect($escena['categoria']->refresh()->home_image_source)->toBe(HomeImageSource::Auto);
});

test('cambiar de sección cierra el formulario y resetea su estado', function () {
    $this->seed(RoleSeeder::class);

    $hombre = escenaInicioPanel('SE1', 'Camisas', StoreSection::Hombre, 'NEG');
    $mujer = escenaInicioPanel('SE2', 'Vestidos', StoreSection::Mujer, 'AZU');

    panelDeInicio()
        ->call('changePhoto', $hombre['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->assertSet('showForm', true)
        ->call('setSection', 'mujer')
        ->assertSet('showForm', false)
        ->assertSet('editingCategoryId', null)
        ->assertSet('photoOption', 'auto')
        ->assertDontSeeHtml('wire:key="category-home-'.$hombre['categoria']->getKey().'"')
        ->assertSeeHtml('wire:key="category-home-'.$mujer['categoria']->getKey().'"');
});

test('el formulario se abre con la opción que la categoría ya usa', function () {
    $this->seed(RoleSeeder::class);

    $auto = escenaInicioPanel('MO1', 'Camisas', StoreSection::Hombre, 'NEG');
    $subida = escenaInicioPanel('MO2', 'Vestidos', StoreSection::Mujer, 'AZU');

    (new UploadCategoryHomeImage)($subida['categoria'], UploadedFile::fake()->image('propia.jpg'));

    panelDeInicio()
        ->call('changePhoto', $auto['categoria']->getKey())
        ->assertSet('photoOption', 'auto')
        ->call('changePhoto', $subida['categoria']->getKey())
        ->assertSet('photoOption', 'upload');
});

test('el número de consultas del panel no cambia entre 3 y 12 categorías', function () {
    $this->seed(RoleSeeder::class);

    escenaInicioPanel('LG1', 'Primera', StoreSection::Hombre, 'NEG');
    escenaInicioPanel('LG2', 'Segunda', StoreSection::Mujer, 'AZU');
    escenaInicioPanel('LG3', 'Tercera', StoreSection::Ninos, 'VER');

    $medicion = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        panelDeInicio()->html();

        $total = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $total;
    };

    $primeras = $medicion();

    foreach (range(4, 12) as $n) {
        escenaInicioPanel('LG'.$n, 'Extra '.$n, StoreSection::Hombre, str_pad((string) $n, 3, '0', STR_PAD_LEFT));
    }

    $segundas = $medicion();

    expect($segundas)->toBe($primeras);
});

test('el número de consultas del selector no cambia entre 4 y 49 fotos', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('PI1', 'Camisas', StoreSection::Hombre, 'NEG');
    $categoria = $escena['categoria'];

    foreach (range(2, 4) as $n) {
        productoConFotoPortada($categoria, $escena['color'], 'Prenda PI'.$n, 'prenda-pi-'.$n);
    }

    $medicion = function () use ($categoria): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        panelDeInicio()
            ->call('changePhoto', $categoria->getKey())
            ->set('photoOption', 'product');

        $total = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $total;
    };

    $primeras = $medicion();

    foreach (range(5, 49) as $n) {
        productoConFotoPortada($categoria, $escena['color'], 'Prenda PI'.$n, 'prenda-pi-'.$n);
    }

    $segundas = $medicion();

    expect($segundas)->toBe($primeras);
});

test('todo elemento x-show con previewUrl o currentUrl queda bajo el x-data de la previa', function () {
    $this->seed(RoleSeeder::class);

    $escena = escenaInicioPanel('PP1', 'Camisas', StoreSection::Hombre, 'NEG');

    $html = panelDeInicio()
        ->call('changePhoto', $escena['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->assertSee('Guardar imagen')
        ->html();

    $dom = new DOMDocument;

    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    expect($xpath->query('//*[@x-show]')->length)->toBeGreaterThan(0);

    foreach ($xpath->query('//*[@x-show]') as $elemento) {
        $expresion = $elemento->getAttribute('x-show');

        if ($expresion === '' || preg_match('/previewUrl|currentUrl/', $expresion) !== 1) {
            continue;
        }

        $bajoElXData = false;

        for ($nodo = $elemento->parentNode; $nodo !== null; $nodo = $nodo->parentNode) {
            if ($nodo instanceof DOMElement && str_starts_with($nodo->getAttribute('x-data'), 'homeImageUploadPreview(')) {
                $bajoElXData = true;

                break;
            }
        }

        expect($bajoElXData)->toBeTrue('El elemento x-show="'.$expresion.'" quedó fuera del x-data de la previa.');
    }
});

test('ninguna vista del módulo «Vista principal» usa x-if', function () {
    foreach (glob(resource_path('views/livewire/admin/home-page/*.blade.php')) as $vista) {
        expect(file_get_contents($vista))->not->toContain('x-if');
    }
});

test('las expresiones de las directivas x del bloque de subida no incrustan literales de Blade', function () {
    $vista = (string) file_get_contents(resource_path('views/livewire/admin/home-page/categories.blade.php'));

    preg_match_all('/\s((?:x-[a-z0-9_.:-]+|:[a-z0-9_-]+|@[a-z0-9_.:-]+(?:\.[a-z0-9_-]+)*))="([^"]*)"/i', $vista, $coincidencias, PREG_SET_ORDER);

    expect($coincidencias)->not->toBeEmpty();

    foreach ($coincidencias as [$atributo, $expresion]) {
        if ($atributo === 'x-data') {
            continue;
        }

        expect($expresion)->not->toMatch('/\{\{|\}\}|@js|<\?php/');
    }
});

test('el x-data de la previa recibe la URL actual como tercer argumento', function () {
    $this->seed(RoleSeeder::class);

    $conFoto = escenaInicioPanel('JD1', 'Camisas', StoreSection::Hombre, 'NEG');

    (new UploadCategoryHomeImage)($conFoto['categoria'], UploadedFile::fake()->image('propia.jpg'));

    $url = Storage::disk(ProductImage::DISK)->url($conFoto['categoria']->fresh()->home_image_thumbnail_path);

    panelDeInicio()
        ->call('changePhoto', $conFoto['categoria']->getKey())
        ->set('photoOption', 'upload')
        ->assertSeeHtml('x-data="homeImageUploadPreview($wire, \'imageUpload\', '.Js::from($url).')"');
});

test('el x-data de la previa recibe null cuando la categoría no tiene foto', function () {
    $this->seed(RoleSeeder::class);

    $categoria = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Básicos', 'sku_prefix' => 'JD2']);

    panelDeInicio()
        ->call('changePhoto', $categoria->getKey())
        ->set('photoOption', 'upload')
        ->assertSeeHtml('x-data="homeImageUploadPreview($wire, \'imageUpload\', null)"');
});

test('el aviso de apoyo solo aparece para categorías sin productos visibles', function () {
    $this->seed(RoleSeeder::class);

    escenaInicioPanel('AV1', 'Camisas', StoreSection::Hombre, 'NEG');
    $vacia = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Básicos', 'sku_prefix' => 'AV2']);

    panelDeInicio()
        ->assertSee('1 producto visible')
        ->assertDontSee('Aún no aparece en el inicio')
        ->assertDontSee('La categoría no tiene productos visibles (un producto activo con al menos una variante activa).');

    panelDeInicio()
        ->call('setSection', 'mujer')
        ->assertSee('Aún no aparece en el inicio')
        ->assertSee('La categoría no tiene productos visibles (un producto activo con al menos una variante activa).');
});
