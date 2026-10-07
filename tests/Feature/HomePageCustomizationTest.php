<?php

use App\Actions\HomePage\AddHomeFeaturedProduct;
use App\Actions\HomePage\SetCategoryHomeImageProduct;
use App\Actions\HomePage\UploadCategoryHomeImage;
use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

/*
 * La ruta / solo devuelve la tienda real si la aplicación está en local
 * (routes/web.php), igual que en HomePageTest.
 */
beforeEach(function () {
    $this->app->instance('env', 'local');

    Storage::fake(ProductImage::DISK);
});

/**
 * La base de la portada: un color, una categoría de hombre y su talla.
 */
function baseDeInicio(): array
{
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG']);
    $categoria = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Camisas', 'sku_prefix' => 'INI']);
    $talla = $categoria->sizes()->create(['name' => 'M', 'is_active' => true]);

    return compact('color', 'categoria', 'talla');
}

function productoDeInicio(array $baseData, string $nombre, string $slug, ?Carbon $createdAt = null): Product
{
    $producto = Product::factory()->create(array_filter([
        'category_id' => $baseData['categoria']->getKey(),
        'name' => $nombre,
        'slug' => $slug,
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => $createdAt,
    ]));
    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $baseData['color']->getKey(),
        'size_id' => $baseData['talla']->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    return $producto;
}

/**
 * Una foto de producto con sus dos archivos en el disco.
 */
function imagenDeInicio(Product $producto, Color $color, string $path): ProductImage
{
    $thumbnail = substr($path, 0, -4).'-thumb.webp';

    Storage::disk(ProductImage::DISK)->put($path, 'foto');
    Storage::disk(ProductImage::DISK)->put($thumbnail, 'thumb');

    return $producto->images()->create([
        'color_id' => $color->getKey(),
        'path' => $path,
        'thumbnail_path' => $thumbnail,
        'order' => 0,
        'is_primary' => true,
    ]);
}

/**
 * Lee el objeto con el que Alpine recibe las secciones del carrusel de la raíz,
 * igual que homeAlpinePayload() en HomePageTest (pero los ficheros de test solo
 * comparten los helpers de Pest.php, así que cada uno define el suyo).
 */
function payloadDeInicio(string $html, string $component): array
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

/**
 * Los slugs de las tarjetas de producto de la portada, en el orden en que se
 * pintan. Cada tarjeta enlaza a /producto/{slug}, que es por donde se identifica.
 *
 * @return list<string|null>
 */
function tarjetasDeInicio(string $html): array
{
    $tarjetas = preg_match_all('/<article\b.*?<\/article>/s', $html, $coincidencias)
        ? $coincidencias[0]
        : [];

    return array_map(function (string $tarjeta): ?string {
        preg_match('/producto\/([a-z0-9-]+)/', $tarjeta, $coincidencias);

        return $coincidencias[1] ?? null;
    }, $tarjetas);
}

/**
 * Las consultas que dispara la raíz, con el log recién empezado.
 */
function consultasDeInicio(): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = get('/');
    $consultas = count(DB::getQueryLog());

    DB::disableQueryLog();

    $response->assertOk();

    return $consultas;
}

test('una categoría en modo subida muestra su propia foto', function () {
    $base = baseDeInicio();
    productoDeInicio($base, 'Camisa', 'camisa');

    (new UploadCategoryHomeImage)($base['categoria'], UploadedFile::fake()->image('camisa.jpg'));

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('home/categories/')
        ->and($categoria['count'])->toBe(1);
});

test('una categoría en modo producto muestra la foto elegida', function () {
    $base = baseDeInicio();
    $producto = productoDeInicio($base, 'Camisa', 'camisa');
    $foto = imagenDeInicio($producto, $base['color'], 'categorias/camisa.jpg');

    (new SetCategoryHomeImageProduct)($base['categoria'], $foto);

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('camisa-thumb');
});

test('una categoría cuya foto elegida ya no existe vuelve a su regla automática', function () {
    $base = baseDeInicio();
    $elegida = imagenDeInicio(
        productoDeInicio($base, 'Camisa', 'camisa', now()->subDay()),
        $base['color'],
        'categorias/camisa.jpg',
    );
    imagenDeInicio(
        productoDeInicio($base, 'Otra', 'otra', now()),
        $base['color'],
        'categorias/otra.jpg',
    );

    (new SetCategoryHomeImageProduct)($base['categoria'], $elegida);
    $elegida->delete();

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('otra-thumb');
});

