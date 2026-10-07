<?php

use App\Actions\Products\AdjustProductVariantStock;
use App\Actions\Products\CreateProductVariant;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductVariantNotDeletableException;
use App\Livewire\Admin\Products\Index;
use App\Livewire\Admin\Products\Variants;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

function variantPanel(Product $product, mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())
        ->test(Variants::class, ['productId' => $product->getKey()]);
}

test('the variants tab is closed until the product is saved', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->assertSee('Guarda el producto primero', false)
        ->assertSeeHtml('aria-controls="product-panel-variantes"')
        ->assertDontSee('Aún no hay variantes');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto)
        ->assertDontSee('Guarda el producto primero', false)
        ->assertSee('Aún no hay variantes')
        ->assertSee($producto->reference);
});

test('reopening the modal goes back to the data tab', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto)
        ->assertDispatched('product-modal-open');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->assertDispatched('product-modal-open');
});

test('the tab does not mount for a product that does not exist', function () {
    $this->seed(RoleSeeder::class);

    Livewire::actingAs(adminForPanel())
        ->test(Variants::class, ['productId' => 9999])
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

    $producto = Product::factory()->create();

    Livewire::actingAs($vendedor)
        ->test(Variants::class, ['productId' => $producto->getKey()])
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

    $admin = adminForPanel();
    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    $panel = variantPanel($producto, $admin);

    $panel
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '5')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    // El rol se va con la página ya abierta, que es justo lo que un snapshot
    // guardado en el navegador permite hacer.
    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call($metodo, ...($conId ? [$variante->getKey()] : []))->assertForbidden();

    // Y nada de eso tocó la base: ni una variante más, ni el stock, ni el estado.
    expect($producto->variants()->count())->toBe(1)
        ->and($variante->refresh()->size->name)->toBe('M')
        ->and($variante->stock)->toBe(5)
        ->and($variante->is_active)->toBeTrue()
        ->and($variante->inventoryMovements()->count())->toBe(1)
        ->and($producto->display_status)->toBe('active');
})->with([
    'startCreating' => ['startCreating', false],
    'startCreatingFrom' => ['startCreatingFrom', true],
    'startEditing' => ['startEditing', true],
    'save' => ['save', false],
    'startAdjusting' => ['startAdjusting', true],
    'adjustStock' => ['adjustStock', false],
    'toggle' => ['toggle', true],
    'delete' => ['delete', true],
]);

/**
 * Even the role check on the page does not hand the tab to a guest.
 */
test('a guest cannot mount the tab either', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    Livewire::test(Variants::class, ['productId' => $producto->getKey()])
        ->assertForbidden();
});

test('creates a variant with a built sku and an initial stock movement', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create(['base_price' => 89900]);
    $color = Color::factory()->create(['name' => 'Azul']);

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '12')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('notice', 'Variante creada correctamente.')
        ->assertDispatched('product-variants-changed');

    $variante = $producto->variants()->sole();

    expect($variante->size->name)->toBe('M')
        ->and($variante->color_id)->toBe($color->getKey())
        ->and($variante->price_override)->toBeNull()
        ->and($variante->stock)->toBe(12)
        ->and($variante->is_active)->toBeTrue()
        ->and($variante->sku)->toBe("{$producto->reference}-M-{$color->code}");

    $movimiento = $variante->inventoryMovements()->sole();

    expect($movimiento->type)->toBe(CreateProductVariant::INITIAL_STOCK_TYPE)
        ->and($movimiento->quantity)->toBe(12)
        ->and($movimiento->note)->toBe(CreateProductVariant::INITIAL_STOCK_REASON);
});

/**
 * The store sells every variant at the price of its product, so the tab has no
 * price field at all: neither when the variant is created nor when it is edited.
 *
 * The input is also gone from the markup, not just hidden, because a field the
 * server ignores teaches the user to type a price that is then thrown away.
 */
