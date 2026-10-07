<?php

use App\Actions\Products\CreateProductVariant;
use App\Actions\Products\UploadProductImages;
use App\Livewire\Admin\Products\Images;
use App\Livewire\Admin\Products\Index;
use App\Livewire\Admin\Products\Variants;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A product sold in the given number of colors, one variant of each.
 *
 * The store counts: the prefix of the category and the name and code of the
 * colors are unique, so a test that asks for two products, or for six colors and
 * then for six more, needs values that are not the ones it already used.
 */
function productoVendidoEn(int $colores = 2): Product
{
    static $productos = 0;

    $productos++;

    $producto = Product::factory()
        ->for(numberedCategory('P'.$productos, 'Polos '.$productos))
        ->create();

    foreach (range(1, $colores) as $numero) {
        static $creados = 0;

        $creados++;

        $color = Color::factory()->create([
            'name' => 'Color '.$creados,
            'code' => 'C'.$creados,
        ]);

        ProductVariant::factory()->for($producto)->create([
            'color_id' => $color->getKey(),
        ]);
    }

    return $producto;
}

/**
 * A picture as the browser sends it, a few pixels wide so the test stays fast.
 */
function foto(string $nombre = 'camisa.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($nombre, 20, 20);
}

function panelDeImagenes(Product $producto, ?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())
        ->test(Images::class, ['productId' => $producto->getKey()]);
}

test('the images tab stays closed until the product is saved', function () {
    $this->seed(RoleSeeder::class);

    $producto = productoVendidoEn();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->assertSeeHtml('aria-controls="product-panel-imagenes"')
        ->assertSee('Guarda el producto primero', false)
        ->assertDontSeeHtml('wire:key="images-')
        ->assertDontSeeHtml('wire:model="uploads.');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto)
        ->assertDontSee('Guarda el producto primero', false)
        ->assertSeeHtml('wire:key="images-'.$producto->getKey().'"')
        ->assertSeeHtml('wire:model="uploads.'.$producto->colors()->first()->getKey().'"');
});

test('el botón «Subir» llama a un método que no choca con el gestor de archivos de $wire', function () {
    $this->seed(RoleSeeder::class);

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    // `upload` es un alias de `$wire` en el JavaScript de Livewire (ver el
    // archivo LivewireReservedNamesTest): el botón tiene que decir `uploadImages`
    // o el clic se queda en el `UploadManager` y el método de PHP no corre.
    panelDeImagenes($producto)
        ->assertSeeHtml('wire:click="uploadImages('.$color->getKey().')"')
        ->assertSeeHtml('wire:target="uploadImages('.$color->getKey().')"')
        ->assertDontSeeHtml('wire:click="upload(');
});

test('el método de subida se llama de verdad cuando se pide desde el servidor', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [foto()])
        ->call('uploadImages', $color->getKey())
        ->assertHasNoErrors();

    expect($producto->images()->count())->toBe(1);
});

test('the tab does not mount for a product that does not exist', function () {
    $this->seed(RoleSeeder::class);

    Livewire::actingAs(adminForPanel())
        ->test(Images::class, ['productId' => 9999])
        ->assertNotFound();
});

/**
 * The route asks for the admin role and so does the tab.
 *
 * `/livewire/update` does not pass through the middleware of the page it was
 * rendered in, so the guard has to live inside the component; without it a
 * `vendedor` that never saw the page could still open a snapshot and call the
 * methods by hand.
 */
test('a user without the admin role cannot mount the tab', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $producto = productoVendidoEn();

    Livewire::actingAs($vendedor)
        ->test(Images::class, ['productId' => $producto->getKey()])
        ->assertForbidden();
});

test('even a guest cannot mount the tab', function () {
    $this->seed(RoleSeeder::class);

    $producto = productoVendidoEn();

    Livewire::test(Images::class, ['productId' => $producto->getKey()])
        ->assertForbidden();
});

/**
 * The role of an open tab is asked again on every request, not only on the mount.
 *
 * This is the request that carries the snapshot, so it is where a method call
 * arrives: the tab was loaded by an administrator who since lost the role. Every
 * method is checked on its own because a 403 leaves no snapshot behind to chain
 * the next call onto.
 */
