<?php

use App\Actions\HomePage\AddHomeFeaturedProduct;
use App\Actions\HomePage\MoveHomeFeaturedProduct;
use App\Actions\HomePage\RemoveHomeFeaturedProduct;
use App\Enums\StoreSection;
use App\Exceptions\HomeFeaturedProductException;
use App\Models\Category;
use App\Models\Color;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * La base de las novedades: un color, una categoría de hombre y una talla para
 * que los productos de los tests sean visibles de verdad.
 */
function baseDeNovedades(): array
{
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG']);
    $categoria = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Camisas', 'sku_prefix' => 'NOV']);
    $talla = $categoria->sizes()->create(['name' => 'M', 'is_active' => true]);

    return compact('color', 'categoria', 'talla');
}

function productoDeNovedades(array $baseData, string $nombre, string $slug): Product
{
    $producto = Product::factory()->create([
        'category_id' => $baseData['categoria']->getKey(),
        'name' => $nombre,
        'slug' => $slug,
        'status' => 'active',
        'base_price' => 10000,
    ]);
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
 * Los ids de las novedades en el orden en que la portada las leería.
 *
 * @return list<int>
 */
function ordenDeNovedades(): array
{
    return HomeFeaturedProduct::query()
        ->orderBy('order')
        ->orderBy('id')
        ->pluck('product_id')
        ->all();
}

test('añade el primer producto como novedad en el primer puesto', function () {
    $base = baseDeNovedades();
    $producto = productoDeNovedades($base, 'Camisa Uno', 'camisa-uno');

    $fila = (new AddHomeFeaturedProduct)($producto);

    expect($fila->product_id)->toBe($producto->getKey())
        ->and($fila->order)->toBe(0)
        ->and(HomeFeaturedProduct::count())->toBe(1);
});

test('cada producto nuevo se añade al final de la lista', function () {
    $base = baseDeNovedades();
    $uno = productoDeNovedades($base, 'Camisa Uno', 'camisa-uno');
    $dos = productoDeNovedades($base, 'Camisa Dos', 'camisa-dos');

    (new AddHomeFeaturedProduct)($uno);
    (new AddHomeFeaturedProduct)($dos);

    expect(ordenDeNovedades())->toBe([$uno->getKey(), $dos->getKey()]);
});

test('no deja destacar un producto que no se muestra', function () {
    $base = baseDeNovedades();
    $producto = Product::factory()->create([
        'category_id' => $base['categoria']->getKey(),
        'name' => 'Inactivo',
        'slug' => 'inactivo',
        'status' => 'inactive',
        'base_price' => 10000,
    ]);

    expect(fn () => (new AddHomeFeaturedProduct)($producto))
        ->toThrow(HomeFeaturedProductException::class, 'no se muestra en la tienda')
        ->and(HomeFeaturedProduct::count())->toBe(0);
});

test('no deja destacar dos veces el mismo producto', function () {
    $base = baseDeNovedades();
    $producto = productoDeNovedades($base, 'Camisa Uno', 'camisa-uno');

    (new AddHomeFeaturedProduct)($producto);

    expect(fn () => (new AddHomeFeaturedProduct)($producto))
        ->toThrow(HomeFeaturedProductException::class, 'ya está en las novedades')
        ->and(HomeFeaturedProduct::count())->toBe(1);
});

test('no deja destacar más de cuatro productos', function () {
    $base = baseDeNovedades();

    foreach (['Uno', 'Dos', 'Tres', 'Cuatro'] as $index => $nombre) {
        (new AddHomeFeaturedProduct)(productoDeNovedades($base, 'Camisa '.$nombre, 'camisa-'.$index));
    }

    $quinto = productoDeNovedades($base, 'Camisa Cinco', 'camisa-cinco');

    expect(fn () => (new AddHomeFeaturedProduct)($quinto))
        ->toThrow(HomeFeaturedProductException::class, 'ya tienen sus 4 productos')
        ->and(HomeFeaturedProduct::count())->toBe(4);
});

test('quitar una novedad reindexa la lista', function () {
    $base = baseDeNovedades();
    $uno = productoDeNovedades($base, 'Camisa Uno', 'camisa-uno');
    $dos = productoDeNovedades($base, 'Camisa Dos', 'camisa-dos');
    $tres = productoDeNovedades($base, 'Camisa Tres', 'camisa-tres');

    foreach ([$uno, $dos, $tres] as $producto) {
        (new AddHomeFeaturedProduct)($producto);
    }

    $filaDelMedio = HomeFeaturedProduct::query()->where('product_id', $dos->getKey())->first();
    (new RemoveHomeFeaturedProduct)($filaDelMedio);

    expect(HomeFeaturedProduct::query()
        ->orderBy('order')
        ->get()
        ->map(fn (HomeFeaturedProduct $fila): array => [$fila->product_id => $fila->order])
        ->all())
        ->toBe([
            [$uno->getKey() => 0],
            [$tres->getKey() => 1],
        ]);
});

test('mover una novedad sube y baja y no hace nada en los extremos', function () {
    $base = baseDeNovedades();
    $uno = productoDeNovedades($base, 'Camisa Uno', 'camisa-uno');
    $dos = productoDeNovedades($base, 'Camisa Dos', 'camisa-dos');
    $tres = productoDeNovedades($base, 'Camisa Tres', 'camisa-tres');

    foreach ([$uno, $dos, $tres] as $producto) {
        (new AddHomeFeaturedProduct)($producto);
    }

    $filaDe = fn (int $productId): HomeFeaturedProduct => HomeFeaturedProduct::query()
        ->where('product_id', $productId)
        ->first();

    (new MoveHomeFeaturedProduct)($filaDe($tres->getKey()), -1);
    expect(ordenDeNovedades())->toBe([$uno->getKey(), $tres->getKey(), $dos->getKey()]);

    (new MoveHomeFeaturedProduct)($filaDe($uno->getKey()), 1);
    expect(ordenDeNovedades())->toBe([$tres->getKey(), $uno->getKey(), $dos->getKey()]);

    (new MoveHomeFeaturedProduct)($filaDe($uno->getKey()), -1);
    expect(ordenDeNovedades())->toBe([$uno->getKey(), $tres->getKey(), $dos->getKey()]);

    (new MoveHomeFeaturedProduct)($filaDe($uno->getKey()), -1);
    expect(ordenDeNovedades())->toBe([$uno->getKey(), $tres->getKey(), $dos->getKey()]);
});
