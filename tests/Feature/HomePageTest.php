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

/**
 * Deja visible una categoría en hombre y otra en mujer para que el carrusel de la
 * raíz tenga dos secciones: la pestaña activa y una inactiva.
 */
function createTwoSectionsData()
{
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];

    foreach ([
        'camisa' => $data['categoryHombre'],
        'vestido' => $data['categoryMujer'],
    ] as $slug => $categoria) {
        $producto = Product::factory()->create([
            'category_id' => $categoria->getKey(),
            'name' => 'Prenda '.$categoria->name,
            'slug' => $slug,
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
    }

    return $data;
}

/*
 * Lee el objeto con el que Alpine recibe las secciones del carrusel de la raíz,
 * igual que storefrontAlpinePayload() hace con la vista previa. Las pestañas y el
 * enlace de "Ver todo" los pintan con x-text y :href desde ese JSON; el contador y
 * la imagen de cada categoría viven en los paneles que renderiza Blade.
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

test('cada panel de sección renderiza siempre su fila de puntos y sus flechas', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    get('/')->assertOk()
        ->assertSeeHtml('data-carousel-dots')
        ->assertSeeHtml('data-carousel-arrows');

    expect(substr_count($html, 'data-carousel-dots'))->toBe(count($payload['sections']))
        ->and(substr_count($html, 'data-carousel-arrows'))->toBe(count($payload['sections']) * 2);
});

test('los paneles inactivos son inertes y ocultos del narrador, y el activo no', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $panels = (new DOMXPath($document))->query('//div[@data-category-panel]');

    expect($panels)->toHaveCount(2);

    foreach ($panels as $panel) {
        $key = $panel->getAttribute('data-category-panel');

        if ($key === $payload['initial']) {
            expect($panel->hasAttribute('inert'))->toBeFalse()
                ->and($panel->hasAttribute('aria-hidden'))->toBeFalse();
        } else {
            expect($panel->hasAttribute('inert'))->toBeTrue()
                ->and($panel->getAttribute('aria-hidden'))->toBe('true');
        }
    }
});

test('el inicio no usa x-if', function () {
    $source = file_get_contents(resource_path('views/storefront/home.blade.php'));

    expect($source)->not->toContain('x-if');
});

test('ningún panel se oculta con la clase hidden ni con display:none', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $panels = (new DOMXPath($document))->query('//div[@data-category-panel]');

    foreach ($panels as $panel) {
        foreach (array_merge([$panel], iterator_to_array($panel->getElementsByTagName('*'))) as $nodo) {
            expect(preg_split('/\s+/', trim($nodo->getAttribute('class'))))->not->toContain('hidden')
                ->and($nodo->hasAttribute('hidden'))->toBeFalse()
                ->and($nodo->getAttribute('style'))->not->toContain('display');
        }
    }
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

test('cada panel de categorías muestra las de su propia sección y no las de otra', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $panels = (new DOMXPath($document))->query('//div[@data-category-panel]');
    $textos = [];

    foreach ($panels as $panel) {
        $textos[$panel->getAttribute('data-category-panel')] = $panel->textContent;
    }

    expect($textos)->toHaveKeys(['hombre', 'mujer'])
        ->and($textos['hombre'])->toContain('Camisas')->not->toContain('Vestidos')
        ->and($textos['mujer'])->toContain('Vestidos')->not->toContain('Camisas');
});

test('las claves de los paneles son exactamente las secciones del payload del carrusel', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $panelKeys = [];

    foreach ((new DOMXPath($document))->query('//div[@data-category-panel]') as $panel) {
        $panelKeys[] = $panel->getAttribute('data-category-panel');
    }

    $payloadKeys = array_column($payload['sections'], 'key');
    sort($panelKeys);
    sort($payloadKeys);

    expect($panelKeys)->toBe($payloadKeys);
});

test('exactamente un panel está sin inert y es el de la sección inicial', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $activos = [];

    foreach ((new DOMXPath($document))->query('//div[@data-category-panel]') as $panel) {
        if ($panel->hasAttribute('inert')) {
            continue;
        }
        $activos[] = $panel->getAttribute('data-category-panel');
    }

    expect($activos)->toBe([$payload['initial']]);
});

test('las tarjetas del carrusel conservan el mismo tamaño en todas las secciones aunque el número de categorías cambie', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];

    // Tres categorías visibles en hombre y una en mujer: los paneles no pueden
    // cuadrar si el tamaño de la tarjeta dependiera del número de categorías.
    $categorias = [
        ['categoria' => $data['categoryHombre'], 'slug' => 'camisas'],
        ['categoria' => Category::factory()->create(['section' => StoreSection::Hombre, 'name' => 'Polos', 'sku_prefix' => 'PO']), 'slug' => 'polos'],
        ['categoria' => Category::factory()->create(['section' => StoreSection::Hombre, 'name' => 'Chaquetas', 'sku_prefix' => 'CHQ']), 'slug' => 'chaquetas'],
        ['categoria' => $data['categoryMujer'], 'slug' => 'vestidos'],
    ];

    foreach ($categorias as ['categoria' => $categoria, 'slug' => $slug]) {
        $producto = Product::factory()->create([
            'category_id' => $categoria->getKey(),
            'name' => 'Prenda '.$categoria->name,
            'slug' => $slug,
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
    }

    $html = get('/')->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($document);
    $primeraTarjeta = [];

    foreach (['hombre', 'mujer'] as $key) {
        $tarjetas = $xpath->query('//div[@data-category-panel="'.$key.'"]//div[@data-category-track]/a');
        $primeraTarjeta[$key] = $tarjetas->item(0)->getAttribute('class');
    }

    expect($xpath->query('//div[@data-category-panel="hombre"]//div[@data-category-track]/a'))->toHaveCount(3)
        ->and($xpath->query('//div[@data-category-panel="mujer"]//div[@data-category-track]/a'))->toHaveCount(1)
        ->and($primeraTarjeta['hombre'])->toBe($primeraTarjeta['mujer'])
        ->and($primeraTarjeta['hombre'])->toContain('w-[68%]')
        ->and($primeraTarjeta['hombre'])->toContain('sm:w-[calc((100%-1.5rem)/2)]');
});

test('cada panel declara su estado con :class, :inert y :aria-hidden gobernados por isActive', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    $nPaneles = count($payload['sections']);

    expect(substr_count($html, 'data-category-panel="'))->toBe($nPaneles)
        ->and(substr_count($html, ':inert="!isActive($el)"'))->toBe($nPaneles)
        ->and(substr_count($html, ':aria-hidden="isActive($el) ? false : \'true\'"'))->toBe($nPaneles)
        ->and(substr_count($html, ':class="{ \'visible opacity-100 translate-y-0\': isActive($el), \'invisible opacity-0 translate-y-1 pointer-events-none\': !isActive($el) }"'))->toBe($nPaneles);
});

test('en el HTML inicial solo el panel de la sección inicial está visible y el resto totalmente inactivo', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();
    $payload = homeAlpinePayload($html, 'categoryCarousel');

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//div[@data-category-panel]') as $panel) {
        $clases = preg_split('/\s+/', trim($panel->getAttribute('class')));
        $esInicial = $panel->getAttribute('data-category-panel') === $payload['initial'];

        expect(in_array('visible', $clases, true))->toBe($esInicial)
            ->and(in_array('opacity-100', $clases, true))->toBe($esInicial)
            ->and(in_array('translate-y-0', $clases, true))->toBe($esInicial)
            ->and(in_array('invisible', $clases, true))->toBe(! $esInicial)
            ->and(in_array('opacity-0', $clases, true))->toBe(! $esInicial)
            ->and(in_array('translate-y-1', $clases, true))->toBe(! $esInicial)
            ->and(in_array('pointer-events-none', $clases, true))->toBe(! $esInicial)
            ->and($panel->hasAttribute('inert'))->toBe(! $esInicial);
    }
});

test('ningún panel mezcla clases de estado contradictorias en el HTML inicial', function () {
    createTwoSectionsData();

    $html = get('/')->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//div[@data-category-panel]') as $panel) {
        $clases = preg_split('/\s+/', trim($panel->getAttribute('class')));

        expect(in_array('visible', $clases, true) && in_array('invisible', $clases, true))->toBeFalse()
            ->and(in_array('opacity-0', $clases, true) && in_array('opacity-100', $clases, true))->toBeFalse()
            ->and(in_array('translate-y-0', $clases, true) && in_array('translate-y-1', $clases, true))->toBeFalse();
    }
});