test('an admin who loses the role can no longer act on the tab', function (string $metodo, bool $conId) {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $admin = adminForPanel();
    $producto = productoVendidoEn();

    $color = $producto->colors()->first();
    $panel = panelDeImagenes($producto, $admin);

    $panel->set('uploads.'.$color->id, [foto()])->call('uploadImages', $color->getKey());

    $imagen = $producto->images()->sole();

    // El rol se va con la página ya abierta, que es justo lo que un snapshot
    // guardado en el navegador permite hacer.
    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call($metodo, ...($conId ? [$imagen->getKey()] : []))->assertForbidden();

    expect($producto->images()->count())->toBe(1)
        ->and($imagen->is_primary)->toBeTrue()
        ->and($producto->refresh()->cover_color_id)->toBe($color->getKey());
})->with([
    'uploadImages' => ['uploadImages', false],
    'makePrimary' => ['makePrimary', true],
    'delete' => ['delete', true],
    'setCover' => ['setCover', true],
    'updatedCoverColorId' => ['updatedCoverColorId', true],
    'openVariantsTab' => ['openVariantsTab', false],
]);

test('uploads several pictures of a color in one go', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [foto('una.jpg'), foto('dos.jpg'), foto('tres.jpg')])
        ->call('uploadImages', $color->id)
        ->assertHasNoErrors()
        ->assertSet('uploads', [])
        ->assertSet('notice', '3 imágenes subidas correctamente.')
        ->assertDispatched(Images::CHANGED_EVENT);

    $imagenes = $producto->imagesForColor($color)->get();

    expect($imagenes)->toHaveCount(3)
        ->and($imagenes->pluck('order')->all())->toBe([1, 2, 3])
        // La primera imagen de un color es su principal, y el producto nace con
        // portada.
        ->and($imagenes->first()->is_primary)->toBeTrue()
        ->and($imagenes->where('is_primary', true))->toHaveCount(1)
        ->and($producto->refresh()->cover_color_id)->toBe($color->getKey());

    foreach ($imagenes as $imagen) {
        Storage::disk('public')->assertExists($imagen->path);
    }
});

test('the gallery paints the small copy of every picture and not the original', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [foto('una.jpg')])
        ->call('uploadImages', $color->id);

    $imagen = $producto->images()->sole();

    expect($imagen->thumbnail_path)->toEndWith('.webp');

    panelDeImagenes($producto)
        ->assertSeeHtml('src="'.$imagen->thumbnailUrl().'"')
        ->assertDontSeeHtml('src="'.$imagen->url.'"');
});

test('a picture left without a thumbnail is painted with the original it does have', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [foto('una.jpg')])
        ->call('uploadImages', $color->id);

    $imagen = $producto->images()->sole();
    $imagen->update(['thumbnail_path' => null]);

    panelDeImagenes($producto)
        ->assertSeeHtml('src="'.$imagen->url.'"');
});

test('the pictures of a color show up in the panel in the order they were uploaded', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);

    $panel->set('uploads.'.$color->id, [foto('una.jpg')])->call('uploadImages', $color->id);
    $primera = $producto->images()->sole();

    $panel->set('uploads.'.$color->id, [foto('dos.jpg')])->call('uploadImages', $color->id);

    $segunda = $producto->images()->get()->last();

    $panel
        ->assertSeeHtmlInOrder([$primera->thumbnailUrl(), $segunda->thumbnailUrl()])
        ->assertSeeHtml('wire:key="imagen-'.$primera->getKey().'"')
        ->assertSeeHtml('wire:key="imagen-'.$segunda->getKey().'"');
});

test('uploading without picking anything says so in Spanish', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->call('uploadImages', $color->id)
        ->assertHasErrors(['uploads.'.$color->id => 'required'])
        ->assertSee('Selecciona al menos una imagen.');

    expect($producto->images()->count())->toBe(0);
});

test('a picture heavier than 4 MB is refused with a message that names it', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $pesada = UploadedFile::fake()->create('camisa.jpg', UploadProductImages::MAX_SIZE_KB + 1);

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [$pesada])
        ->call('uploadImages', $color->id)
        ->assertHasErrors(['uploads.'.$color->id])
        ->assertSee('La imagen «camisa.jpg» pesa más de 4096 KB.', false);

    expect($producto->images()->count())->toBe(0);
});

