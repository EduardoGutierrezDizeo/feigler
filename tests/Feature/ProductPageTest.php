<?php

use App\Enums\StoreSection;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

/*
 * La ficha pública /producto/{slug}: datos reales de la base, con el contrato
 * que el comentario de resources/views/storefront/product.blade.php fija.
 *
 * La ruta existe en cualquier entorno; solo /_vista/* está amarrada a local, y
 * para pedirla hay que arrancar la aplicación como local con
 * refreshApplicationIn(), que no comparte la transacción de RefreshDatabase —
 * por eso ese test no toca la base.
 *
 * Los botones de talla y los puntos de color los pinta Alpine dentro de un
 * <template>, así que lo que se comprueba es el objeto con el que arranca
 * productPurchase(), igual que StorefrontPreviewTest hace con la vista previa.
 */

/**
 * Lee la configuración con la que productPurchase() arranca en el x-data.
 */
function productPurchasePayload(string $html): array
{
    $pattern = '/'.preg_quote('productPurchase', '/').'\(JSON\.parse\('."'(.*?)'".'\)\)"/s';

    preg_match($pattern, $html, $matches);

    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"');
    expect($json)->toBeString();

    $payload = json_decode($json, true);
    expect($payload)->toBeArray();

    return $payload;
}

/**
 * Una categoría de hombre con las tallas S, M y L y dos colores del catálogo.
 *
 * Las tallas se crean en desorden para que el orden de la lista salga de
 * `sizes.order` y no del orden de inserción.
 */
function catalogoDeFicha(): array
{
    $category = numberedCategory('CF', 'Camisas');

    $sizeM = $category->sizes()->create(['name' => 'M', 'order' => 2, 'is_active' => true]);
    $sizeS = $category->sizes()->create(['name' => 'S', 'order' => 1, 'is_active' => true]);
    $sizeL = $category->sizes()->create(['name' => 'L', 'order' => 3, 'is_active' => true]);

    $negro = Color::factory()->create(['name' => 'Negro', 'code' => 'NEG', 'hex' => '#000000', 'order' => 1]);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#AA0000', 'order' => 2]);

    return compact('category', 'sizeS', 'sizeM', 'sizeL', 'negro', 'rojo');
}

/**
 * Un producto visible con una variante activa por talla en Negro.
 *
 * @param  array<string, mixed>  $attributes
 */
function productoDeFicha(array $attributes = []): Product
{
    $data = catalogoDeFicha();

    $product = Product::factory()->create(array_merge([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Ejemplo',
        'slug' => 'camisa-ejemplo',
        'status' => 'active',
        'base_price' => 129900,
    ], $attributes));

    foreach (['sizeS', 'sizeM'] as $key) {
        ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'color_id' => $data['negro']->getKey(),
            'size_id' => $data[$key]->getKey(),
            'is_active' => true,
            'stock' => 5,
        ]);
    }

    return $product;
}

/**
 * El número de consultas que hace la ficha de un producto.
 *
 * enableQueryLog() no vacía lo ya registrado, así que hay que limpiarlo a mano
 * o la segunda medición sumaría la primera.
 */
function consultasDeFicha(string $slug): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    get('/producto/'.$slug)->assertOk();
    $total = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $total;
}

test('un producto visible responde 200 y ofrece cada talla de la categoría', function () {
    $product = productoDeFicha();

    $response = get('/producto/'.$product->slug);

    $response->assertOk()
        ->assertSee($product->name)
        ->assertSee('role="group" aria-label="Talla"', false)
        ->assertSee('129.900');

    $purchase = productPurchasePayload($response->getContent());

    expect($purchase['name'])->toBe($product->name)
        ->and($purchase['sizes'])->toBe(['S', 'M'])
        ->and(array_column($purchase['variants'], 'size'))->toBe(['S', 'M']);
});

test('slug inexistente, producto apagado y producto sin variantes activas devuelven 404', function () {
    $data = catalogoDeFicha();

    $apagado = Product::factory()->inactive()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Apagada',
        'slug' => 'camisa-apagada-ficha',
    ]);
    ProductVariant::factory()->create([
        'product_id' => $apagado->getKey(),
        'color_id' => $data['negro']->getKey(),
        'size_id' => $data['sizeS']->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    $sinVarianteActiva = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Sin Variante',
        'slug' => 'camisa-sin-variante-ficha',
        'status' => 'active',
    ]);
    ProductVariant::factory()->inactive()->create([
        'product_id' => $sinVarianteActiva->getKey(),
        'color_id' => $data['negro']->getKey(),
        'size_id' => $data['sizeS']->getKey(),
        'stock' => 5,
    ]);

    get('/producto/slug-que-no-existe')->assertNotFound();
    get('/producto/'.$apagado->slug)->assertNotFound();
    get('/producto/'.$sinVarianteActiva->slug)->assertNotFound();
});