test('the tab asks for no price and the variant sells at the price of its product', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create(['base_price' => 89900]);
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->assertDontSee('Precio propio')
        ->assertSee('El precio lo hereda la variante del producto: $89.900.')
        ->set('sizeId', sizeOfProduct($producto, 'L')->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Hereda $89.900');

    $variante = $producto->variants()->sole();

    // Tampoco al editar: la talla y el color se cambian sin tocar el precio.
    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->assertDontSee('Precio propio')
        ->assertDontSeeHtml('wire:model="price"')
        ->set('sizeId', sizeOfProduct($producto, 'XL')->getKey())
        ->call('save')
        ->assertHasNoErrors();

    expect($variante->refresh()->size->name)->toBe('XL')
        ->and($variante->price_override)->toBeNull()
        ->and((float) $producto->base_price)->toEqual(89900.0);
});

/**
 * A variant that already carries an override keeps it when its size or color is
 * edited: the panel does not write `price_override`, so it does not get to erase
 * it either.
 */
test('editing a variant leaves a price it already had alone', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create(['base_price' => 89900]);
    $color = Color::factory()->create();
    $otroColor = Color::factory()->create();

    $variante = (new CreateProductVariant)($producto, sizeOfProduct($producto)->getKey(), $color, '64900', 5, adminForPanel());

    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->set('sizeId', sizeOfProduct($producto, 'XL')->getKey())
        ->set('colorId', $otroColor->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante->refresh();

    expect($variante->size->name)->toBe('XL')
        ->and($variante->color_id)->toBe($otroColor->getKey())
        ->and((float) $variante->price_override)->toEqual(64900.0);
});

test('a color that does not exist is refused and nothing is written', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', 987654)
        ->call('save')
        ->assertHasErrors(['colorId'])
        ->assertSet('showForm', true);

    expect($producto->variants()->count())->toBe(0);
});

test('refuses a combination already taken and a size of another category', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto)->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto)->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasErrors(['sizeId'])
        ->assertSet('showForm', true);

    // Una talla de otra categoría nunca es una opción del formulario: el select sólo
    // ofrece las de la categoría del producto, así que si aun así llega un id ajeno,
    // la validación lo corta aquí y no en la llave foránea.
    $ajena = Size::factory()->create(['name' => '42']);

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', $ajena->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasErrors(['sizeId'])
        ->assertSet('sizeId', $ajena->getKey());

    expect($producto->variants()->count())->toBe(1)
        ->and($variante->stock)->toBe(0);
});

test('refuses a size that is turned off and keeps the one the variant already has', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();
    $mediana = sizeOfProduct($producto);
    $grande = sizeOfProduct($producto, 'XL');
    $grande->update(['is_active' => false]);

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', $mediana->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    // Desactivada no quiere decir borrada: sigue en el catálogo de la categoría, pero
    // el formulario no la ofrece para variantes nuevas y así se comporta.
    variantPanel($producto)
        ->call('startCreating')
        ->assertDontSeeHtml('<option value="'.$grande->getKey().'"')
        ->set('sizeId', $grande->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasErrors(['sizeId']);

    // La variante que ya está en ella sí se puede seguir editando.
    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->set('sizeId', $mediana->getKey())
        ->call('save')
        ->assertHasNoErrors();

    expect($producto->variants()->count())->toBe(1)
        ->and($variante->refresh()->size_id)->toBe($mediana->getKey());
});

test('refuses a color that is turned off and keeps the one the variant already has', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create(['name' => 'Azul']);
    $apagado = Color::factory()->inactive()->create(['name' => 'Verde']);
    $mediana = sizeOfProduct($producto);

    // Un color apagado ni siquiera se ofrece, así que el id escrito a mano no llega a
    // la acción: se rechaza con el aviso del campo, como ya pasaba con la talla.
    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', $mediana->getKey())
        ->set('colorId', $apagado->getKey())
        ->call('save')
        ->assertHasErrors(['colorId' => 'El color seleccionado no está disponible.'])
        ->assertSet('showForm', true);

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', $mediana->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    // Desactivado no quiere decir borrado: el mismo trato que una talla apagada, con el
    // aviso bajo su propio select porque son dos campos con dos motivos distintos.
    // La variante que ya está en él sí se puede seguir editando sin moverla de color.
    $apagado->update(['name' => 'Verde']);
    $variante->update(['color_id' => $apagado->getKey()]);

    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->assertSet('colorId', $apagado->getKey())
        ->call('save')
        ->assertHasNoErrors();

    expect($producto->variants()->count())->toBe(1)
        ->and($variante->refresh()->color_id)->toBe($apagado->getKey());
});