test('a file that is not a picture is refused with a message that names it', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->set('uploads.'.$color->id, [UploadedFile::fake()->create('ficha.pdf', 10)])
        ->call('uploadImages', $color->id)
        ->assertHasErrors(['uploads.'.$color->id])
        ->assertSee('La imagen «ficha.pdf» no tiene un formato admitido; los formatos permitidos son: jpg, jpeg, png, webp.', false);

    expect($producto->images()->count())->toBe(0);
});

test('pictures of a color the product is not sold in are refused', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $ajeno = Color::factory()->create(['name' => 'Verde', 'code' => 'VDE']);

    panelDeImagenes($producto)
        ->set('uploads.'.$ajeno->id, [foto()])
        ->call('uploadImages', $ajeno->id)
        ->assertHasErrors(['uploads.'.$ajeno->id])
        ->assertSet('notice', 'El color «Verde» no está entre los colores del producto «'.$producto->name.'»: '.$producto->colors()->pluck('name')->join(', ').'.');

    expect($producto->images()->count())->toBe(0);
});

test('marks a picture as the main one of its color', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto('una.jpg'), foto('dos.jpg')])->call('uploadImages', $color->id);

    $segunda = $producto->images()->get()->last();

    $panel
        ->call('makePrimary', $segunda->getKey())
        ->assertSet('notice', 'Imagen principal actualizada correctamente.')
        ->assertDispatched(Images::CHANGED_EVENT);

    expect($segunda->refresh()->is_primary)->toBeTrue()
        ->and($producto->imagesForColor($color)->where('is_primary', true)->count())->toBe(1);
});

/**
 * The tag of the star button of one picture, taken from the HTML of the panel.
 *
 * The button is looked up by its own `wire:click` and read up to the next star, so
 * that `aria-pressed` is checked on the picture it belongs to and not on the panel.
 */
function botonDeLaEstrella(string $html, int $imagenId): string
{
    $marca = 'wire:click="makePrimary('.$imagenId.')"';
    $inicio = strpos($html, $marca);

    expect($inicio)->not->toBeFalse();

    $siguiente = strpos($html, 'wire:click="makePrimary(', $inicio + strlen($marca));

    return substr($html, $inicio, ($siguiente === false ? strlen($html) : $siguiente) - $inicio);
}

test('exactly one star of the gallery is pressed, and it moves to the picture the admin picks', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto('una.jpg'), foto('dos.jpg'), foto('tres.jpg')])
        ->call('uploadImages', $color->id);

    $imagenes = $producto->imagesForColor($color)->get();
    $principal = $imagenes->firstWhere('is_primary', true);
    $otra = $imagenes->firstWhere('is_primary', false);

    expect($imagenes)->toHaveCount(3);

    $html = $panel->html();

    // Una sola estrella pulsada, y las otras dos sueltas.
    expect(substr_count($html, 'aria-pressed="true"'))->toBe(1)
        ->and(substr_count($html, 'aria-pressed="false"'))->toBe(2)
        ->and(botonDeLaEstrella($html, $principal->getKey()))->toContain('aria-pressed="true"')
        ->and(botonDeLaEstrella($html, $otra->getKey()))->toContain('aria-pressed="false"');

    // La estrella de la principal lleva el estado seleccionado en disco crema y
    // estrella verde rellena; la otra, disco de tinta y solo el contorno.
    expect(botonDeLaEstrella($html, $principal->getKey()))->toContain('bg-crema text-verde')
        ->and(botonDeLaEstrella($html, $otra->getKey()))->toContain('bg-tinta/60 text-white');

    $panel->call('makePrimary', $otra->getKey());

    $html = $panel->html();

    expect(substr_count($html, 'aria-pressed="true"'))->toBe(1)
        ->and(botonDeLaEstrella($html, $otra->getKey()))->toContain('aria-pressed="true"')
        ->and(botonDeLaEstrella($html, $principal->getKey()))->toContain('aria-pressed="false"')
        ->and(botonDeLaEstrella($html, $otra->getKey()))->toContain('aria-label="Imagen principal de '.$color->name.'"');
});

