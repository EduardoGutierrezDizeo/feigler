<?php

use App\Actions\Products\DeleteProductImage;
use App\Actions\Products\UploadProductImages;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductImageThumbnailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * A product sold in the given colors, one variant of each.
 */
function productoConMiniaturas(Color ...$colores): Product
{
    $producto = Product::factory()->for(Category::factory())->create();

    foreach ($colores as $color) {
        ProductVariant::factory()->for($producto)->create([
            'color_id' => $color->getKey(),
        ]);
    }

    return $producto;
}

function colorDeMiniaturas(): Color
{
    return Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
}

function otroColorDeMiniaturas(): Color
{
    return Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#332211']);
}

/**
 * A picture of the size asked for, as the browser sends it.
 */
function fotoGrande(string $nombre = 'camisa.jpg', int $ancho = 1000, int $alto = 600): UploadedFile
{
    return UploadedFile::fake()->image($nombre, $ancho, $alto);
}

/**
 * Write the original of a row that was created by hand, which is what the copy
 * command reads: the pictures it goes over were uploaded long before there were
 * thumbnails, and a row with no file behind it is a broken picture.
 */
function guardarOriginalDe(ProductImage $imagen, int $ancho = 1000, int $alto = 600): void
{
    Storage::disk(ProductImage::DISK)->put(
        $imagen->path,
        UploadedFile::fake()->image('foto.jpg', $ancho, $alto)->getContent(),
    );
}

/**
 * The size of a stored picture, read from its own bytes.
 *
 * @return array{width: int, height: int, mime: string}
 */
function tamanoDe(string $path): array
{
    $size = getimagesizefromstring(Storage::disk('public')->get($path));

    return [
        'width' => (int) $size[0],
        'height' => (int) $size[1],
        'mime' => $size['mime'],
    ];
}

/**
 * A thumbnailer with the pixel ceiling within reach of a small picture, so that
 * the ceiling can be tested without building a twenty-megapixel one.
 */
function thumbnailerConTechoDe(int $pixels): ProductImageThumbnailer
{
    return new ProductImageThumbnailer(app(ImageManager::class), $pixels);
}

/**
 * A JPEG of the given size carrying an EXIF orientation tag, which is what a
 * camera leaves behind when the picture was taken with the phone in vertical.
 */
function jpegConOrientacion(int $orientacion, int $ancho = 200, int $alto = 100): string
{
    $base = imagecreatetruecolor($ancho, $alto);
    imagefilledrectangle($base, 0, 0, (int) ($ancho / 2) - 1, $alto - 1, (int) imagecolorallocate($base, 255, 0, 0));
    imagefilledrectangle($base, (int) ($ancho / 2), 0, $ancho - 1, $alto - 1, (int) imagecolorallocate($base, 0, 0, 255));

    ob_start();
    imagejpeg($base, null, 95);
    $jpeg = (string) ob_get_clean();
    imagedestroy($base);

    $exif = "Exif\0\0"
        .pack('v', 0x4949).pack('v', 0x002A).pack('V', 8)
        .pack('v', 1)
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientacion).pack('v', 0)
        .pack('V', 0);

    return "\xFF\xD8".("\xFF\xE1".pack('n', strlen($exif) + 2).$exif).substr($jpeg, 2);
}

test('an uploaded picture is thumbnailed next to it, in webp and at most 480 px on its longest side', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $imagen = (new UploadProductImages)($producto, $azul, [fotoGrande('camisa.jpg', 1000, 600)])->first();

    $carpeta = "products/{$producto->getKey()}/{$azul->getKey()}";

    expect($imagen->thumbnail_path)->toBe($carpeta.'/thumbs/'.pathinfo($imagen->path, PATHINFO_FILENAME).'.webp');

    Storage::disk('public')->assertExists($imagen->thumbnail_path);

    // 1000x600 se reduce a la mitad de lado largo y conserva la proporción.
    expect(tamanoDe($imagen->thumbnail_path))->toBe([
        'width' => 480,
        'height' => 288,
        'mime' => 'image/webp',
    ]);
});