test('the select of colors only offers the ones the store still sells, and the color of the variant is always in it', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $mediana = sizeOfProduct($producto);

    // El orden es el que lleva el catálogo (`order`, `id`), no el alfabético del
    // nombre: es el mismo orden que usa el resto del panel.
    $tercero = Color::factory()->create(['name' => 'Zafiro', 'order' => 3]);
    $primero = Color::factory()->create(['name' => 'Amarillo', 'order' => 1]);
    $segundo = Color::factory()->create(['name' => 'Blanco', 'order' => 2]);
    $apagado = Color::factory()->inactive()->create(['name' => 'Verde', 'order' => 4]);

    // Al crear no hay variante propia, así que el apagado no aparece.
    variantPanel($producto)
        ->call('startCreating')
        ->assertSeeHtmlInOrder([
            '<option value="'.$primero->getKey().'">',
            '<option value="'.$segundo->getKey().'">',
            '<option value="'.$tercero->getKey().'">',
        ])
        ->assertDontSeeHtml('<option value="'.$apagado->getKey().'">');

    $apagado->update(['name' => 'Verde', 'is_active' => true]);
    $variante = (new CreateProductVariant)($producto, $mediana->getKey(), $apagado);
    $apagado->update(['is_active' => false]);

    // Al editar, el color que la variante ya tiene se ofrece aunque esté apagado, y
    // marcado como tal para que se vea que no es una opción normal del catálogo.
    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->assertSet('colorId', $apagado->getKey())
        ->assertSeeHtml($apagado->name.' (inactivo)')
        ->assertSeeHtmlInOrder([
            '<option value="'.$primero->getKey().'">',
            '<option value="'.$segundo->getKey().'">',
            '<option value="'.$tercero->getKey().'">',
            '<option value="'.$apagado->getKey().'">',
        ]);

    expect($variante->color_id)->toBe($apagado->getKey());
});

test('editing a variant changes its data but neither its sku nor its stock', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $colorInicial = Color::factory()->create();
    $colorNuevo = Color::factory()->create(['name' => 'Rojo']);

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'S')->getKey())
        ->set('colorId', $colorInicial->getKey())
        ->set('initialStock', '5')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();
    $skuOriginal = $variante->sku;

    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->assertSet('sizeId', sizeOfProduct($producto, 'S')->getKey())
        ->assertSet('editingSku', $skuOriginal)
        ->set('sizeId', sizeOfProduct($producto, 'XL')->getKey())
        ->set('colorId', $colorNuevo->getKey())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Variante actualizada correctamente.')
        ->assertDispatched('product-variants-changed');

    $variante->refresh();

    expect($variante->size->name)->toBe('XL')
        ->and($variante->color_id)->toBe($colorNuevo->getKey())
        ->and($variante->price_override)->toBeNull()
        ->and($variante->sku)->toBe($skuOriginal)
        ->and($variante->stock)->toBe(5)
        ->and($variante->inventoryMovements()->count())->toBe(1);
});

test('refuses to edit a variant into a combination the product already has', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    foreach (['S', 'M'] as $talla) {
        variantPanel($producto)
            ->call('startCreating')
            ->set('sizeId', sizeOfProduct($producto, $talla)->getKey())
            ->set('colorId', $color->getKey())
            ->call('save')
            ->assertHasNoErrors();
    }

    $mediana = $producto->variants()->where('size_id', sizeOfProduct($producto, 'M')->getKey())->sole();

    variantPanel($producto)
        ->call('startEditing', $mediana->getKey())
        ->set('sizeId', sizeOfProduct($producto, 'S')->getKey())
        ->call('save')
        ->assertHasErrors(['sizeId']);

    expect($mediana->fresh()->size->name)->toBe('M');
});