test('deletes a picture and its file', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto('una.jpg'), foto('dos.jpg')])->call('uploadImages', $color->id);

    $segunda = $producto->images()->get()->last();

    $panel
        ->call('delete', $segunda->getKey())
        ->assertSet('notice', 'Imagen eliminada correctamente.')
        ->assertDispatched(Images::CHANGED_EVENT)
        ->assertDontSee($segunda->url, false);

    expect($producto->images()->count())->toBe(1);

    Storage::disk('public')->assertMissing($segunda->path);
});

test('deleting the main picture hands the place to the next one', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto('una.jpg'), foto('dos.jpg')])->call('uploadImages', $color->id);

    $primera = $producto->images()->first();
    $segunda = $producto->images()->get()->last();

    $panel->call('delete', $primera->getKey());

    expect($segunda->refresh()->is_primary)->toBeTrue();
});

test('chooses the color that stands for the product in the catalog', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn(2);
    $colores = $producto->colors();

    $panel = panelDeImagenes($producto);

    foreach ($colores as $color) {
        $panel->set('uploads.'.$color->getKey(), [foto()])->call('uploadImages', $color->getKey());
    }

    $otro = $colores->last();

    $panel
        ->set('coverColorId', $otro->getKey())
        ->assertHasNoErrors()
        ->assertDispatched(Images::CHANGED_EVENT);

    expect($producto->refresh()->cover_color_id)->toBe($otro->getKey())
        ->and($producto->coverColor->is($otro))->toBeTrue();
});

test('the cover can be taken away', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto()])->call('uploadImages', $color->id);

    expect($producto->refresh()->cover_color_id)->toBe($color->getKey());

    $panel
        ->call('setCover', null)
        ->assertHasNoErrors()
        ->assertDispatched(Images::CHANGED_EVENT);

    expect($producto->refresh()->cover_color_id)->toBeNull();
});

test('a color with no pictures cannot be the cover of the product', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn(2);
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto()])->call('uploadImages', $color->id);

    $sinFotos = $producto->colors()->last();

    $panel
        ->call('setCover', $sinFotos->getKey())
        ->assertHasErrors(['coverColorId'])
        ->assertSet('coverColorId', $color->getKey())
        ->assertSet('notice', 'El color «'.$sinFotos->name.'» no puede ser la portada del producto «'.$producto->name.'» porque todavía no tiene ninguna imagen.');

    expect($producto->refresh()->cover_color_id)->toBe($color->getKey());
});

test('the cover selector only offers the colors that have pictures', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn(2);
    $colores = $producto->colors();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$colores->first()->getKey(), [foto()])->call('uploadImages', $colores->first()->getKey());

    $panel
        ->assertSee('— Sin portada —', false)
        ->assertSeeHtml('<option value="'.$colores->first()->getKey().'"')
        ->assertDontSeeHtml('<option value="'.$colores->last()->getKey().'"');
});

/**
 * The selector is painted with the cover the product has, and the action that
 * uploads the first picture of a color gives the product that cover without
 * anyone choosing it: a selector still reading «Sin portada» would be a picture
 * of a state that no longer exists.
 */
test('the selector shows the cover the product was given by the upload', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    panelDeImagenes($producto)
        ->assertSet('coverColorId', null)
        ->set('uploads.'.$color->id, [foto()])
        ->call('uploadImages', $color->id)
        ->assertSet('coverColorId', $color->getKey())
        ->assertSeeHtml('<option value="'.$color->getKey().'" selected>');
});

test('deleting the last picture of the cover color takes the cover away', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn();
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto()])->call('uploadImages', $color->id);

    $panel
        ->call('delete', $producto->images()->sole()->getKey())
        ->assertSet('coverColorId', null)
        // Sin fotos no hay ningún color que pueda ser la portada, así que el
        // selector se retira en vez de quedar ofreciendo una elección vacía.
        ->assertDontSee('Color de portada')
        ->assertSee('Este color todavía no tiene imágenes.');

    expect($producto->refresh()->cover_color_id)->toBeNull();
});

test('a product without variants says where the pictures come from', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    panelDeImagenes($producto)
        ->assertSee('Este producto todavía no tiene variantes, así que no tiene colores donde colocar imágenes.', false)
        ->call('openVariantsTab')
        ->assertDispatched(Images::VARIANTS_TAB_EVENT);
});

