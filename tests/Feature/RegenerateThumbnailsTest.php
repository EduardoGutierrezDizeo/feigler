<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A product of the given color, the way the gallery helpers of the other test
 * files build one.
 */
function productoDeRegeneradas(Color $color): Product
{
    $producto = Product::factory()->for(Category::factory())->create();

    ProductVariant::factory()->for($producto)->create([
        'color_id' => $color->getKey(),
    ]);

    return $producto;
}

function colorDeRegeneradas(): Color
{
    return Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
}

/**
 * A product image row with its original really on the disk and a thumbnail that
 * was made with an older size sitting on its deterministic address.
 */
function imagenDeRegeneradas(Product $producto, Color $color, string $nombre = 'camisa.jpg', int $ancho = 2400, int $alto = 1600): ProductImage
{
    $carpeta = "products/{$producto->getKey()}/{$color->getKey()}";

    $imagen = ProductImage::factory()->for($producto)->for($color, 'color')->create([
        'order' => 1,
        'path' => $carpeta."/{$nombre}",
        'thumbnail_path' => $carpeta.'/thumbs/'.pathinfo($nombre, PATHINFO_FILENAME).'.webp',
    ]);

    Storage::disk('public')->put(
        $imagen->path,
        UploadedFile::fake()->image('original.jpg', $ancho, $alto)->getContent(),
    );

    // La miniatura vieja lleva el tamaño anterior; regenerar la reemplaza.
    Storage::disk('public')->put($imagen->thumbnail_path, 'miniatura hecha con el tamaño viejo');

    return $imagen;
}

/**
 * Give a category its own photo, the way an admin upload does, without a
 * thumbnail yet.
 */
function fotoDeCategoriaRegenerada(Category $category, string $nombre = 'propia.jpg', int $ancho = 2400, int $alto = 1600): void
{
    $ruta = 'home/categories/'.$nombre;

    Storage::disk('public')->put(
        $ruta,
        UploadedFile::fake()->image('original.jpg', $ancho, $alto)->getContent(),
    );

    $category->update(['home_image_path' => $ruta]);
}

/**
 * The size of a stored picture, read from its own bytes.
 *
 * @return array{width: int, height: int, mime: string}
 */
function tamanoDeRegenerada(string $path): array
{
    $size = getimagesizefromstring(Storage::disk('public')->get($path));

    return [
        'width' => (int) $size[0],
        'height' => (int) $size[1],
        'mime' => $size['mime'],
    ];
}

test('the dry run only counts and reports, writing no file and no column', function () {
    Storage::fake('public');

    $color = colorDeRegeneradas();
    $producto = productoDeRegeneradas($color);
    $imagen = imagenDeRegeneradas($producto, $color);

    $sinOriginal = ProductImage::factory()->for($producto)->for($color, 'color')->create([
        'order' => 2,
        'path' => "products/{$producto->getKey()}/{$color->getKey()}/fantasma.jpg",
    ]);

    $this->artisan('images:regenerate-thumbnails --dry-run --only=products')
        ->expectsOutputToContain('Se regenerarían: 1')
        ->expectsOutputToContain('Omitidas: 1')
        ->expectsOutputToContain($sinOriginal->path)
        // Un original ausente es algo roto, y el modo seco también lo delata.
        ->assertFailed();

    // Ni un byte se escribió: la miniatura vieja sigue siendo texto plano y la
    // columna no se movió, y el original nunca se tocó.
    expect(Storage::disk('public')->get($imagen->thumbnail_path))->toBe('miniatura hecha con el tamaño viejo')
        ->and($imagen->refresh()->thumbnail_path)->toBe($imagen->thumbnail_path)
        ->and(tamanoDeRegenerada($imagen->path))->toMatchArray([
            'width' => 2400,
            'height' => 1600,
            'mime' => 'image/jpeg',
        ]);
});