test('starting a new one from an existing one keeps its size and color', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startCreatingFrom', $variante->getKey())
        ->assertSet('showForm', true)
        ->assertSet('editingId', null)
        ->assertSet('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->assertSet('colorId', $color->getKey());
});

/**
 * The stock of a variant is the one the form writes, and a form nobody wrote
 * anything in must not be read as if it said zero: the creation treats a blank
 * as a zero at save time, and until then the field stays empty, showing only
 * the zero as a hint of what is expected.
 */
test('the creation form opens with an empty stock and only a hint of a zero', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    // El valor por defecto se comprueba apenas montado, sin llamar a nada: es lo
    // único que delata un «0» que el campo no escribió (cualquier apertura del
    // formulario pasa por `resetForm()`, que también empieza en blanco).
    variantPanel($producto)
        ->assertSet('initialStock', '')
        ->call('startCreating')
        ->assertSet('initialStock', '')
        ->assertSeeHtml('id="variant-stock"')
        ->assertSeeHtml('placeholder="0"')
        ->assertDontSeeHtml('value="0"');
});

/**
 * The same blank applies when the form is opened from an existing variant: its
 * size and color are copied, but its stock is not, because a changing stock
 * belongs to the history of a variant while a new one always begins again.
 */
test('starting a new one from an existing one leaves the stock blank', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '12')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startCreatingFrom', $variante->getKey())
        ->assertSet('initialStock', '');
});

test('saving with an empty stock creates the variant with zero and no movement', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Variante creada correctamente.');

    $variante = $producto->variants()->sole();

    expect($variante->stock)->toBe(0)
        ->and($variante->inventoryMovements()->count())->toBe(0);
});

test('saving with a written stock creates it and records the initial movement', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '5')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();
    $movimiento = $variante->inventoryMovements()->sole();

    expect($variante->stock)->toBe(5)
        ->and($movimiento->type)->toBe(CreateProductVariant::INITIAL_STOCK_TYPE)
        ->and($movimiento->quantity)->toBe(5)
        ->and($movimiento->note)->toBe(CreateProductVariant::INITIAL_STOCK_REASON);
});

test('refuses a stock that is negative or not a whole number and keeps the form open', function (string $stock) {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $color = Color::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', $stock)
        ->call('save')
        ->assertHasErrors(['initialStock'])
        ->assertSet('showForm', true);

    expect($producto->variants()->count())->toBe(0);
})->with(['-1', '2.5', 'abc']);

test('adjusts the stock in both directions and writes the reason down', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $producto = Product::factory()->create();

    variantPanel($producto, $admin)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->set('initialStock', '10')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto, $admin)
        ->call('startAdjusting', $variante->getKey())
        ->set('stockDelta', '5')
        ->set('stockReason', 'Reposición')
        ->call('adjustStock')
        ->assertHasNoErrors()
        ->assertSet('adjustingId', null)
        ->assertSet('notice', 'Stock ajustado correctamente.')
        ->assertDispatched('product-variants-changed');

    expect($variante->fresh()->stock)->toBe(15);

    variantPanel($producto, $admin)
        ->call('startAdjusting', $variante->getKey())
        ->set('stockDelta', '-3')
        ->set('stockReason', 'Merma')
        ->call('adjustStock')
        ->assertHasNoErrors();

    expect($variante->fresh()->stock)->toBe(12);

    $ultimo = $variante->inventoryMovements()->orderByDesc('id')->first();

    expect($variante->inventoryMovements()->count())->toBe(3)
        ->and($ultimo->quantity)->toBe(-3)
        ->and($ultimo->note)->toBe('Merma')
        ->and($ultimo->user_id)->toBe($admin->getKey());
});