test('una categoría cuya foto es de un producto movido de categoría vuelve a su regla automática', function () {
    $base = baseDeInicio();
    $movido = productoDeInicio($base, 'Movido', 'movido', now()->subDay());
    $foto = imagenDeInicio($movido, $base['color'], 'categorias/movido.jpg');
    imagenDeInicio(
        productoDeInicio($base, 'Otra', 'otra', now()),
        $base['color'],
        'categorias/otra.jpg',
    );

    (new SetCategoryHomeImageProduct)($base['categoria'], $foto);

    $otraCategoria = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos', 'sku_prefix' => 'VES']);
    $movido->update(['category_id' => $otraCategoria->getKey()]);

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('otra-thumb');
});

test('una categoría cuya foto es de un producto que ya no se muestra vuelve a su regla automática', function () {
    $base = baseDeInicio();
    $apagado = productoDeInicio($base, 'Apagado', 'apagado', now()->subDays(2));
    $foto = imagenDeInicio($apagado, $base['color'], 'categorias/apagado.jpg');
    imagenDeInicio(
        productoDeInicio($base, 'Otra', 'otra', now()),
        $base['color'],
        'categorias/otra.jpg',
    );

    (new SetCategoryHomeImageProduct)($base['categoria'], $foto);

    $apagado->status = 'inactive';
    $apagado->save();

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('otra-thumb');
});

test('sin decisión manual la categoría sigue mostrando la foto automática', function () {
    $base = baseDeInicio();
    productoDeInicio($base, 'Viejo', 'viejo', now()->subDay());
    $nuevo = productoDeInicio($base, 'Nuevo', 'nuevo', now());
    imagenDeInicio($nuevo, $base['color'], 'categorias/nuevo.jpg');

    $html = get('/')->getContent();
    $categoria = payloadDeInicio($html, 'categoryCarousel')['sections'][0]['categories'][0];

    expect($categoria['image'])->toContain('nuevo-thumb')
        ->and($categoria['count'])->toBe(2);
});

test('las novedades manuales se muestran en el orden elegido', function () {
    $base = baseDeInicio();

    foreach (['Uno' => 0, 'Dos' => 1, 'Tres' => 2, 'Cuatro' => 3, 'Cinco' => 4] as $nombre => $indice) {
        productoDeInicio($base, 'Camisa '.$nombre, 'camisa-'.$indice);
    }

    foreach (['camisa-3', 'camisa-0', 'camisa-1'] as $slug) {
        (new AddHomeFeaturedProduct)(Product::query()->where('slug', $slug)->first());
    }

    $html = get('/')->getContent();

    expect(tarjetasDeInicio($html))->toBe(['camisa-3', 'camisa-0', 'camisa-1']);
});

test('un elegido que dejó de ser visible se omite sin tumbar la lista', function () {
    $base = baseDeInicio();
    $oculto = productoDeInicio($base, 'Camisa Oculta', 'camisa-oculta');
    $visible = productoDeInicio($base, 'Camisa Visible', 'camisa-visible');

    (new AddHomeFeaturedProduct)($oculto);
    (new AddHomeFeaturedProduct)($visible);

    $oculto->update(['status' => 'inactive']);

    $html = get('/')->getContent();

    expect(tarjetasDeInicio($html))->toBe(['camisa-visible']);
});

test('las novedades manuales no se completan con productos automáticos', function () {
    $base = baseDeInicio();

    foreach (['Uno' => 0, 'Dos' => 1, 'Tres' => 2] as $nombre => $indice) {
        productoDeInicio($base, 'Camisa '.$nombre, 'camisa-'.$indice);
    }

    (new AddHomeFeaturedProduct)(Product::query()->where('slug', 'camisa-1')->first());

    $html = get('/')->getContent();

    expect(tarjetasDeInicio($html))->toBe(['camisa-1']);
});

