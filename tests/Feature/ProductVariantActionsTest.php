<?php

use App\Actions\Products\AdjustProductVariantStock;
use App\Actions\Products\CreateProductVariant;
use App\Actions\Products\DeleteProductVariant;
use App\Actions\Products\ToggleProductVariant;
use App\Actions\Products\UpdateProductVariant;
use App\Enums\StoreSection;
use App\Exceptions\DuplicateProductVariantException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Exceptions\InvalidVariantSizeException;
use App\Exceptions\ProductVariantNotDeletableException;
use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

/**
 * A product with a known reference, so the SKU of its variants is predictable.
 *
 * The prefix of the category is counted instead of fixed because it is unique in
 * the whole store, and a test may well ask for a second product.
 */
function productFor(string $reference = 'PL-001', string $name = 'Playera'): Product
{
    static $numero = 0;

    $numero++;

    $category = Category::factory()->create([
        'sku_prefix' => 'PR'.str_pad((string) $numero, 2, '0', STR_PAD_LEFT),
        'name' => 'Camisetas '.$numero,
    ]);

    return Product::factory()->for($category, 'category')->create([
        'name' => $name.' '.$numero,
        'reference' => $reference,
        'base_price' => 250,
    ]);
}

/**
 * A color. Its name and its code are unique, so the callers that need the same
 * blue twice hold on to the object instead of asking for it twice.
 */
function color(string $name, string $code): Color
{
    return Color::factory()->create(['name' => $name, 'code' => $code, 'hex' => '#112233']);
}

function azul(): Color
{
    return color('Azul', 'AZU');
}

function rojo(): Color
{
    return color('Rojo', 'ROJ');
}

/**
 * The id of a size of the category of this product, created when the category does
 * not carry it yet. The name is stored exactly as it comes, because the catalogue is
 * the one that normalizes; the actions only read it.
 */
function talla(Product $product, string $name = 'M'): int
{
    return Size::query()->firstOrCreate([
        'category_id' => $product->category_id,
        'name' => $name,
    ])->getKey();
}

/**
 * A size of a category of its own, which no product may point at.
 */
function tallaAjena(string $name = 'M'): Size
{
    return Size::factory()->create(['name' => $name]);
}

test('a variant is created with a sku built from the reference of the product', function () {
    $product = productFor();
    $azul = azul();

    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU')
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->size->name)->toBe('M')
        ->and($variant->size->category_id)->toBe($product->category_id)
        ->and($variant->color_id)->toBe($azul->id)
        ->and($variant->is_active)->toBeTrue()
        ->and($product->variants()->count())->toBe(1);
});

test('a sku already taken gets a number appended', function () {
    $product = productFor();
    $azul = azul();

    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU']);

    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU-2');
});

test('a second collision keeps counting from a two', function () {
    $product = productFor();
    $azul = azul();

    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU']);
    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU-2']);

    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU-3');
});

test('the sku never changes when the variant is edited', function () {
    $product = productFor();
    $azul = azul();
    $rojo = rojo();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    $edited = (new UpdateProductVariant)($product, $variant->id, talla($product, 'XL'), $rojo);

    expect($edited->sku)->toBe($variant->sku)
        ->and($edited->sku)->toBe('PL-001-M-AZU')
        ->and($edited->refresh()->sku)->toBe('PL-001-M-AZU')
        ->and($edited->size->name)->toBe('XL')
        ->and($edited->color_id)->toBe($rojo->id);
});

test('a variant without a price is sold at the price of its product', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, talla($product, 'L'), azul());

    expect($variant->price_override)->toBeNull()
        ->and($product->base_price)->toEqual('250.00');
});

test('a variant with a price keeps it', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, talla($product, 'L'), azul(), '199.90');

    expect($variant->refresh()->price_override)->toEqual('199.90');
});

test('a product cannot have two variants in the same size and color', function () {
    $product = productFor();
    $azul = azul();

    (new CreateProductVariant)($product, talla($product), $azul);

    expect(fn () => (new CreateProductVariant)($product, talla($product), $azul))
        ->toThrow(DuplicateProductVariantException::class);

    expect($product->variants()->count())->toBe(1);
});