test('refuses an adjustment that would leave the stock below zero', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->set('initialStock', '2')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startAdjusting', $variante->getKey())
        ->set('stockDelta', '-5')
        ->set('stockReason', 'Devolución imposible')
        ->call('adjustStock')
        ->assertHasNoErrors()
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', InsufficientStockException::forVariant($variante, 5)->getMessage())
        ->assertSet('adjustingId', $variante->getKey());

    expect($variante->fresh()->stock)->toBe(2)
        ->and($variante->inventoryMovements()->count())->toBe(1);
});

test('an adjustment needs a reason and a non zero amount', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startAdjusting', $variante->getKey())
        ->call('adjustStock')
        ->assertHasErrors(['stockDelta', 'stockReason']);

    variantPanel($producto)
        ->call('startAdjusting', $variante->getKey())
        ->set('stockDelta', '0')
        ->set('stockReason', 'Nada')
        ->call('adjustStock')
        ->assertHasErrors(['stockDelta']);

    expect($variante->fresh()->stock)->toBe(0);
});

test('turning a variant off takes it out of the catalog without deleting it', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->set('initialStock', '4')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('toggle', $variante->getKey())
        ->assertSet('notice', "Variante «{$variante->sku}» desactivada correctamente.")
        ->assertDispatched('product-variants-changed');

    expect($variante->fresh()->is_active)->toBeFalse();

    variantPanel($producto)
        ->call('toggle', $variante->getKey())
        ->assertSet('notice', "Variante «{$variante->sku}» activada correctamente.");

    expect($variante->fresh()->is_active)->toBeTrue();
});

test('deletes a variant nobody has touched', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('delete', $variante->getKey())
        ->assertSet('notice', 'Variante eliminada correctamente.')
        ->assertDispatched('product-variants-changed')
        ->assertSee('Aún no hay variantes');

    expect($producto->variants()->count())->toBe(0);
});

test('a variant with movements stays in place and says why', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->set('initialStock', '5')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    // El botón está siempre visible, como en el panel de categorías: lo que decide
    // si la variante se puede borrar es la acción, no la vista.
    variantPanel($producto)
        ->assertSee('Eliminar')
        ->assertSeeHtml("wire:click=\"startAdjusting({$variante->getKey()})\"");

    (new AdjustProductVariantStock)($producto, $variante->getKey(), 1, 'Conteo', adminForPanel());

    variantPanel($producto)
        ->call('delete', $variante->getKey())
        ->assertSet('noticeType', 'error')
        ->assertSet(
            'notice',
            ProductVariantNotDeletableException::adjusted($variante)->getMessage(),
        )
        ->assertNotDispatched('product-variants-changed');

    expect($producto->variants()->count())->toBe(1);
});

test('the listing moves from no variants to active and back to out of stock', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create();
    $color = Color::factory()->create();

    // La insignia del listado es lo que se repinta: «Sin variantes» también
    // aparece en el texto de la pestaña, así que se mira su clase.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('font-medium bg-hueso text-gris-calido border border-arena');

    expect($producto->display_status)->toBe('no_variants');

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '7')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('font-medium bg-verde/10 text-verde')
        ->assertDontSeeHtml('font-medium bg-hueso text-gris-calido border border-arena');

    expect($producto->display_status)->toBe('active');

    $variante = $producto->variants()->sole();

    (new AdjustProductVariantStock)($producto, $variante->getKey(), -7, 'Venta', adminForPanel());

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('font-medium bg-ladrillo/10 text-ladrillo');

    // El modelo en memoria ya tenía las variantes cargadas de la primera lectura,
    // así que hay que releerlas para que el accessor vea el stock que quedó.
    expect($producto->refresh()->display_status)->toBe('out_of_stock');
});

/**
 * `products.status` is a column, `out_of_stock` is a calculation.
 *
 * The whole module runs its way through every action a product can take and the
 * column is only read at the end: nothing in the panel may confuse the stored
 * state with the one derived from the stock of the variants.
 */