test('a picture smaller than the thumbnail is copied at its own size and never enlarged', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $imagen = (new UploadProductImages)($producto, $azul, [fotoGrande('chica.jpg', 120, 80)])->first();

    expect(tamanoDe($imagen->thumbnail_path))->toBe([
        'width' => 120,
        'height' => 80,
        'mime' => 'image/webp',
    ]);
});

test('the original stays untouched next to its thumbnail', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $original = fotoGrande('camisa.jpg', 1000, 600);
    $imagen = (new UploadProductImages)($producto, $azul, [$original])->first();

    expect(tamanoDe($imagen->path))->toBe([
        'width' => 1000,
        'height' => 600,
        'mime' => 'image/jpeg',
    ])->and($imagen->path)->not->toEndWith('.webp');
});

test('a picture over the pixel ceiling is stored without a thumbnail and the reason is logged', function () {
    Storage::fake('public');
    Log::spy();

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    app()->instance(ProductImageThumbnailer::class, thumbnailerConTechoDe(10));

    $imagen = (new UploadProductImages)($producto, $azul, [fotoGrande('enorme.jpg', 1000, 600)])->first();

    expect($imagen->thumbnail_path)->toBeNull()
        ->and($imagen->refresh()->exists)->toBeTrue();

    Storage::disk('public')->assertExists($imagen->path);
    Storage::disk('public')->assertDirectoryEmpty("products/{$producto->getKey()}/{$azul->getKey()}/thumbs");

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, $imagen->path)
            && str_contains($mensaje, 'producto '.$producto->getKey())
            && str_contains($mensaje, 'color '.$azul->getKey()));
});

test('a file that is not a picture is stored without a thumbnail and the upload goes on', function () {
    Storage::fake('public');
    Log::spy();

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $rota = UploadedFile::fake()->createWithContent('rota.jpg', 'esto no es una imagen');

    $imagen = (new UploadProductImages)($producto, $azul, [$rota])->first();

    expect($imagen)->not->toBeNull()
        ->and($imagen->thumbnail_path)->toBeNull()
        ->and($producto->images()->count())->toBe(1);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, $imagen->path)
            && str_contains($mensaje, 'no es una imagen'));
});

test('an upload whose rows never land takes its thumbnails off the disk too', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    ProductImage::creating(function (): void {
        throw new RuntimeException('la fila no entra');
    });

    expect(fn () => (new UploadProductImages)($producto, $azul, [fotoGrande('camisa.jpg', 1000, 600)]))
        ->toThrow(RuntimeException::class);

    // Ni el original ni su miniatura sobreviven a una subida que no llegó a existir.
    Storage::disk('public')->assertDirectoryEmpty("products/{$producto->getKey()}/{$azul->getKey()}");

    expect($producto->images()->count())->toBe(0);
});

test('deleting a picture takes its thumbnail with it and leaves the others alone', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $primera = (new UploadProductImages)($producto, $azul, [fotoGrande('una.jpg', 1000, 600)])->first();
    $segunda = (new UploadProductImages)($producto, $azul, [fotoGrande('dos.jpg', 1000, 600)])->first();

    (new DeleteProductImage)($producto, $primera->getKey());

    Storage::disk('public')->assertMissing($primera->path);
    Storage::disk('public')->assertMissing($primera->thumbnail_path);
    Storage::disk('public')->assertExists($segunda->path);
    Storage::disk('public')->assertExists($segunda->thumbnail_path);
});

test('deleting a product erases the thumbnails of its pictures', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $primera = (new UploadProductImages)($producto, $azul, [fotoGrande('una.jpg', 1000, 600)])->first();
    $segunda = (new UploadProductImages)($producto, $azul, [fotoGrande('dos.jpg', 1000, 600)])->first();

    $producto->delete();

    foreach ([$primera, $segunda] as $imagen) {
        Storage::disk('public')->assertMissing($imagen->path);
        Storage::disk('public')->assertMissing($imagen->thumbnail_path);
    }
});