test('the same size and color can be repeated in another product', function () {
    $azul = azul();
    $first = productFor('PL-001');
    $second = productFor('PL-002');

    (new CreateProductVariant)($first, talla($first), $azul);
    (new CreateProductVariant)($second, talla($second), $azul);

    expect($first->variants()->count())->toBe(1)
        ->and($second->variants()->count())->toBe(1)
        ->and($second->variants()->sole()->sku)->toBe('PL-002-M-AZU');
});

test('a size that does not exist is refused', function () {
    $product = productFor();

    expect(fn () => (new CreateProductVariant)($product, 987654, azul()))
        ->toThrow(ModelNotFoundException::class);

    expect($product->variants()->count())->toBe(0);
});

test('the sku normalizes the name of the size it points at', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, talla($product, ' m '), azul());

    expect($variant->size->name)->toBe(' m ')
        ->and($variant->sku)->toBe('PL-001-M-AZU');
});

test('an edited variant cannot move onto a combination that is taken', function () {
    $product = productFor();
    $azul = azul();
    $rojo = rojo();
    $editada = (new CreateProductVariant)($product, talla($product), $azul);

    (new CreateProductVariant)($product, talla($product), $rojo);

    expect(fn () => (new UpdateProductVariant)($product, $editada->id, talla($product), $rojo))
        ->toThrow(DuplicateProductVariantException::class);

    expect($editada->refresh()->color_id)->toBe($azul->id);
});

test('a variant can be edited without changing its size and color', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    $edited = (new UpdateProductVariant)($product, $variant->id, talla($product), $azul, '175.00');

    expect($edited->id)->toBe($variant->id)
        ->and($edited->price_override)->toEqual('175.00')
        ->and($product->variants()->count())->toBe(1);
});

test('editing a variant leaves its stock alone', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul, null, 7);

    $edited = (new UpdateProductVariant)($product, $variant->id, talla($product, 'XL'), rojo());

    expect($edited->refresh()->stock)->toBe(7)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('initial stock leaves a movement with the user and the reason behind it', function () {
    $product = productFor();
    $user = User::factory()->create();

    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 4, $user);

    $movement = $variant->inventoryMovements()->sole();

    expect($variant->stock)->toBe(4)
        ->and($movement->type)->toBe(CreateProductVariant::INITIAL_STOCK_TYPE)
        ->and($movement->quantity)->toBe(4)
        ->and($movement->note)->toBe(CreateProductVariant::INITIAL_STOCK_REASON)
        ->and($movement->user_id)->toBe($user->id)
        ->and($movement->order_id)->toBeNull();
});

test('a variant created with no stock leaves no movement', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, talla($product), azul());

    expect($variant->stock)->toBe(0)
        ->and($variant->inventoryMovements()->count())->toBe(0);
});

test('an adjustment moves the stock and leaves a movement saying why', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5, $user);
    $adjust = new AdjustProductVariantStock;

    $adjust($product, $variant->id, 6, 'Recepción de mercancía', $user);

    expect($variant->refresh()->stock)->toBe(11)
        ->and($variant->inventoryMovements()->count())->toBe(2);

    $movement = $variant->inventoryMovements()->orderByDesc('id')->first();

    expect($movement->type)->toBe('ajuste_entrada')
        ->and($movement->quantity)->toBe(6)
        ->and($movement->note)->toBe('Recepción de mercancía')
        ->and($movement->user_id)->toBe($user->id);

    $adjust($product, $variant->id, -4, 'Una prenda defectuosa', $user);

    expect($variant->refresh()->stock)->toBe(7);

    $movement = $variant->inventoryMovements()->orderByDesc('id')->first();

    expect($movement->type)->toBe('ajuste_salida')
        ->and($movement->quantity)->toBe(-4)
        ->and($variant->inventoryMovements()->sum('quantity'))->toBe(7);
});

