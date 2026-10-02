<?php

use App\Actions\Products\UploadProductImages;
use App\Livewire\Admin\Products\Images;
use App\Livewire\Admin\Products\Index;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A product of the listing section, with one variant in each given color.
 */
function productoDelListado(Color ...$colores): Product
{
    $producto = Product::factory()->for(numberedCategory())->create();

    foreach ($colores as $color) {
        ProductVariant::factory()->for($producto)->create([
            'size' => 'M',
            'color_id' => $color->getKey(),
        ]);
    }

    return $producto;
}

function fotoDePortada(Product $producto, Color $color, string $nombre, bool $principal = false): ProductImage
{
    return ProductImage::factory()
        ->for($producto)
        ->for($color, 'color')
        ->create([
            'path' => "productos/{$nombre}",
            'is_primary' => $principal,
        ]);
}

/**
 * The tag of the cover of the first row, which is either the picture or the empty
 * slot. Reading it as a chunk of HTML keeps the assertions off the names, which
 * the open modal repeats all over the page.
 */
function portadaEnElListado(mixed $listado): string
{
    $html = $listado->html();

    $inicio = strpos($html, 'wire:key="portada-');

    expect($inicio)->not->toBeFalse();

    // Se lee desde un poco antes de la clave para que entre también la etiqueta
    // que abre el recuadro, que es lo que decide si hay foto o hueco.
    return substr($html, max(0, $inicio - 200), 1200);
}

function listado(): mixed
{
    return Livewire::actingAs(adminForPanel())->test(Index::class);
}

test('the row of a product shows the main picture of its cover color', function () {
    $this->seed(RoleSeeder::class);

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#332211']);

    $producto = productoDelListado($azul, $rojo);
    $producto->update(['cover_color_id' => $azul->getKey()]);

    $portada = fotoDePortada($producto, $azul, 'azul-1.jpg', principal: true);
    $otra = fotoDePortada($producto, $rojo, 'rojo-1.jpg', principal: true);

    listado()
        ->assertSeeHtml('src="'.$portada->url.'"')
        // La principal del otro color existe, pero el color elegido manda.
        ->assertDontSeeHtml('src="'.$otra->url.'"');
});

test('the row falls back to the main picture of any color when no cover color is chosen', function () {
    $this->seed(RoleSeeder::class);

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#332211']);

    $producto = productoDelListado($azul, $rojo);

    expect($producto->cover_color_id)->toBeNull();

    $portada = fotoDePortada($producto, $rojo, 'rojo-1.jpg', principal: true);

    listado()->assertSeeHtml('src="'.$portada->url.'"');
});

test('a product without pictures shows the empty slot instead of a broken image', function () {
    $this->seed(RoleSeeder::class);

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);

    productoDelListado($azul);

    listado()
        ->assertSeeHtml('from-hueso to-arena')
        // El icono del hueco va dentro del recuadro, así que tampoco hay <img>.
        ->assertDontSeeHtml('<img');
});

test('the cover of the row names the product for the screen reader', function () {
    $this->seed(RoleSeeder::class);

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);

    $producto = productoDelListado($azul);
    $producto->update(['name' => 'Camisa oxford', 'cover_color_id' => $azul->getKey()]);

    $portada = fotoDePortada($producto, $azul, 'azul-1.jpg', principal: true);

    // Blade deja un salto de línea entre atributos, así que se comprueba la
    // etiqueta por partes en vez de como una sola cadena.
    expect(portadaEnElListado(listado()))
        ->toContain('<img')
        ->toContain('wire:key="portada-'.$producto->getKey().'"')
        ->toContain('src="'.$portada->url.'"')
        ->toContain('alt="Camisa oxford"')
        ->toContain('loading="lazy"');
});

test('the row of a product paints the small copy of the cover, and the original when there is none', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);

    $producto = productoDelListado($azul);
    $producto->update(['cover_color_id' => $azul->getKey()]);

    // Una fila subida por el panel llega con su miniatura hecha; una creada a mano,
    // como las de más arriba, no la tiene y se ve el original.
    $conMiniatura = (new UploadProductImages)(
        $producto,
        $azul,
        [UploadedFile::fake()->image('azul-1.jpg', 1000, 600)],
    )->first();

    expect($conMiniatura->thumbnail_path)->toEndWith('.webp');

    listado()
        ->assertSeeHtml('src="'.$conMiniatura->thumbnailUrl().'"')
        ->assertDontSeeHtml('src="'.$conMiniatura->url.'"');

    $sinMiniatura = fotoDePortada($producto, $azul, 'azul-2.jpg', principal: true);
    $producto->update(['cover_color_id' => $azul->getKey()]);

    $imagenes = $producto->images()->get();

    expect($imagenes->firstWhere('is_primary', true)->getKey())->toBe($sinMiniatura->getKey());

    listado()->assertSeeHtml('src="'.$sinMiniatura->url.'"');
});

test('the listing waits for the modal to announce a change of pictures', function () {
    $this->seed(RoleSeeder::class);

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);

    $producto = productoDelListado($azul);

    // El listener vive en el panel de imágenes, que solo se monta con un producto
    // abierto: por eso la fila de la lista se repinta, y no el formulario entero.
    $listado = listado()->call('edit', $producto);

    expect($listado->html())->toContain('product-images-changed');
});

test('a change of pictures in the modal is already in the listing the next time it is drawn', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $azul = Color::factory()->create(['name' => 'Azul', 'code' => 'AZU', 'hex' => '#112233']);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'code' => 'ROJ', 'hex' => '#332211']);

    $producto = productoDelListado($azul, $rojo);
    $producto->update(['cover_color_id' => $azul->getKey()]);

    // Antes de subir nada, la fila enseña el hueco del producto sin fotos.
    expect(portadaEnElListado(listado()))->toContain('from-hueso to-arena');

    $panel = Livewire::actingAs(adminForPanel())
        ->test(Images::class, ['productId' => $producto->getKey()]);

    $panel->set('uploads.'.$azul->getKey(), [UploadedFile::fake()->image('azul-1.jpg', 20, 20)])
        ->call('uploadImages', $azul->getKey())
        ->assertDispatched(Images::CHANGED_EVENT);

    // El evento es lo que refresca el listado en el navegador; lo que se comprueba
    // aquí es que la fila ya lee la portada nueva cuando vuelve a dibujarse.
    $carpetaAzul = 'src="/storage/products/'.$producto->getKey().'/'.$azul->getKey().'/';

    expect(portadaEnElListado(listado()))
        ->toContain($carpetaAzul)
        ->not->toContain('from-hueso to-arena');

    // Con foto en los dos colores, cambiar el color de portada cambia la fila.
    $panel->set('uploads.'.$rojo->getKey(), [UploadedFile::fake()->image('rojo-1.jpg', 20, 20)])
        ->call('uploadImages', $rojo->getKey())
        ->call('setCover', $rojo->getKey());

    expect(portadaEnElListado(listado()))
        ->toContain('src="/storage/products/'.$producto->getKey().'/'.$rojo->getKey().'/')
        ->not->toContain($carpetaAzul);
});
