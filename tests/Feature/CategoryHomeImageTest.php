<?php

use App\Actions\HomePage\SetCategoryHomeImageAuto;
use App\Actions\HomePage\SetCategoryHomeImageProduct;
use App\Actions\HomePage\UploadCategoryHomeImage;
use App\Actions\ProductDetails\DeleteCategory;
use App\Enums\HomeImageSource;
use App\Enums\StoreSection;
use App\Exceptions\HomeImageProductException;
use App\Exceptions\InvalidProductImageException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Una categoría de hombre con un producto visible del que cuelga una foto, todo
 * lo que los cambios de la foto de portada necesitan.
 */
function escenaDeFotoCategoria(): array
{
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG']);
    $categoria = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Camisas', 'sku_prefix' => 'CAM']);
    $talla = $categoria->sizes()->create(['name' => 'M', 'is_active' => true]);

    $producto = Product::factory()->create([
        'category_id' => $categoria->getKey(),
        'name' => 'Camisa',
        'slug' => 'camisa',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $color->getKey(),
        'size_id' => $talla->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    return compact('categoria', 'producto', 'color');
}

/**
 * Una foto de producto con sus dos archivos en el disco, la que los modos que
 * eligen una foto esperan encontrar.
 */
function fotoDeProducto(Product $producto, Color $color, string $path = 'categorias/camisa.jpg'): ProductImage
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

beforeEach(function () {
    Storage::fake(ProductImage::DISK);
});

test('subir una foto a una categoría la guarda y activa el modo subida', function () {
    $data = escenaDeFotoCategoria();
    $categoria = $data['categoria'];

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('camisa.jpg'));

    $fresh = $categoria->fresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Upload)
        ->and($fresh->home_image_path)->toContain('home/categories/')
        ->and($fresh->home_image_thumbnail_path)->not->toBeNull()
        ->and($fresh->home_image_product_image_id)->toBeNull()
        ->and(Storage::disk(ProductImage::DISK)->exists($fresh->home_image_path))->toBeTrue()
        ->and(Storage::disk(ProductImage::DISK)->exists($fresh->home_image_thumbnail_path))->toBeTrue();
});

test('rechaza una foto que pesa demasiado y no toca la categoría', function () {
    $categoria = escenaDeFotoCategoria()['categoria'];

    expect(fn () => (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->create('pesada.jpg', 5000)))
        ->toThrow(InvalidProductImageException::class, 'pesa más de');

    $fresh = $categoria->fresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Auto)
        ->and($fresh->home_image_path)->toBeNull()
        ->and(Storage::disk(ProductImage::DISK)->allFiles())->toBe([]);
});

test('rechaza una foto que no es una imagen admitida', function () {
    $categoria = escenaDeFotoCategoria()['categoria'];

    expect(fn () => (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->create('nota.txt', 10)))
        ->toThrow(InvalidProductImageException::class, 'formato admitido');
});

test('subir una foto nueva borra la anterior cuando el cambio se confirma', function () {
    $categoria = escenaDeFotoCategoria()['categoria'];

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('primera.jpg'));
    $primera = $categoria->fresh()->home_image_path;

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('segunda.jpg'));
    $segunda = $categoria->fresh()->home_image_path;

    expect($segunda)->not->toBe($primera)
        ->and(Storage::disk(ProductImage::DISK)->exists($primera))->toBeFalse()
        ->and(Storage::disk(ProductImage::DISK)->exists($segunda))->toBeTrue();
});

test('una subida que no llega a la base conserva la foto anterior y descarta la nueva', function () {
    $categoria = escenaDeFotoCategoria()['categoria'];

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('anterior.jpg'));
    $anterior = $categoria->fresh()->home_image_path;

    $antes = Collection::make(Storage::disk(ProductImage::DISK)->allFiles())->sort()->values();

    Category::updating(function () {
        throw new RuntimeException('fallo de prueba');
    });

    expect(fn () => (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('nueva.jpg')))
        ->toThrow(RuntimeException::class);

    $despues = Collection::make(Storage::disk(ProductImage::DISK)->allFiles())->sort()->values();

    expect($categoria->fresh()->home_image_path)->toBe($anterior)
        ->and(Storage::disk(ProductImage::DISK)->exists($anterior))->toBeTrue()
        ->and($despues->all())->toBe($antes->all());
});