test('an adjustment that would leave the stock below zero is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, -4, 'Merma'))
        ->toThrow(InsufficientStockException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('an adjustment of zero units is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 0, 'Nada que contar'))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('an adjustment without a reason is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 2, '   '))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('a reason longer than the note column is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 2, str_repeat('a', 256)))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3);
});

test('a negative initial stock is refused', function () {
    $product = productFor();

    expect(fn () => (new CreateProductVariant)($product, talla($product), azul(), null, -1))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($product->variants()->count())->toBe(0);
});

test('deactivating a variant takes it out of the catalog without deleting it', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5, $user);
    $toggle = new ToggleProductVariant;

    $toggle($product, $variant->id);

    expect($variant->refresh()->is_active)->toBeFalse()
        ->and($variant->stock)->toBe(5)
        ->and($variant->inventoryMovements()->count())->toBe(1)
        ->and($product->variants()->count())->toBe(1);

    $toggle($product, $variant->id);

    expect($variant->refresh()->is_active)->toBeTrue();
});

test('an inactive variant is not counted as stock', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 12);

    expect($product->load('variants')->stock_total)->toBe(12);

    (new ToggleProductVariant)($product, $variant->id);
    $product->unsetRelation('variants');

    expect($product->stock_total)->toBe(0);
});

test('the status shown by a product follows the stock of its variants', function () {
    $product = productFor();

    expect($product->display_status)->toBe('no_variants');

    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 4);

    expect($product->display_status)->toBe('active');

    (new AdjustProductVariantStock)($product, $variant->id, -4, 'Todo vendido en mostrador');
    $product->unsetRelation('variants');

    expect($product->display_status)->toBe('out_of_stock');
});

test('a variant that was never touched can be deleted', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5, $user);

    (new DeleteProductVariant)($product, $variant->id);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeFalse()
        ->and(InventoryMovement::query()->where('product_variant_id', $variant->id)->count())->toBe(0)
        ->and($product->display_status)->toBe('no_variants');
});

test('a variant created with no stock can be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul());

    (new DeleteProductVariant)($product, $variant->id);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeFalse();
});

test('a variant that was sold cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul());
    OrderItem::factory()->for($variant, 'productVariant')->create();

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue();
});

test('a variant whose stock was adjusted cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5);

    (new AdjustProductVariantStock)($product, $variant->id, -5, 'Todo vendido');

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue()
        ->and($variant->inventoryMovements()->count())->toBe(2);
});

test('a variant with a movement that is not its initial stock cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul());
    InventoryMovement::factory()->for($variant, 'variant')->create([
        'type' => 'devolucion',
        'quantity' => 1,
        'note' => 'Devolución de un cliente',
    ]);

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue();
});

test('a variant whose initial stock no longer matches cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5);
    InventoryMovement::query()->where('product_variant_id', $variant->id)->delete();

    $variant->update(['stock' => 5]);

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);
});

test('no action can touch a variant of another product', function () {
    $ajeno = productFor('PL-002');
    $ajena = (new CreateProductVariant)($ajeno, talla($ajeno), rojo(), null, 5);
    $producto = productFor('PL-001');

    $acciones = [
        'update' => fn () => (new UpdateProductVariant)($producto, $ajena->id, talla($producto, 'XL'), azul()),
        'adjust' => fn () => (new AdjustProductVariantStock)($producto, $ajena->id, 1, 'Ajuste indebido'),
        'toggle' => fn () => (new ToggleProductVariant)($producto, $ajena->id),
        'delete' => fn () => (new DeleteProductVariant)($producto, $ajena->id),
    ];

    foreach ($acciones as $nombre => $accion) {
        expect(fn () => $accion(), $nombre)->toThrow(ModelNotFoundException::class);
    }

    expect($ajena->refresh()->size->name)->toBe('M')
        ->and($ajena->stock)->toBe(5)
        ->and($ajena->is_active)->toBeTrue()
        ->and($ajena->inventoryMovements()->count())->toBe(1);
});