test('the picture is shown with its thumbnail, or with the original when there is none', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $conMiniatura = (new UploadProductImages)($producto, $azul, [fotoGrande('una.jpg', 1000, 600)])->first();

    $sinMiniatura = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'path' => 'products/'.fake()->unique()->slug(4).'.jpg',
    ]);

    $disk = Storage::disk('public');

    expect($conMiniatura->thumbnailUrl())->toBe($disk->url($conMiniatura->thumbnail_path))
        ->and($sinMiniatura->thumbnail_path)->toBeNull()
        ->and($sinMiniatura->thumbnailUrl())->toBe($disk->url($sinMiniatura->path))
        // La columna basta: preguntar al disco por cada fila sería una petición
        // por cada imagen de la lista.
        ->and($sinMiniatura->url)->toBe($disk->url($sinMiniatura->path));
});

test('the copy command sorts every picture into what it did with it, and is happy to run twice', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $carpeta = "products/{$producto->getKey()}/{$azul->getKey()}";

    $sinMiniatura = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'order' => 1,
        'path' => $carpeta.'/sin-miniatura.jpg',
    ]);

    $rota = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'order' => 2,
        'path' => $carpeta.'/rota.jpg',
        'thumbnail_path' => $carpeta.'/thumbs/rota.webp',
    ]);

    $correcta = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'order' => 3,
        'path' => $carpeta.'/correcta.jpg',
        'thumbnail_path' => $carpeta.'/thumbs/correcta.webp',
    ]);

    $sinOriginal = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'order' => 4,
        'path' => $carpeta.'/sin-original.jpg',
        'thumbnail_path' => $carpeta.'/thumbs/sin-original.webp',
    ]);

    guardarOriginalDe($sinMiniatura);
    guardarOriginalDe($rota);
    guardarOriginalDe($correcta, 800, 600);

    // La miniatura rota tiene su dirección escrita pero ningún archivo detrás: el
    // disco se vació, o un despliegue se llevó la carpeta por delante.
    Storage::disk('public')->put($correcta->thumbnail_path, 'una miniatura buena');
    // La que no tiene original conserva además su dirección de miniatura.
    Storage::disk('public')->put($sinOriginal->thumbnail_path, 'una miniatura vieja');

    $this->artisan('products:generate-thumbnails')
        ->expectsOutputToContain('Generadas: 1')
        ->expectsOutputToContain('Ya tenían miniatura válida: 1')
        ->expectsOutputToContain('Miniaturas rotas rehechas: 1')
        ->expectsOutputToContain('Original ausente: 1')
        ->expectsOutputToContain('La imagen '.$sinOriginal->getKey().' no tiene original')
        ->assertSuccessful();

    expect($sinMiniatura->refresh()->thumbnail_path)->toBe($carpeta.'/thumbs/sin-miniatura.webp')
        // Una miniatura rota se rehace sin --force: es justo lo que un comando de
        // reparación viene a hacer, y dejarla apuntando al vacío sería tomarla por
        // buena y no volver a mirar nunca.
        ->and($rota->refresh()->thumbnail_path)->toBe($carpeta.'/thumbs/rota.webp')
        ->and(tamanoDe($rota->thumbnail_path)['mime'])->toBe('image/webp')
        // La que está bien se respeta: ni se vuelve a escribir ni se pierde.
        ->and($correcta->refresh()->thumbnail_path)->toBe($carpeta.'/thumbs/correcta.webp')
        ->and(Storage::disk('public')->get($correcta->thumbnail_path))->toBe('una miniatura buena')
        // Sin original no hay nada que rehacer, así que su dirección se queda como
        // estaba y el catálogo sigue enseñando lo que sí hay.
        ->and($sinOriginal->refresh()->thumbnail_path)->toBe($carpeta.'/thumbs/sin-original.webp');

    // Segunda pasada sin cambios: no rehace lo que ya está bien y no inventa nada.
    $this->artisan('products:generate-thumbnails')
        ->expectsOutputToContain('Generadas: 0')
        ->expectsOutputToContain('Ya tenían miniatura válida: 3')
        ->expectsOutputToContain('Miniaturas rotas rehechas: 0')
        ->expectsOutputToContain('Original ausente: 1')
        ->assertSuccessful();

    expect(Storage::disk('public')->get($correcta->thumbnail_path))->toBe('una miniatura buena');
});