test('the stored status of a product is never left as out of stock', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create();
    $color = Color::factory()->create();
    $otroColor = Color::factory()->create();

    $estadoGuardado = fn (): string => Product::query()->findOrFail($producto->getKey())->status;

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '4')
        ->call('save')
        ->assertHasNoErrors();

    $variante = $producto->variants()->sole();

    variantPanel($producto)
        ->call('startEditing', $variante->getKey())
        ->call('save')
        ->assertHasNoErrors();

    variantPanel($producto)
        ->call('startAdjusting', $variante->getKey())
        ->set('stockDelta', '-4')
        ->set('stockReason', 'Venta de mostrador')
        ->call('adjustStock')
        ->assertHasNoErrors();

    // Agotada de verdad: el accessor lo dice, la columna ni se entera.
    expect($producto->refresh()->display_status)->toBe('out_of_stock')
        ->and($estadoGuardado())->toBe('active');

    variantPanel($producto)
        ->call('toggle', $variante->getKey())
        ->assertHasNoErrors();

    variantPanel($producto)
        ->call('toggle', $variante->getKey())
        ->assertHasNoErrors();

    // Una segunda variante, esta vez sin stock, para poder borrarla: la que tiene
    // movimientos ya no se puede eliminar y esa regla es de otra prueba.
    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'L')->getKey())
        ->set('colorId', $otroColor->getKey())
        ->set('initialStock', '0')
        ->call('save')
        ->assertHasNoErrors();

    variantPanel($producto)
        ->call('delete', $producto->variants()->where('size_id', sizeOfProduct($producto, 'L')->getKey())->sole()->getKey())
        ->assertHasNoErrors();

    expect($estadoGuardado())->toBe('active')
        ->and(Product::query()->findOrFail($producto->getKey())->status)->not->toBe('out_of_stock')
        ->and($producto->refresh()->display_status)->toBe('out_of_stock');
});

/**
 * The three states have to agree everywhere at once.
 *
 * The badge comes from `display_status`, the filter from `statusFilter` and the
 * column from the same calculation; this reads them together so a change that
 * fixes one of them while breaking another shows up here.
 */
test('the listing the filter and the model agree on the state of a product', function () {
    $this->seed(RoleSeeder::class);

    $categoria = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($categoria)->create(['name' => 'Polo de auditoría']);
    $color = Color::factory()->create();

    $filtroTrae = function (string $estado) use ($producto) {
        $componente = Livewire::actingAs(adminForPanel())
            ->test(Index::class, ['search' => $producto->name, 'statusFilter' => $estado]);

        $componente->assertSee($producto->name);

        return $componente;
    };

    $filtroOculta = function (string $estado) use ($producto) {
        Livewire::actingAs(adminForPanel())
            ->test(Index::class, ['search' => $producto->name, 'statusFilter' => $estado])
            ->assertDontSee($producto->name);
    };

    // Sin variantes: el filtro la trae y los otros dos no.
    $filtroTrae('no_variants');
    $filtroOculta('active');
    $filtroOculta('out_of_stock');

    expect($producto->display_status)->toBe('no_variants');

    variantPanel($producto)
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', $color->getKey())
        ->set('initialStock', '2')
        ->call('save')
        ->assertHasNoErrors();

    // Activa: el filtro activo la trae y el de agotadas no.
    $filtroTrae('active');
    $filtroOculta('no_variants');
    $filtroOculta('out_of_stock');

    expect($producto->refresh()->display_status)->toBe('active');

    $variante = $producto->variants()->sole();

    (new AdjustProductVariantStock)($producto, $variante->getKey(), -2, 'Venta', adminForPanel());

    // Agotada: el filtro de agotadas la trae y el activo no.
    $filtroTrae('out_of_stock');
    $filtroOculta('active');
    $filtroOculta('no_variants');

    expect($producto->refresh()->display_status)->toBe('out_of_stock');
});