test('the run replaces the thumbnails of products and categories with the current size and leaves the originals alone', function () {
    Storage::fake('public');

    $color = colorDeRegeneradas();
    $producto = productoDeRegeneradas($color);
    $imagen = imagenDeRegeneradas($producto, $color);

    $categoria = Category::factory()->create();
    fotoDeCategoriaRegenerada($categoria);

    $this->artisan('images:regenerate-thumbnails')
        ->expectsOutputToContain('Productos:')
        ->expectsOutputToContain('Regeneradas: 2')
        ->expectsOutputToContain('Omitidas: 0')
        ->expectsOutputToContain('Fallidas: 0')
        ->assertSuccessful();

    // La miniatura del producto se rehace sobre su dirección determinista, del
    // tamaño nuevo, y el original queda intacto byte a byte.
    expect(tamanoDeRegenerada($imagen->refresh()->thumbnail_path))->toMatchArray([
        'width' => 960,
        'height' => 640,
        'mime' => 'image/webp',
    ])->and(tamanoDeRegenerada($imagen->path))->toMatchArray([
        'width' => 2400,
        'height' => 1600,
        'mime' => 'image/jpeg',
    ]);

    // La foto propia de la categoría recibe la suya en el mismo lugar.
    $nuevo = $categoria->refresh()->home_image_thumbnail_path;

    expect($nuevo)->toBe('home/categories/thumbs/propia.webp')
        ->and(tamanoDeRegenerada($nuevo))->toMatchArray([
            'width' => 960,
            'height' => 640,
            'mime' => 'image/webp',
        ]);
});

test('a second pass on a healthy catalog lands on the same files, so the run is idempotent', function () {
    Storage::fake('public');

    $color = colorDeRegeneradas();
    $producto = productoDeRegeneradas($color);
    $imagen = imagenDeRegeneradas($producto, $color);

    $this->artisan('images:regenerate-thumbnails --only=products')->assertSuccessful();

    $ruta = $imagen->refresh()->thumbnail_path;
    $bytes = Storage::disk('public')->get($ruta);

    $this->artisan('images:regenerate-thumbnails --only=products')
        ->expectsOutputToContain('Regeneradas: 1')
        ->assertSuccessful();

    // El mismo archivo en el mismo sitio, rehecho con los mismos bytes.
    expect($imagen->refresh()->thumbnail_path)->toBe($ruta)
        ->and(Storage::disk('public')->get($ruta))->toBe($bytes);
});

test('an original that is missing is reported and does not stop the rest of the run', function () {
    Storage::fake('public');

    $color = colorDeRegeneradas();
    $producto = productoDeRegeneradas($color);
    $imagen = imagenDeRegeneradas($producto, $color);

    $sinOriginal = ProductImage::factory()->for($producto)->for($color, 'color')->create([
        'order' => 2,
        'path' => "products/{$producto->getKey()}/{$color->getKey()}/fantasma.jpg",
        'thumbnail_path' => "products/{$producto->getKey()}/{$color->getKey()}/thumbs/fantasma.webp",
    ]);

    $this->artisan('images:regenerate-thumbnails --only=products')
        ->expectsOutputToContain('Regeneradas: 1')
        ->expectsOutputToContain('Omitidas: 1')
        ->expectsOutputToContain($sinOriginal->path)
        ->assertFailed();

    // La de al lado sí se regeneró a pesar de la rota.
    expect(tamanoDeRegenerada($imagen->refresh()->thumbnail_path))->toMatchArray([
        'width' => 960,
        'height' => 640,
    ]);
});

test('--only leaves the group it is not asked about untouched', function () {
    Storage::fake('public');

    $color = colorDeRegeneradas();
    $producto = productoDeRegeneradas($color);
    $imagen = imagenDeRegeneradas($producto, $color);

    $categoria = Category::factory()->create();
    fotoDeCategoriaRegenerada($categoria);

    $this->artisan('images:regenerate-thumbnails --only=categories')
        ->expectsOutputToContain('Regeneradas: 1')
        ->assertSuccessful();

    // La miniatura del producto sigue siendo la vieja: nadie la tocó.
    expect(Storage::disk('public')->get($imagen->refresh()->thumbnail_path))->toBe('miniatura hecha con el tamaño viejo')
        ->and($categoria->refresh()->home_image_thumbnail_path)->not->toBeNull();
});

test('a category whose original has no thumbnail yet receives it when the run goes over its photo', function () {
    Storage::fake('public');

    $categoria = Category::factory()->create();
    fotoDeCategoriaRegenerada($categoria);

    $this->artisan('images:regenerate-thumbnails --only=categories')
        ->expectsOutputToContain('Regeneradas: 1')
        ->assertSuccessful();

    expect($categoria->refresh()->home_image_thumbnail_path)
        ->toBe('home/categories/thumbs/propia.webp');
});

test('--only refuses a value that is neither products nor categories', function () {
    $this->artisan('images:regenerate-thumbnails --only=tienda')
        ->expectsOutputToContain('--only solo acepta')
        ->assertExitCode(2);
});