test('the copy command redoes the thumbnails that already exist when it is told to force it', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $carpeta = "products/{$producto->getKey()}/{$azul->getKey()}";

    $imagen = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'path' => $carpeta.'/rehacer.jpg',
        'thumbnail_path' => $carpeta.'/thumbs/vecchia.webp',
    ]);

    guardarOriginalDe($imagen);
    Storage::disk('public')->put($imagen->thumbnail_path, 'una miniatura vieja');

    $this->artisan('products:generate-thumbnails --force')
        ->expectsOutputToContain('Generadas: 1')
        ->assertSuccessful();

    expect($imagen->refresh()->thumbnail_path)->toBe($carpeta.'/thumbs/rehacer.webp')
        ->and(tamanoDe($imagen->refresh()->thumbnail_path)['mime'])->toBe('image/webp')
        ->and(Storage::disk('public')->get($carpeta.'/thumbs/rehacer.webp'))->not->toBe('una miniatura vieja');
});

test('the copy command says in the log which pictures it could not repair', function () {
    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $imagen = ProductImage::factory()->for($producto)->for($azul, 'color')->create();

    Log::spy();

    $this->artisan('products:generate-thumbnails')
        ->expectsOutputToContain('no tiene original en')
        ->expectsOutputToContain('Original ausente: 1')
        ->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'no tiene original en')
            && str_contains($mensaje, (string) $imagen->getKey()));

    expect($imagen->refresh()->thumbnail_path)->toBeNull();
});

test('the pictures of a product come in the order of the gallery, with the id breaking the ties', function () {
    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $tercera = ProductImage::factory()->for($producto)->for($azul, 'color')->create(['order' => 2]);
    $primera = ProductImage::factory()->for($producto)->for($azul, 'color')->create(['order' => 1]);
    $segunda = ProductImage::factory()->for($producto)->for($azul, 'color')->create(['order' => 1]);

    expect($producto->images()->pluck('id')->all())->toBe([
        $primera->getKey(),
        $segunda->getKey(),
        $tercera->getKey(),
    ]);
});

test('the cover falls on the main picture of the lowest order, and the oldest of those, every time', function () {
    $azul = colorDeMiniaturas();
    $rojo = otroColorDeMiniaturas();
    $producto = productoConMiniaturas($azul, $rojo);

    // El color azul entra después en la base y a pesar de eso va después en la
    // galería: sin orden, la portada dependería de en qué orden se leyeron las filas.
    $azulPrincipal = ProductImage::factory()->for($producto)->for($azul, 'color')->create([
        'order' => 5,
        'is_primary' => true,
    ]);

    $rojoPrincipal = ProductImage::factory()->for($producto)->for($rojo, 'color')->create([
        'order' => 1,
        'is_primary' => true,
    ]);

    $producto->update(['cover_color_id' => null]);

    expect($producto->coverImage->is($rojoPrincipal))->toBeTrue();

    $rojoPrincipal->update(['order' => 5]);

    // Con el mismo orden, la más antigua gana, y no depende de cómo llegue la fila.
    expect($producto->refresh()->coverImage->is($azulPrincipal))->toBeTrue();

    unset($producto->images);
    expect($producto->coverImage->is($azulPrincipal))->toBeTrue();
});

test('a picture taken with the phone in vertical is turned the right way up in the thumbnail', function () {
    if (! extension_loaded('exif')) {
        $this->markTestSkipped('La extensión exif de PHP no está cargada: sin ella GD no lee la orientación EXIF y la foto saldría tumbada.');
    }

    Storage::fake('public');

    $producto = productoConMiniaturas(colorDeMiniaturas());
    $azul = $producto->colors()->first();

    $vertical = UploadedFile::fake()->createWithContent('movil.jpg', jpegConOrientacion(6, 1000, 600));

    $imagen = (new UploadProductImages)($producto, $azul, [$vertical])->first();

    // Orientación 6 es «girar 90° en sentido horario»: una foto de 1000x600 se
    // guarda girada, como 600x1000, y de ahí sale la miniatura de 288x480. Sin
    // leer la orientación saldría 480x288, que es la foto tumbada.
    expect(tamanoDe($imagen->thumbnail_path))->toMatchArray(['width' => 288, 'height' => 480]);
});