test('un producto con stock total 0 responde 200 y todas sus tallas salen sin stock', function () {
    $product = productoDeFicha(['slug' => 'camisa-agotada']);
    $product->variants()->update(['stock' => 0]);

    $response = get('/producto/camisa-agotada');

    $response->assertOk();

    $purchase = productPurchasePayload($response->getContent());

    expect($purchase['sizes'])->toBe(['S', 'M'])
        ->and(array_column($purchase['variants'], 'stock'))->toBe([0, 0]);
});

test('cada color enseña sus fotos con la principal primero, el sin fotos queda vacío y el inicial es la portada', function () {
    $data = catalogoDeFicha();

    $product = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Fotos',
        'slug' => 'camisa-fotos',
        'status' => 'active',
        'base_price' => 99000,
    ]);

    foreach (['sizeS', 'sizeM'] as $key) {
        foreach (['negro', 'rojo'] as $color) {
            ProductVariant::factory()->create([
                'product_id' => $product->getKey(),
                'color_id' => $data[$color]->getKey(),
                'size_id' => $data[$key]->getKey(),
                'is_active' => true,
                'stock' => 4,
            ]);
        }
    }

    // Rojo es la portada y su principal fue subida la última: tiene que salir
    // primero igual, y su miniatura es la que se guardó al generarla.
    ProductImage::factory()->create([
        'product_id' => $product->getKey(),
        'color_id' => $data['rojo']->getKey(),
        'path' => 'products/rojo-sec.jpg',
        'order' => 5,
        'is_primary' => false,
    ]);
    ProductImage::factory()->primary()->create([
        'product_id' => $product->getKey(),
        'color_id' => $data['rojo']->getKey(),
        'path' => 'products/rojo-1.jpg',
        'thumbnail_path' => 'products/thumb-rojo-1.jpg',
        'order' => 2,
    ]);

    $product->update(['cover_color_id' => $data['rojo']->getKey()]);

    $response = get('/producto/camisa-fotos');

    $response->assertOk();

    $purchase = productPurchasePayload($response->getContent());

    expect(array_column($purchase['colors'], 'name'))->toBe(['Negro', 'Rojo'])
        ->and($purchase['colors'][0]['images'])->toBe([])
        ->and(array_column($purchase['colors'][1]['images'], 'url'))->toBe([
            Storage::disk('public')->url('products/rojo-1.jpg'),
            Storage::disk('public')->url('products/rojo-sec.jpg'),
        ])
        ->and($purchase['colors'][1]['images'][0]['thumb'])->toBe(Storage::disk('public')->url('products/thumb-rojo-1.jpg'))
        ->and($purchase['colors'][1]['images'][1]['thumb'])->toBeNull()
        ->and($purchase['initialColor'])->toBe(1);
});

test('una variante inactiva no aporta color, talla ni stock', function () {
    $data = catalogoDeFicha();

    $product = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Parcial',
        'slug' => 'camisa-parcial',
        'status' => 'active',
        'base_price' => 80000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'color_id' => $data['negro']->getKey(),
        'size_id' => $data['sizeM']->getKey(),
        'is_active' => true,
        'stock' => 7,
    ]);
    ProductVariant::factory()->inactive()->create([
        'product_id' => $product->getKey(),
        'color_id' => $data['rojo']->getKey(),
        'size_id' => $data['sizeS']->getKey(),
        'stock' => 9,
    ]);

    $purchase = productPurchasePayload(get('/producto/camisa-parcial')->assertOk()->getContent());

    expect(array_column($purchase['colors'], 'name'))->toBe(['Negro'])
        ->and($purchase['sizes'])->toBe(['M'])
        ->and($purchase['variants'])->toHaveCount(1)
        ->and($purchase['variants'][0])->toMatchArray([
            'color' => $data['negro']->getKey(),
            'size' => 'M',
            'stock' => 7,
        ]);
});

