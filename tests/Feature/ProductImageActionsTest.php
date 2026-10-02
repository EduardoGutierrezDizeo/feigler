<?php

use App\Actions\Products\DeleteProductImage;
use App\Actions\Products\SetPrimaryProductImage;
use App\Actions\Products\SetProductCoverColor;
use App\Actions\Products\UploadProductImages;
use App\Exceptions\InvalidProductImageException;
use App\Exceptions\ProductCoverColorWithoutImagesException;
use App\Exceptions\ProductImageColorNotInProductException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A product sold in the given colors, one variant of each.
 *
 * The names and codes are unique in the store, so the tests that need the same
 * blue twice ask this for it instead of creating two.
 */
function productSoldIn(Color ...$colors): Product
{
    $product = Product::factory()->for(Category::factory())->create();

    foreach ($colors as $color) {
        ProductVariant::factory()->for($product)->create([
            'size' => 'M',
            'color_id' => $color->getKey(),
        ]);
    }

    return $product;
}

function azulDelCatalogo(): Color
{
    return Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
}

function rojoDelCatalogo(): Color
{
    return Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#332211']);
}

function verdeDelCatalogo(): Color
{
    return Color::factory()->create(['name' => 'Verde', 'code' => 'VER', 'hex' => '#113322']);
}

/**
 * A picture as the browser sends it, a few pixels wide so the test stays fast.
 */
function picture(string $name = 'camisa.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 20, 20);
}

test('a picture is stored in the color the product is sold in', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $images = (new UploadProductImages)($product, $azul, [picture()]);

    expect($images)->toHaveCount(1)
        ->and($product->images()->count())->toBe(1)
        ->and($images->first()->color_id)->toBe($azul->id)
        ->and($images->first()->path)->toStartWith("products/{$product->id}/{$azul->id}/");

    Storage::disk('public')->assertExists($images->first()->path);
});

test('a color the product is not sold in refuses the pictures', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $verde = verdeDelCatalogo();

    expect(fn () => (new UploadProductImages)($product, $verde, [picture()]))
        ->toThrow(ProductImageColorNotInProductException::class);

    expect($product->images()->count())->toBe(0);

    Storage::disk('public')->assertDirectoryEmpty('products');
});

test('a file that is not a picture is refused', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    expect(fn () => (new UploadProductImages)($product, $azul, [
        UploadedFile::fake()->create('ficha.pdf', 10),
    ]))->toThrow(InvalidProductImageException::class, 'no tiene un formato admitido');

    expect($product->images()->count())->toBe(0);
});

test('every admitted format is stored', function (string $extension) {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $images = (new UploadProductImages)($product, $azul, [picture("camisa.{$extension}")]);

    expect($images)->toHaveCount(1)
        ->and($images->first()->is_primary)->toBeTrue();
})->with(['jpg', 'jpeg', 'png', 'webp']);

test('the first picture of a color is its main one and the next ones are not', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $images = (new UploadProductImages)($product, $azul, [picture('una.jpg'), picture('dos.jpg')]);

    expect($images->first()->is_primary)->toBeTrue()
        ->and($images->last()->is_primary)->toBeFalse()
        ->and($product->imagesForColor($azul)->where('is_primary', true)->count())->toBe(1);
});

test('a color that already has pictures keeps its main one', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $primera = (new UploadProductImages)($product, $azul, [picture('una.jpg')])->first();

    $mas = (new UploadProductImages)($product, $azul, [picture('dos.jpg'), picture('tres.jpg')]);

    expect($primera->refresh()->is_primary)->toBeTrue()
        ->and($mas->first()->is_primary)->toBeFalse()
        ->and($mas->last()->is_primary)->toBeFalse();
});

/**
 * A gallery is read in the order the pictures arrived in.
 *
 * The column exists so the order survives, but nothing reorders the gallery by
 * hand, so the order is exactly the upload order: the first picture of a color
 * is the one shown first and the last one uploaded is the last seen.
 */
test('a gallery keeps the order the pictures were uploaded in', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo(), rojoDelCatalogo());
    $azul = $product->colors()->firstWhere('name', 'Azul');
    $rojo = $product->colors()->firstWhere('name', 'Rojo');

    $lote = (new UploadProductImages)($product, $azul, [
        picture('una.jpg'),
        picture('dos.jpg'),
        picture('tres.jpg'),
    ]);

    $despues = (new UploadProductImages)($product, $azul, [picture('cuatro.jpg')]);

    // Cada color lleva su propia cuenta: el rojo no sigue al azul.
    $otro = (new UploadProductImages)($product, $rojo, [picture('roja.jpg')]);

    expect($lote->pluck('order')->all())->toBe([1, 2, 3])
        ->and($despues->first()->order)->toBe(4)
        ->and($otro->first()->order)->toBe(1)
        ->and($product->imagesForColor($azul)->pluck('id')->all())
        ->toBe($lote->pluck('id')->merge($despues->pluck('id'))->all())
        ->and($product->refresh()->coverColor->is($azul))->toBeTrue();
});