test('a variant is not left behind when creating it fails halfway', function () {
    $product = productFor();
    $azul = azul();
    $user = User::factory()->create();
    (new CreateProductVariant)($product, talla($product), $azul);

    expect(fn () => (new CreateProductVariant)($product, talla($product), $azul, null, 4, $user))
        ->toThrow(DuplicateProductVariantException::class);

    expect($product->variants()->count())->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('a failed deletion leaves the variant and its movements alone', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, talla($product), azul(), null, 5);
    OrderItem::factory()->for($variant, 'productVariant')->create();

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('product_variant_id', $variant->id)->count())->toBe(1);
});

test('a section is inherited from the category of the product', function () {
    $category = Category::factory()->section(StoreSection::Mujer)->create(['sku_prefix' => 'MUJ']);
    $product = Product::factory()->for($category, 'category')->create();

    expect($product->section)->toBe(StoreSection::Mujer);
});

/**
 * A variant cannot be written against a color that is not there.
 *
 * The action takes a `Color` object, so the only way a bad id reaches the write is
 * through the raw column: this is the database refusing it, which is the last line
 * of defence under the `exists` rule of the panel.
 */
test('a variant cannot be saved against a color that does not exist', function () {
    $product = productFor();

    expect(fn () => $product->variants()->create([
        'size_id' => talla($product),
        'color_id' => 987654,
        'sku' => 'PL-001-M-XXX',
    ]))->toThrow(QueryException::class);

    expect($product->variants()->count())->toBe(0);
});

/**
 * The transaction is what makes a half-written creation disappear.
 *
 * `DuplicateProductVariantException` is thrown before the insert, so it proves
 * nothing about the rollback; here the failure is injected once the row is already
 * in the table, which is where a rollback has something to undo.
 */
test('a creation that fails after the insert leaves nothing behind', function () {
    $product = productFor();
    $color = azul();
    $user = User::factory()->create();

    Event::listen('eloquent.created: '.ProductVariant::class, function (): void {
        throw new RuntimeException('Fallo simulado a mitad del alta');
    });

    expect(fn () => (new CreateProductVariant)($product, talla($product), $color, null, 5, $user))
        ->toThrow(RuntimeException::class);

    expect($product->variants()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

/**
 * A size belongs to the category it was created for, and a product can only be
 * built out of the sizes of its own category: otherwise a variant would carry a
 * size that the rest of the catalogue does not sell in that section.
 */
test('a size of another category is refused when a variant is created', function () {
    $product = productFor();
    $ajena = tallaAjena();

    expect(fn () => (new CreateProductVariant)($product, $ajena->getKey(), azul()))
        ->toThrow(InvalidVariantSizeException::class);

    expect($product->variants()->count())->toBe(0);
});

test('a size of another category is refused when a variant is edited', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);

    expect(fn () => (new UpdateProductVariant)($product, $variant->id, tallaAjena()->getKey(), $azul))
        ->toThrow(InvalidVariantSizeException::class);

    expect($variant->refresh()->size->name)->toBe('M');
});

test('the refusal names the category the size belongs to', function () {
    $product = productFor();
    $ajena = tallaAjena();

    expect(fn () => (new CreateProductVariant)($product, $ajena->getKey(), azul()))
        ->toThrow(
            InvalidVariantSizeException::class,
            'La talla «M» pertenece a la categoría «'.$ajena->category->name.
            '» y no a «'.$product->category->name.'», que es la del producto.',
        );
});

/**
 * A size that is switched off stays readable for the variants that already exist
 * in it, so an old variant can still be edited; what is refused is the choice.
 */
test('an inactive size cannot be chosen for a new variant', function () {
    $product = productFor();
    $inactiva = Size::factory()->for($product->category, 'category')->inactive()->create(['name' => 'M']);

    expect(fn () => (new CreateProductVariant)($product, $inactiva->getKey(), azul()))
        ->toThrow(InactiveVariantSizeException::class);

    expect($product->variants()->count())->toBe(0);
});

test('an inactive size cannot be moved onto by an edit', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);
    $inactiva = Size::factory()->for($product->category, 'category')->inactive()->create(['name' => 'XL']);

    expect(fn () => (new UpdateProductVariant)($product, $variant->id, $inactiva->getKey(), $azul))
        ->toThrow(InactiveVariantSizeException::class);

    expect($variant->refresh()->size->name)->toBe('M');
});