test('las tallas salen en el orden de la categoría y la combinación sin variante cuenta como sin stock', function () {
    $data = catalogoDeFicha();

    $product = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Tallas',
        'slug' => 'camisa-tallas',
        'status' => 'active',
        'base_price' => 70000,
    ]);

    foreach ([['negro', 'sizeS'], ['negro', 'sizeM'], ['rojo', 'sizeS']] as [$color, $size]) {
        ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'color_id' => $data[$color]->getKey(),
            'size_id' => $data[$size]->getKey(),
            'is_active' => true,
            'stock' => 4,
        ]);
    }

    $purchase = productPurchasePayload(get('/producto/camisa-tallas')->assertOk()->getContent());

    // La L no tiene ninguna variante, así que no se ofrece; la S se crea
    // después que la M y aun así sale primero por `sizes.order`.
    expect($purchase['sizes'])->toBe(['S', 'M']);

    // Sin la combinación Rojo + M, product-purchase.js lee stock 0 y deja la
    // talla deshabilitada para ese color.
    $combinaciones = array_map(
        fn (array $variant): string => $variant['color'].'-'.$variant['size'],
        $purchase['variants'],
    );

    expect($combinaciones)->toEqualCanonicalizing([
        $data['negro']->getKey().'-S',
        $data['negro']->getKey().'-M',
        $data['rojo']->getKey().'-S',
    ]);
});

test('los materiales salen de mayor a menor porcentaje y un producto sin materiales no rompe la vista', function () {
    $data = catalogoDeFicha();

    $lana = Material::factory()->create(['name' => 'Lana', 'order' => 2]);
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);

    $conMateriales = Product::factory()->withMaterials([
        ['id' => $algodon, 'percentage' => 40],
        ['id' => $lana, 'percentage' => 60],
    ])->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Tejida',
        'slug' => 'camisa-tejida',
        'status' => 'active',
        'base_price' => 90000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $conMateriales->getKey(),
        'color_id' => $data['negro']->getKey(),
        'size_id' => $data['sizeS']->getKey(),
        'is_active' => true,
        'stock' => 3,
    ]);

    $html = get('/producto/camisa-tejida')->assertOk()->getContent();

    $posLana = strpos($html, 'Lana 60%');
    $posAlgodon = strpos($html, 'Algodón 40%');

    expect($posLana)->toBeInt()
        ->and($posAlgodon)->toBeInt()
        ->and($posLana)->toBeLessThan($posAlgodon);

    $sinMateriales = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Sin Composición',
        'slug' => 'camisa-sin-composicion',
        'status' => 'active',
        'base_price' => 60000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $sinMateriales->getKey(),
        'color_id' => $data['negro']->getKey(),
        'size_id' => $data['sizeS']->getKey(),
        'is_active' => true,
        'stock' => 2,
    ]);

    get('/producto/camisa-sin-composicion')
        ->assertOk()
        ->assertSee('Composición no indicada.');
});

test('los relacionados ponen la misma categoría primero, completan con la sección y excluyen el actual y los invisibles', function () {
    $data = catalogoDeFicha();
    $pantalones = numberedCategory('PNT', 'Pantalones');
    $vestidos = numberedCategory('VST', 'Vestidos', StoreSection::Mujer);

    /**
     * Un producto visible con una variante activa en la categoría indicada.
     */
    $visible = function (string $slug, $categoria) use ($data): Product {
        $producto = Product::factory()->create([
            'category_id' => $categoria->getKey(),
            'name' => $slug,
            'slug' => str()->slug($slug),
            'status' => 'active',
            'base_price' => 50000,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $producto->getKey(),
            'color_id' => $data['negro']->getKey(),
            'size_id' => $data['sizeS']->getKey(),
            'is_active' => true,
            'stock' => 5,
        ]);

        return $producto;
    };

    $visible('Camisa A1', $data['category']);
    $visible('Camisa A2', $data['category']);
    $visible('Camisa A3', $data['category']);
    $visible('Pantalón B1', $pantalones);
    $visible('Pantalón B2', $pantalones);
    $visible('Pantalón B3', $pantalones);

    $apagada = $visible('Camisa Apagada', $data['category']);
    $apagada->update(['status' => 'inactive']);

    $invisible = $visible('Pantalón Invisible', $pantalones);
    $invisible->variants()->update(['is_active' => false]);

    $visible('Vestido Lejano', $vestidos);

    // El actual se crea el último: si la exclusión fallara, entraría en los
    // cuatro y empujaría a «Pantalón B3» fuera de la lista.
    $actual = $visible('Camisa Actual', $data['category']);

    $html = get('/producto/'.$actual->slug)->assertOk()->getContent();

    expect(preg_match_all('/<article\b/', $html))->toBe(4);

    $orden = ['Camisa A3', 'Camisa A2', 'Camisa A1', 'Pantalón B3'];
    $posiciones = array_map(fn (string $nombre): int|false => strpos($html, $nombre), $orden);
    $enOrden = $posiciones;
    sort($enOrden);

    foreach ($posiciones as $posicion) {
        expect($posicion)->toBeInt();
    }

    expect($posiciones)->toBe($enOrden);

    foreach (['Pantalón B1', 'Pantalón B2', 'Camisa Apagada', 'Pantalón Invisible', 'Vestido Lejano'] as $fuera) {
        expect($html)->not->toContain($fuera);
    }

    // El actual no aparece como tarjeta (su nombre sí está en el título y la miga de pan).
    expect(substr_count($html, 'href="'.route('storefront.product', $actual->slug).'"'))->toBe(0);
});