test('every color of the product gets its own area to upload into', function () {
    $this->seed(RoleSeeder::class);

    $producto = productoVendidoEn(3);

    $panel = panelDeImagenes($producto);

    expect($producto->colors()->count())->toBe(3);

    foreach ($producto->colors() as $color) {
        $panel
            ->assertSeeHtml('wire:key="color-imagenes-'.$color->getKey().'"')
            ->assertSeeHtml('wire:model="uploads.'.$color->getKey().'"')
            ->assertSeeHtml('wire:click="uploadImages('.$color->getKey().')"');
    }
});

/**
 * A variant born while the images tab is open must appear in it as a color to
 * upload into without the tab being reopened: the change arrives through the
 * event the variants tab broadcasts, and the tab re-renders on it instead of
 * waiting for the admin to close and reopen the panel.
 */
test('a variant created elsewhere appears in the images tab without reopening it', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    $panel = panelDeImagenes($producto);
    $panel->assertSee('Este producto todavía no tiene variantes, así que no tiene colores donde colocar imágenes.', false);

    $color = Color::factory()->create(['name' => 'Azul']);

    (new CreateProductVariant)($producto, sizeOfProduct($producto, 'M')->getKey(), $color);

    // El despacho que hizo la pestaña de variantes al guardar; el tab de imágenes
    // lo recibe y vuelve a leer las variantes, que es donde aparece el color.
    $panel
        ->dispatch(Variants::CHANGED_EVENT, productId: $producto->getKey())
        ->assertDontSee('Este producto todavía no tiene variantes, así que no tiene colores donde colocar imágenes.', false)
        ->assertSeeHtml('wire:key="color-imagenes-'.$color->getKey().'"')
        ->assertSeeHtml('wire:model="uploads.'.$color->getKey().'"');
});

/**
 * The word the images tab listens for is also announced by the variants tab on
 * every change that could move the colors: the creation is already covered by
 * the listing test, and here it is the color edit and the deletion, because
 * both leave the product sold in a different set of colors.
 */
test('the variants tab announces the change when a color moves or a variant is deleted', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();
    $otro = Color::factory()->create();

    Livewire::actingAs(adminForPanel())
        ->test(Variants::class, ['productId' => $producto->getKey()])
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    Livewire::actingAs(adminForPanel())
        ->test(Variants::class, ['productId' => $producto->getKey()])
        ->call('startEditing', $variante->getKey())
        ->set('colorId', $otro->getKey())
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('product-variants-changed', productId: $producto->getKey());

    Livewire::actingAs(adminForPanel())
        ->test(Variants::class, ['productId' => $producto->getKey()])
        ->call('delete', $variante->fresh()->getKey())
        ->assertDispatched('product-variants-changed', productId: $producto->getKey());
});

/**
 * A color that stops being sold is also a color whose pictures have nowhere to
 * hang: the tab reads the variants again on the announcement and drops the
 * color, taking the cover away when it was the one carrying it.
 */
test('a color that stops being sold disappears from the tab and takes its cover along', function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');

    $producto = productoVendidoEn(1);
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);
    $panel->set('uploads.'.$color->id, [foto()])->call('uploadImages', $color->id);

    $panel->assertSet('coverColorId', $color->getKey());

    $producto->variants()->delete();

    $panel
        ->dispatch(Variants::CHANGED_EVENT, productId: $producto->getKey())
        ->assertSet('coverColorId', null)
        ->assertDontSeeHtml('wire:key="color-imagenes-'.$color->getKey().'"');
});

/**
 * The announcement names the product that moved and the tab only answers its
 * own: a change in the variants of another product must not touch this one.
 */
test('an event announced for another product leaves the tab alone', function () {
    $this->seed(RoleSeeder::class);

    $producto = productoVendidoEn(1);
    $color = $producto->colors()->first();

    $panel = panelDeImagenes($producto);

    $otro = Product::factory()->create();
    $otroColor = Color::factory()->create();

    (new CreateProductVariant)($otro, sizeOfProduct($otro)->getKey(), $otroColor);

    $panel
        ->dispatch(Variants::CHANGED_EVENT, productId: $otro->getKey())
        ->assertDontSeeHtml('wire:key="color-imagenes-'.$otroColor->getKey().'"')
        ->assertSeeHtml('wire:key="color-imagenes-'.$color->getKey().'"');
});