test('si ninguno de los elegidos se muestra vuelven las novedades automáticas', function () {
    $base = baseDeInicio();
    productoDeInicio($base, 'Camisa Uno', 'camisa-uno', now()->subDays(4));
    productoDeInicio($base, 'Camisa Dos', 'camisa-dos', now()->subDays(3));
    $tres = productoDeInicio($base, 'Camisa Tres', 'camisa-tres', now()->subDays(2));
    productoDeInicio($base, 'Camisa Cuatro', 'camisa-cuatro', now()->subDays(1));
    productoDeInicio($base, 'Camisa Cinco', 'camisa-cinco', now());

    (new AddHomeFeaturedProduct)($tres);
    $tres->update(['status' => 'inactive']);

    $html = get('/')->getContent();

    expect(tarjetasDeInicio($html))->toBe(['camisa-cinco', 'camisa-cuatro', 'camisa-dos', 'camisa-uno']);
});

test('sin filas manuales las novedades son las de siempre', function () {
    $base = baseDeInicio();
    productoDeInicio($base, 'Camisa Uno', 'camisa-uno', now()->subDays(4));
    productoDeInicio($base, 'Camisa Dos', 'camisa-dos', now()->subDays(3));
    productoDeInicio($base, 'Camisa Tres', 'camisa-tres', now()->subDays(2));
    productoDeInicio($base, 'Camisa Cuatro', 'camisa-cuatro', now()->subDays(1));
    productoDeInicio($base, 'Camisa Cinco', 'camisa-cinco', now());

    $html = get('/')->getContent();

    expect(tarjetasDeInicio($html))->toBe(['camisa-cinco', 'camisa-cuatro', 'camisa-tres', 'camisa-dos']);
});

test('el número de consultas no cambia entre 3 y 9 categorías mezcladas', function () {
    $base = baseDeInicio();
    $color = $base['color'];
    $talla = $base['talla'];

    $categorias = [
        $base['categoria'],
        Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos', 'sku_prefix' => 'VES']),
        Category::factory()->section(StoreSection::Ninos)->create(['name' => 'Pantalones', 'sku_prefix' => 'PAN']),
    ];

    foreach ($categorias as $indice => $categoria) {
        foreach (['a', 'b'] as $sufijo) {
            $producto = Product::factory()->create([
                'category_id' => $categoria->getKey(),
                'name' => 'Prenda '.$indice.$sufijo,
                'slug' => 'prenda-'.$indice.'-'.$sufijo,
                'status' => 'active',
                'base_price' => 10000,
                'created_at' => now()->subMinutes(3)->addMinutes($indice),
            ]);
            ProductVariant::factory()->create([
                'product_id' => $producto->getKey(),
                'color_id' => $color->getKey(),
                'size_id' => $talla->getKey(),
                'is_active' => true,
                'stock' => 5,
            ]);
        }
    }

    $productoDeCamisas = $categorias[0]->products()->first();
    $foto = $productoDeCamisas->images()->create([
        'color_id' => $color->getKey(),
        'path' => 'categorias/camisas.jpg',
        'thumbnail_path' => 'categorias/camisas-thumb.webp',
        'order' => 0,
        'is_primary' => true,
    ]);
    (new SetCategoryHomeImageProduct)($categorias[0], $foto);

    $primeras = consultasDeInicio();

    // Seis categorías más, dos por sección, con el mismo modo mezclado de
    // siempre: si las consultas dependieran del número de categorías, esta
    // segunda medición tendría que ser mayor.
    foreach ([StoreSection::Hombre, StoreSection::Mujer, StoreSection::Ninos] as $seccion) {
        for ($indice = 0; $indice < 2; $indice++) {
            $nueva = Category::factory()->section($seccion)->create();
            $producto = Product::factory()->create([
                'category_id' => $nueva->getKey(),
                'name' => 'Extra '.$seccion->value.$indice,
                'slug' => 'extra-'.$seccion->value.'-'.$indice,
                'status' => 'active',
                'base_price' => 10000,
                'created_at' => now()->subMinutes(1),
            ]);
            ProductVariant::factory()->create([
                'product_id' => $producto->getKey(),
                'color_id' => $color->getKey(),
                'size_id' => $talla->getKey(),
                'is_active' => true,
                'stock' => 5,
            ]);
        }
    }

    $segundas = consultasDeInicio();

    expect($segundas)->toBe($primeras);
});
