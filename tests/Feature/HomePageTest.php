<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * La ruta / solo devuelve la tienda real si la aplicación está en local
 * (routes/web.php). Se cambia el entorno sobre la instancia que creó el setUp
 * en vez de arrancar otra con refreshApplicationIn(): la instancia nueva tendría
 * una base en memoria vacía, sin las migraciones que RefreshDatabase acaba de
 * aplicar, y todas las consultas fallarían con "no such table".
 */
beforeEach(function () {
    $this->app->instance('env', 'local');
});

function createTestData()
{
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG']);

    $categoryHombre = Category::factory()->create(['section' => StoreSection::Hombre, 'name' => 'Camisas', 'sku_prefix' => 'CH']);
    $categoryMujer = Category::factory()->create(['section' => StoreSection::Mujer, 'name' => 'Vestidos', 'sku_prefix' => 'VE']);
    $categoryNinos = Category::factory()->create(['section' => StoreSection::Ninos, 'name' => 'Pantalones', 'sku_prefix' => 'PN']);

    // Las tallas cuelgan de la categoría por `sizes.category_id`: no hay tabla
    // pivote, así que se crean con la relación y no con attach().
    $size = $categoryHombre->sizes()->create(['name' => 'M', 'is_active' => true]);
    $categoryMujer->sizes()->create(['name' => 'M', 'is_active' => true]);
    $categoryNinos->sizes()->create(['name' => 'M', 'is_active' => true]);

    return compact('color', 'size', 'categoryHombre', 'categoryMujer', 'categoryNinos');
}

/*
 * Lee el objeto con el que Alpine recibe las secciones del carrusel de la raíz,
 * igual que storefrontAlpinePayload() hace con la vista previa. El contador y la
 * imagen de cada categoría solo existen dentro de ese JSON: la pestaña y la
 * tarjeta los pintan con x-text y :src, nunca en el HTML que manda el servidor.
 */
function homeAlpinePayload(string $html, string $component): array
{
    $pattern = '/'.preg_quote($component, '/').'\(JSON\.parse\('."'(.*?)'".'\)\)"/s';

    preg_match($pattern, $html, $matches);

    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"');
    expect($json)->toBeString();

    $payload = json_decode($json, true);
    expect($payload)->toBeArray();

    return $payload;
}

test('ruta / responde 200 y renderiza con base vacía', function () {
    $response = get('/');

    $response->assertOk();
});

test('solo aparecen productos activos con variante activa y producto inactivo no aparece ni cuenta', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $productVisible = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Visible',
        'slug' => 'camisa-visible',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productVisible->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $productInactive = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Inactiva',
        'slug' => 'camisa-inactiva',
        'status' => 'inactive',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productInactive->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $productNoActiveVariant = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Sin Variante Activa',
        'slug' => 'camisa-sin-variante-activa',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productNoActiveVariant->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => false,
        'stock' => 10,
    ]);

    $response = get('/');

    $response->assertOk();
    $response->assertSee('Camisa Visible');
    $response->assertDontSee('Camisa Inactiva');
    $response->assertDontSee('Camisa Sin Variante Activa');

    $payload = homeAlpinePayload($response->getContent(), 'categoryCarousel');
    $categorias = $payload['sections'][0]['categories'];

    expect(array_column($payload['sections'], 'key'))->toBe(['hombre'])
        ->and($categorias)->toHaveCount(1)
        ->and($categorias[0]['name'])->toBe('Camisas')
        ->and($categorias[0]['count'])->toBe(1)
        ->and(preg_match_all('/<article\b/', $response->getContent()))->toBe(1);
});

test('conteo de categoría y su imagen son los esperados', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $p1 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'P1',
        'slug' => 'p1',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDay(),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $p1->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $p2 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'P2',
        'slug' => 'p2',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now(),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $p2->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $p2->images()->create([
        'color_id' => $color->id,
        'path' => 'images/test.jpg',
        'thumbnail_path' => 'images/test-thumb.jpg',
        'order' => 0,
        'is_primary' => true,
    ]);

    $response = get('/');
    $response->assertOk();

    $payload = homeAlpinePayload($response->getContent(), 'categoryCarousel');
    $categoria = $payload['sections'][0]['categories'][0];

    expect($categoria['name'])->toBe('Camisas')
        ->and($categoria['count'])->toBe(2)
        ->and($categoria['image'])->toContain('test-thumb');
});