test('the refusal names the size that is switched off', function () {
    $product = productFor();
    $inactiva = Size::factory()->for($product->category, 'category')->inactive()->create(['name' => 'XL']);

    expect(fn () => (new CreateProductVariant)($product, $inactiva->getKey(), azul()))
        ->toThrow(
            InactiveVariantSizeException::class,
            'La talla «XL» está desactivada y no admite variantes nuevas.',
        );
});

test('a variant in an inactive size can still be edited on other fields', function () {
    $product = productFor();
    $azul = azul();
    $talla = talla($product);
    $variant = (new CreateProductVariant)($product, $talla, $azul);
    Size::query()->whereKey($talla)->update(['is_active' => false]);

    $edited = (new UpdateProductVariant)($product, $variant->id, $talla, $azul, '175.00');

    expect($edited->price_override)->toEqual('175.00')
        ->and($edited->size_id)->toBe($talla)
        ->and($edited->refresh()->sku)->toBe($variant->sku);
});

/**
 * Turning a color off is how the store takes it out of the offer, and a variant in a
 * color that is not offered is not for sale in anything.
 */
test('an inactive color cannot be chosen for a new variant', function () {
    $product = productFor();
    $inactiva = Color::factory()->inactive()->create(['name' => 'Verde', 'code' => 'VER']);

    expect(fn () => (new CreateProductVariant)($product, talla($product), $inactiva))
        ->toThrow(InactiveVariantColorException::class);

    expect($product->variants()->count())->toBe(0);
});

test('an inactive color cannot be moved onto by an edit', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);
    $inactiva = Color::factory()->inactive()->create(['name' => 'Verde', 'code' => 'VER']);

    expect(fn () => (new UpdateProductVariant)($product, $variant->id, talla($product), $inactiva))
        ->toThrow(InactiveVariantColorException::class);

    expect($variant->refresh()->color_id)->toBe($azul->id);
});

test('the refusal names the color that is switched off', function () {
    $product = productFor();
    $inactiva = Color::factory()->inactive()->create(['name' => 'Verde', 'code' => 'VER']);

    expect(fn () => (new CreateProductVariant)($product, talla($product), $inactiva))
        ->toThrow(
            InactiveVariantColorException::class,
            'El color «Verde» está desactivado y no admite variantes nuevas.'
        );
});

/**
 * A variant that is already in a deactivated color stays in it: the store is still
 * selling what it already has.
 */
test('a variant in an inactive color can still be edited on other fields', function () {
    $product = productFor();
    $inactiva = Color::factory()->create(['name' => 'Verde', 'code' => 'VER']);
    $variant = (new CreateProductVariant)($product, talla($product), $inactiva);
    $inactiva->update(['is_active' => false]);

    $edited = (new UpdateProductVariant)($product, $variant->id, talla($product), $inactiva, '175.00');

    expect($edited->price_override)->toEqual('175.00')
        ->and($edited->color_id)->toBe($inactiva->id)
        ->and($edited->refresh()->sku)->toBe($variant->sku);
});

test('moving a variant off an inactive color into an active one is allowed', function () {
    $product = productFor();
    $inactiva = Color::factory()->create(['name' => 'Verde', 'code' => 'VER']);
    $variant = (new CreateProductVariant)($product, talla($product), $inactiva);
    $inactiva->update(['is_active' => false]);
    $azul = azul();

    $edited = (new UpdateProductVariant)($product, $variant->id, talla($product), $azul);

    expect($edited->refresh()->color_id)->toBe($azul->id);
});

test('a refused color leaves the variant and its sku alone', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, talla($product), $azul);
    $inactiva = Color::factory()->inactive()->create(['name' => 'Verde', 'code' => 'VER']);

    expect(fn () => (new UpdateProductVariant)($product, $variant->id, talla($product), $inactiva))
        ->toThrow(InactiveVariantColorException::class);

    expect($variant->refresh()->color_id)->toBe($azul->id)
        ->and($variant->sku)->toBe('PL-001-M-AZU');
});