/**
 * Ties are broken by id, the oldest first, so two pictures that share an order
 * never swap places between two reads of the same gallery.
 */
test('pictures that share an order are read by id, the oldest first', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $primera = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 5]);
    $segunda = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 5]);
    $tercera = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 9]);

    expect($product->imagesForColor($azul)->pluck('id')->all())
        ->toBe([$primera->getKey(), $segunda->getKey(), $tercera->getKey()])
        // La galería de una variante lee lo mismo que el color del producto.
        ->and($product->variants()->sole()->gallery->pluck('id')->all())
        ->toBe([$primera->getKey(), $segunda->getKey(), $tercera->getKey()]);
});

/**
 * The picture that takes over as main when the main one is deleted is the first of
 * the gallery, and the first of the gallery is the one with the lowest order, which
 * is not always the oldest one.
 */
test('deleting the main picture promotes the first one by order, not by id', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $primera = (new UploadProductImages)($product, $azul, [picture('una.jpg')])->first();
    $segunda = (new UploadProductImages)($product, $azul, [picture('dos.jpg')])->first();

    // Una imagen posterior con un order menor: la galería la muestra antes.
    $anterior = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 0]);

    (new DeleteProductImage)($product, $primera->getKey());

    expect($primera->fresh())->toBeNull()
        ->and($anterior->refresh()->is_primary)->toBeTrue()
        ->and($segunda->refresh()->is_primary)->toBeFalse();
});

test('the first color with pictures becomes the cover of the product', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $rojo = rojoDelCatalogo();
    $product = productSoldIn($azul, $rojo);

    expect($product->cover_color_id)->toBeNull();

    (new UploadProductImages)($product, $azul, [picture()]);

    expect($product->refresh()->cover_color_id)->toBe($azul->id)
        ->and($product->coverColor->is($azul))->toBeTrue();

    (new UploadProductImages)($product, $rojo, [picture()]);

    expect($product->refresh()->cover_color_id)->toBe($azul->id);
});

test('a picture heavier than the limit is refused', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $pesada = UploadedFile::fake()->create('camisa.jpg', UploadProductImages::MAX_SIZE_KB + 1);

    expect(fn () => (new UploadProductImages)($product, $azul, [$pesada]))
        ->toThrow(InvalidProductImageException::class, 'pesa más de');

    expect($product->images()->count())->toBe(0);
});

test('choosing a main picture leaves that color with exactly one', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo(), rojoDelCatalogo());
    $azul = $product->colors()->firstWhere('name', 'Azul');
    $rojo = $product->colors()->firstWhere('name', 'Rojo');

    $azules = (new UploadProductImages)($product, $azul, [picture('azul-1.jpg'), picture('azul-2.jpg')]);
    $rojos = (new UploadProductImages)($product, $rojo, [picture('rojo-1.jpg')]);

    $nueva = (new SetPrimaryProductImage)($product, $azules->last()->getKey());

    expect($nueva->is_primary)->toBeTrue()
        ->and($azules->first()->refresh()->is_primary)->toBeFalse()
        ->and($product->imagesForColor($azul)->where('is_primary', true)->count())->toBe(1)
        // The main picture of the other color is a different question and stays put.
        ->and($rojos->first()->refresh()->is_primary)->toBeTrue();
});

test('deleting the main picture promotes the oldest one left', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $primera = (new UploadProductImages)($product, $azul, [picture('una.jpg')])->first();
    $segunda = (new UploadProductImages)($product, $azul, [picture('dos.jpg')])->first();
    $tercera = (new UploadProductImages)($product, $azul, [picture('tres.jpg')])->first();

    (new DeleteProductImage)($product, $primera->getKey());

    expect($primera->fresh())->toBeNull()
        ->and($segunda->refresh()->is_primary)->toBeTrue()
        ->and($tercera->refresh()->is_primary)->toBeFalse();
});

test('deleting the last picture of the cover color leaves the product without a cover', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $product = productSoldIn($azul);

    $unica = (new UploadProductImages)($product, $azul, [picture()])->first();

    (new DeleteProductImage)($product, $unica->getKey());

    expect($product->refresh()->cover_color_id)->toBeNull()
        ->and($product->coverImage)->toBeNull()
        ->and($product->images()->count())->toBe(0);
});

test('deleting the last picture of a color that is not the cover leaves the cover alone', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $rojo = rojoDelCatalogo();
    $product = productSoldIn($azul, $rojo);

    (new UploadProductImages)($product, $azul, [picture()]);
    $roja = (new UploadProductImages)($product, $rojo, [picture()])->first();

    (new DeleteProductImage)($product, $roja->getKey());

    expect($product->refresh()->cover_color_id)->toBe($azul->id);
});