test('the listing is refreshed when the tab says its variants changed', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class, ['search' => $producto->name])
        ->assertSee('Sin variantes')
        ->call('edit', $producto)
        ->assertSee('Aún no hay variantes')
        // `@product-variants-changed="$refresh"` en el tag del hijo se compila en
        // un `x-on:` sobre su elemento raíz, que es lo que hace que el listado
        // vuelva a pintar el estado que deriva del stock.
        ->assertSeeHtml('x-on:product-variants-changed="$wire.$parent.$refresh"')
        ->assertSeeHtml('wire:key="variants-'.$producto->getKey().'"');

    Livewire::actingAs(adminForPanel())
        ->test(Variants::class, ['productId' => $producto->getKey()])
        ->call('startCreating')
        ->set('sizeId', sizeOfProduct($producto, 'M')->getKey())
        ->set('colorId', Color::factory()->create()->getKey())
        ->set('initialStock', '3')
        ->call('save')
        ->assertDispatched('product-variants-changed', productId: $producto->getKey());
});

test('the number of queries does not grow with the number of variants', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();

    $queriesFor = function (int $variantes) use ($admin): int {
        $producto = Product::factory()->create();
        $colores = Color::factory()->count(3)->create();

        $combinaciones = [];

        foreach (Size::STANDARD_NAMES as $talla) {
            foreach ($colores as $color) {
                $combinaciones[] = [$talla, $color->getKey()];
            }
        }

        foreach (array_slice($combinaciones, 0, $variantes) as $indice => [$talla, $colorId]) {
            ProductVariant::factory()->for($producto)->for($colores->firstWhere('id', $colorId), 'color')->create([
                'size_id' => sizeOfProduct($producto, $talla)->getKey(),
                'sku' => "SKU-{$producto->getKey()}-{$indice}",
            ]);
        }

        // El guard de rol lee los permisos una sola vez y los cachea el
        // registrador de Spatie: se calienta antes de contar para que la
        // medición sea sobre las variantes y no sobre ese cacheo.
        $admin->hasRole('admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)->test(Variants::class, ['productId' => $producto->getKey()]);

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    $conTres = $queriesFor(3);
    $conQuince = $queriesFor(15);

    expect($conQuince)->toBe($conTres);
});

test('the variants of a product are listed in the order of their size and then by color', function () {
    $this->seed(RoleSeeder::class);

    $producto = Product::factory()->create();
    $azul = Color::factory()->create(['name' => 'Azul']);
    $rojo = Color::factory()->create(['name' => 'Rojo']);

    // Las tallas se leen en el orden que la categoría lleva, no en el alfabético de su
    // nombre: por eso aquí el orden se dice de antemano y no sale de crear las tallas.
    $chica = sizeOfProduct($producto, 'S');
    $mediana = sizeOfProduct($producto, 'M');
    $grande = sizeOfProduct($producto, 'XL');
    $chica->update(['order' => 1]);
    $mediana->update(['order' => 2]);
    $grande->update(['order' => 3]);

    ProductVariant::factory()->for($producto)->for($rojo, 'color')->create(['size_id' => $grande->getKey(), 'sku' => 'A-XL-ROJO']);
    ProductVariant::factory()->for($producto)->for($azul, 'color')->create(['size_id' => $chica->getKey(), 'sku' => 'B-S-AZUL']);
    ProductVariant::factory()->for($producto)->for($rojo, 'color')->create(['size_id' => $mediana->getKey(), 'sku' => 'C-M-ROJO']);
    ProductVariant::factory()->for($producto)->for($azul, 'color')->create(['size_id' => $mediana->getKey(), 'sku' => 'D-M-AZUL']);

    $vista = variantPanel($producto)->html();

    // Y dentro de una misma talla manda el nombre del color, no el orden en que se
    // crearon las variantes: «Azul» va antes que «Rojo».
    $orden = ['B-S-AZUL', 'D-M-AZUL', 'C-M-ROJO', 'A-XL-ROJO'];

    $posiciones = array_map(fn (string $sku): int => strpos($vista, $sku), $orden);

    expect($posiciones)->each->toBeInt()
        ->and($posiciones)->toBe(collect($posiciones)->sort()->values()->all());
});