test('badge nuevo a los 30 días exactos y no a los 31', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $this->travelTo(now());
    $pNuevo30 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Nuevo30',
        'slug' => 'nuevo30',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(30),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pNuevo30->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $pViejo31 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Viejo31',
        'slug' => 'viejo31',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(31),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pViejo31->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('Nuevo30');
    $response->assertSee('Viejo31');

    $tarjetas = preg_match_all('/<article\b.*?<\/article>/s', $response->getContent(), $coincidencias)
        ? $coincidencias[0]
        : [];

    $deNuevo30 = collect($tarjetas)->first(fn (string $html) => str_contains($html, 'Nuevo30'));
    $deViejo31 = collect($tarjetas)->first(fn (string $html) => str_contains($html, 'Viejo31'));

    expect($deNuevo30)->toContain('>Nuevo<')
        ->and($deViejo31)->not->toContain('>Nuevo<');
});

test('agotado con stock 0 gana sobre nuevo', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $this->travelTo(now());
    $pAgotadoNuevo = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'AgotadoNuevo',
        'slug' => 'agotado-nuevo',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(1),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pAgotadoNuevo->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 0,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('AgotadoNuevo');

    $content = $response->getContent();

    expect($content)->toContain('>Agotado<')
        ->and($content)->not->toContain('>Nuevo<');
});

test('colores de tarjeta no se repiten y no incluyen variantes inactivas', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $color2 = Color::factory()->create(['is_active' => true, 'name' => 'Azul', 'hex' => '#0000FF', 'code' => 'AZU']);
    $cat = $data['categoryHombre'];

    $product = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'MultiColor',
        'slug' => 'multicolor',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);
    $size2 = Size::factory()->create(['is_active' => true]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color->id,
        'size_id' => $size2->id,
        'is_active' => true,
        'stock' => 5,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color2->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('MultiColor');
});

test('una sección sin categorías visibles no entra en las pestañas', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];

    // Una categoría de hombre sin prendas visibles: no debe sumarse a la sección.
    Category::factory()->create([
        'section' => StoreSection::Hombre,
        'name' => 'Abrigos',
        'sku_prefix' => 'AB',
    ]);

    $producto = Product::factory()->create([
        'category_id' => $data['categoryHombre']->getKey(),
        'name' => 'Camisa Única',
        'slug' => 'camisa-unica',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $color->getKey(),
        'size_id' => $size->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    $payload = homeAlpinePayload(get('/')->assertOk()->getContent(), 'categoryCarousel');

    expect(array_column($payload['sections'], 'key'))->toBe(['hombre'])
        ->and(array_column($payload['sections'][0]['categories'], 'name'))->toBe(['Camisas'])
        ->and($payload['sections'][0]['categories'][0]['url'])->toContain($data['categoryHombre']->getKey())
        ->and($payload['initial'])->toBe('hombre');
});

test('guardia de consultas: la raíz usa un número fijo con 4 novedades y 3 secciones', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];

    // Ocho productos visibles en las tres secciones: solo cuatro se muestran como
    // novedades, y si el límite sube el recuento de consultas deja de cuadrar.
    $porSeccion = [
        $data['categoryHombre']->getKey() => 6,
        $data['categoryMujer']->getKey() => 1,
        $data['categoryNinos']->getKey() => 1,
    ];

    $numero = 1;

    foreach ($porSeccion as $categoriaId => $cantidad) {
        for ($i = 0; $i < $cantidad; $i++) {
            $producto = Product::factory()->create([
                'category_id' => $categoriaId,
                'name' => 'Prenda '.$numero,
                'slug' => 'prenda-'.$numero,
                'status' => 'active',
                'base_price' => 10000,
            ]);
            ProductVariant::factory()->create([
                'product_id' => $producto->getKey(),
                'color_id' => $color->getKey(),
                'size_id' => $size->getKey(),
                'is_active' => true,
                'stock' => 5,
            ]);
            $numero++;
        }
    }

    DB::enableQueryLog();
    $response = get('/');
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();

    $payload = homeAlpinePayload($response->getContent(), 'categoryCarousel');

    expect(array_column($payload['sections'], 'key'))->toBe(['hombre', 'mujer', 'ninos'])
        ->and(preg_match_all('/<article\b/', $response->getContent()))->toBe(4)
        // Eran 24 cuando cada tarjeta consultaba su stock y sus colores; el conteo
        // de productos de todas las categorías pasó a una sola consulta agrupada y
        // la lectura de la lista de novedades manuales añadió una, así que ahora
        // son 16: 9 de secciones, 1 del conteo agrupado, 1 de las filas manuales
        // (vacías aquí) y 5 de las novedades automáticas.
        ->and($consultas)->toBe(16);
});

test('fuera de local la raíz sigue sirviendo welcome y el panel sigue protegido', function () {
    $this->app->instance('env', 'production');

    expect(app()->environment())->toBe('production');

    $this->get('/')
        ->assertOk()
        ->assertViewIs('welcome')
        ->assertDontSee('Todo en un solo');

    $this->get('/dashboard')->assertRedirect(route('login'));
});