test('deleting a picture erases its file and leaves the others on disk', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo());
    $azul = $product->colors()->first();

    $primera = (new UploadProductImages)($product, $azul, [picture('una.jpg')])->first();
    $segunda = (new UploadProductImages)($product, $azul, [picture('dos.jpg')])->first();

    (new DeleteProductImage)($product, $primera->getKey());

    Storage::disk('public')->assertMissing($primera->path);
    Storage::disk('public')->assertExists($segunda->path);
});

test('the cover of a product is the main picture of its cover color', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $rojo = rojoDelCatalogo();
    $product = productSoldIn($azul, $rojo);

    $azules = (new UploadProductImages)($product, $azul, [picture('azul-1.jpg'), picture('azul-2.jpg')]);
    $rojos = (new UploadProductImages)($product, $rojo, [picture('rojo-1.jpg')]);

    $azulPrincipal = (new SetPrimaryProductImage)($product, $azules->last()->getKey());

    $product->load('images');

    expect($product->coverImage->is($azulPrincipal))->toBeTrue()
        ->and($azulPrincipal->url)->toBe(Storage::disk('public')->url($azulPrincipal->path))
        ->and($rojos->first()->is_primary)->toBeTrue();
});

test('the cover of a product falls back to the main picture of any color', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $rojo = rojoDelCatalogo();
    $product = productSoldIn($azul, $rojo);

    $rojos = (new UploadProductImages)($product, $rojo, [picture('rojo-1.jpg')]);

    expect($product->refresh()->cover_color_id)->toBe($rojo->id)
        ->and($product->coverImage->is($rojos->first()))->toBeTrue();

    (new SetProductCoverColor)($product, null);

    expect($product->refresh()->cover_color_id)->toBeNull()
        ->and($product->coverImage->is($rojos->first()))->toBeTrue();
});

test('the cover of a product reads the loaded images instead of querying them', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $product = productSoldIn($azul);
    $otra = productSoldIn($azul);

    (new UploadProductImages)($product, $azul, [picture()]);

    $cargado = Product::query()->with('images')->findOrFail($product->id);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $portada = $cargado->cover_image;

    $consultas = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    expect($portada->is($product->images()->first()))->toBeTrue()
        ->and($consultas)->toBe(0)
        ->and($otra->coverImage)->toBeNull();
});

test('a product whose color has no main picture has no cover', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $product = productSoldIn($azul);

    ProductImage::factory()->for($product)->for($azul, 'color')->create(['path' => 'products/vieja.jpg']);

    expect($product->coverImage)->toBeNull();
});

test('a product without pictures has no cover', function () {
    expect(productSoldIn(azulDelCatalogo())->coverImage)->toBeNull();
});

test('a color without pictures cannot become the cover of a product', function () {
    Storage::fake('public');

    $product = productSoldIn(azulDelCatalogo(), rojoDelCatalogo());
    $azul = $product->colors()->firstWhere('name', 'Azul');
    $rojo = $product->colors()->firstWhere('name', 'Rojo');

    // Only blue has pictures, and blue is therefore the cover by now.
    (new UploadProductImages)($product, $azul, [picture()]);

    expect(fn () => (new SetProductCoverColor)($product, $rojo))
        ->toThrow(ProductCoverColorWithoutImagesException::class);

    expect($product->refresh()->cover_color_id)->toBe($azul->id)
        ->and((new SetProductCoverColor)($product, $azul)->cover_color_id)->toBe($azul->id);

    $rojos = (new UploadProductImages)($product, $rojo, [picture()]);

    expect((new SetProductCoverColor)($product, $rojo)->cover_color_id)->toBe($rojo->id)
        ->and($product->refresh()->coverImage->is($rojos->first()))->toBeTrue();
});

test('deleting a product erases the files of its pictures', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $product = productSoldIn($azul);

    $primera = (new UploadProductImages)($product, $azul, [picture('una.jpg')])->first();
    $segunda = (new UploadProductImages)($product, $azul, [picture('dos.jpg')])->first();

    $product->delete();

    Storage::disk('public')->assertMissing($primera->path);
    Storage::disk('public')->assertMissing($segunda->path);

    expect(ProductImage::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('no action can touch a picture of another product', function () {
    Storage::fake('public');

    $azul = azulDelCatalogo();
    $propio = productSoldIn($azul);
    $ajeno = productSoldIn($azul);

    $ajena = (new UploadProductImages)($ajeno, $azul, [picture()])->first();

    expect(fn () => (new DeleteProductImage)($propio, $ajena->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new SetPrimaryProductImage)($propio, $ajena->getKey()))->toThrow(ModelNotFoundException::class);

    expect($ajena->refresh()->is_primary)->toBeTrue()
        ->and(ProductImage::query()->count())->toBe(1);
});