test('la tarjeta de la portada enlaza a la ruta de producto por slug', function () {
    $this->app->instance('env', 'local');

    $product = productoDeFicha();

    get('/')
        ->assertOk()
        ->assertSeeHtml('href="'.route('storefront.product', $product->slug).'"')
        ->assertDontSeeHtml('href="/producto/'.$product->getKey().'-');
});

test('la ficha hace el mismo número de consultas con 3 y con 12 variantes, imágenes y relacionados', function () {
    $data = catalogoDeFicha();

    $product = Product::factory()->create([
        'category_id' => $data['category']->getKey(),
        'name' => 'Camisa Guardia',
        'slug' => 'camisa-guardia',
        'status' => 'active',
        'base_price' => 10000,
    ]);

    // Escenario pequeño: 3 variantes, 3 imágenes y 2 relacionados.
    foreach (['sizeS', 'sizeM', 'sizeL'] as $key) {
        ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'color_id' => $data['negro']->getKey(),
            'size_id' => $data[$key]->getKey(),
            'is_active' => true,
            'stock' => 5,
        ]);
    }

    for ($i = 1; $i <= 3; $i++) {
        ProductImage::factory()->create([
            'product_id' => $product->getKey(),
            'color_id' => $data['negro']->getKey(),
            'path' => "products/guardia-$i.jpg",
            'order' => $i,
        ]);
    }

    $relacionado = function (int $numero) use ($data, $product): void {
        $otro = Product::factory()->create([
            'category_id' => $product->category_id,
            'name' => 'Camisa Relacionada '.$numero,
            'slug' => 'camisa-relacionada-'.$numero,
            'status' => 'active',
            'base_price' => 10000,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $otro->getKey(),
            'color_id' => $data['negro']->getKey(),
            'size_id' => $data['sizeS']->getKey(),
            'is_active' => true,
            'stock' => 5,
        ]);
    };

    $relacionado(1);
    $relacionado(2);

    $pocas = consultasDeFicha($product->slug);

    // Hasta 12 variantes (4 colores × 3 tallas), 12 imágenes y 11 relacionados.
    $rojo = Color::factory()->create(['name' => 'Rojo Guardia', 'code' => 'RGR', 'order' => 3]);
    $azul = Color::factory()->create(['name' => 'Azul Guardia', 'code' => 'AGR', 'order' => 4]);

    foreach ([$data['rojo'], $rojo, $azul] as $color) {
        foreach (['sizeS', 'sizeM', 'sizeL'] as $key) {
            ProductVariant::factory()->create([
                'product_id' => $product->getKey(),
                'color_id' => $color->getKey(),
                'size_id' => $data[$key]->getKey(),
                'is_active' => true,
                'stock' => 5,
            ]);
        }
    }

    for ($i = 4; $i <= 12; $i++) {
        ProductImage::factory()->create([
            'product_id' => $product->getKey(),
            'color_id' => $data['negro']->getKey(),
            'path' => "products/guardia-$i.jpg",
            'order' => $i,
        ]);
    }

    for ($i = 3; $i <= 11; $i++) {
        $relacionado($i);
    }

    $muchas = consultasDeFicha($product->slug);

    expect($pocas)->toBeGreaterThan(0)
        ->and($muchas)->toBe($pocas);
});

test('la vista previa de producto sigue respondiendo 200 en local', function () {
    $this->refreshApplicationIn('local');

    get('/_vista/producto')->assertOk();
});