test('volver a la regla automática borra la foto subida', function () {
    $categoria = escenaDeFotoCategoria()['categoria'];

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('camisa.jpg'));
    $subida = $categoria->fresh()->home_image_path;

    (new SetCategoryHomeImageAuto)($categoria);

    $fresh = $categoria->fresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Auto)
        ->and($fresh->home_image_path)->toBeNull()
        ->and($fresh->home_image_thumbnail_path)->toBeNull()
        ->and($fresh->home_image_product_image_id)->toBeNull()
        ->and(Storage::disk(ProductImage::DISK)->exists($subida))->toBeFalse();
});

test('elegir la foto de un producto guarda la elección', function () {
    $data = escenaDeFotoCategoria();
    $foto = fotoDeProducto($data['producto'], $data['color']);

    (new SetCategoryHomeImageProduct)($data['categoria'], $foto);

    $fresh = $data['categoria']->fresh();

    expect($fresh->home_image_source)->toBe(HomeImageSource::Product)
        ->and($fresh->home_image_product_image_id)->toBe($foto->getKey())
        ->and($fresh->home_image_path)->toBeNull();
});

test('elegir una foto de producto borra la foto subida anterior', function () {
    $data = escenaDeFotoCategoria();
    $categoria = $data['categoria'];

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('camisa.jpg'));
    $subida = $categoria->fresh()->home_image_path;

    $foto = fotoDeProducto($data['producto'], $data['color']);
    (new SetCategoryHomeImageProduct)($categoria, $foto);

    $fresh = $categoria->fresh();

    expect($fresh->home_image_product_image_id)->toBe($foto->getKey())
        ->and(Storage::disk(ProductImage::DISK)->exists($subida))->toBeFalse();
});

test('no deja elegir la foto de un producto que no es de la categoría', function () {
    $data = escenaDeFotoCategoria();

    $otra = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos', 'sku_prefix' => 'VES']);
    $talla = $otra->sizes()->create(['name' => 'M', 'is_active' => true]);
    $producto = Product::factory()->create([
        'category_id' => $otra->getKey(),
        'name' => 'Vestido',
        'slug' => 'vestido',
        'status' => 'active',
        'base_price' => 12000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $data['color']->getKey(),
        'size_id' => $talla->getKey(),
        'is_active' => true,
        'stock' => 3,
    ]);
    $foto = fotoDeProducto($producto, $data['color'], 'categorias/vestido.jpg');

    expect(fn () => (new SetCategoryHomeImageProduct)($data['categoria'], $foto))
        ->toThrow(HomeImageProductException::class, 'no es de un producto de la categoría');
});

test('no deja elegir la foto de un producto que no se muestra', function () {
    $data = escenaDeFotoCategoria();

    $data['producto']->update(['status' => 'inactive']);
    $foto = fotoDeProducto($data['producto'], $data['color']);

    expect(fn () => (new SetCategoryHomeImageProduct)($data['categoria'], $foto))
        ->toThrow(HomeImageProductException::class, 'no se muestra en la tienda');
});

test('borrar la categoría borra también su foto de portada', function () {
    $categoria = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Abrigos', 'sku_prefix' => 'ABR']);
    $categoria->sizes()->create(['name' => 'M', 'is_active' => true]);

    (new UploadCategoryHomeImage)($categoria, UploadedFile::fake()->image('abrigo.jpg'));
    $subida = $categoria->fresh()->home_image_path;

    (new DeleteCategory)($categoria);

    expect(Category::query()->find($categoria->getKey()))->toBeNull()
        ->and(Storage::disk(ProductImage::DISK)->exists($subida))->toBeFalse();
});
