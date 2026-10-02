<?php

use App\Actions\Products\AdjustProductVariantStock;
use App\Actions\Products\CreateProductVariant;
use App\Actions\Products\DeleteProductVariant;
use App\Actions\Products\ToggleProductVariant;
use App\Actions\Products\UpdateProductVariant;
use App\Enums\StoreSection;
use App\Exceptions\DuplicateProductVariantException;
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
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

test('a variant is created with a sku built from the reference of the product', function () {
    $product = productFor();
    $azul = azul();

    $variant = (new CreateProductVariant)($product, 'M', $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU')
        ->and($variant->product_id)->toBe($product->id)
        ->and($variant->size)->toBe('M')
        ->and($variant->color_id)->toBe($azul->id)
        ->and($variant->is_active)->toBeTrue()
        ->and($product->variants()->count())->toBe(1);
});

test('a sku already taken gets a number appended', function () {
    $product = productFor();
    $azul = azul();

    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU']);

    $variant = (new CreateProductVariant)($product, 'M', $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU-2');
});

test('a second collision keeps counting from a two', function () {
    $product = productFor();
    $azul = azul();

    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU']);
    ProductVariant::factory()->for($product)->create(['sku' => 'PL-001-M-AZU-2']);

    $variant = (new CreateProductVariant)($product, 'M', $azul);

    expect($variant->sku)->toBe('PL-001-M-AZU-3');
});

test('the sku never changes when the variant is edited', function () {
    $product = productFor();
    $azul = azul();
    $rojo = rojo();
    $variant = (new CreateProductVariant)($product, 'M', $azul);

    $edited = (new UpdateProductVariant)($product, $variant->id, 'XL', $rojo);

    expect($edited->sku)->toBe($variant->sku)
        ->and($edited->sku)->toBe('PL-001-M-AZU')
        ->and($edited->refresh()->sku)->toBe('PL-001-M-AZU')
        ->and($edited->size)->toBe('XL')
        ->and($edited->color_id)->toBe($rojo->id);
});

test('a variant without a price is sold at the price of its product', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, 'L', azul());

    expect($variant->price_override)->toBeNull()
        ->and($product->base_price)->toEqual('250.00');
});

test('a variant with a price keeps it', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, 'L', azul(), '199.90');

    expect($variant->refresh()->price_override)->toEqual('199.90');
});

test('a product cannot have two variants in the same size and color', function () {
    $product = productFor();
    $azul = azul();

    (new CreateProductVariant)($product, 'M', $azul);

    expect(fn () => (new CreateProductVariant)($product, 'M', $azul))
        ->toThrow(DuplicateProductVariantException::class);

    expect($product->variants()->count())->toBe(1);
});

test('the same size and color can be repeated in another product', function () {
    $azul = azul();
    $first = productFor('PL-001');
    $second = productFor('PL-002');

    (new CreateProductVariant)($first, 'M', $azul);
    (new CreateProductVariant)($second, 'M', $azul);

    expect($first->variants()->count())->toBe(1)
        ->and($second->variants()->count())->toBe(1)
        ->and($second->variants()->sole()->sku)->toBe('PL-002-M-AZU');
});

test('a size that does not exist is refused', function () {
    $product = productFor();

    expect(fn () => (new CreateProductVariant)($product, 'TLL', azul()))
        ->toThrow(InvalidVariantSizeException::class);

    expect($product->variants()->count())->toBe(0);
});

test('a size is stored the same way no matter how it is typed', function () {
    $product = productFor();

    $variant = (new CreateProductVariant)($product, ' m ', azul());

    expect($variant->size)->toBe('M')
        ->and($variant->sku)->toBe('PL-001-M-AZU');
});

test('an edited variant cannot move onto a combination that is taken', function () {
    $product = productFor();
    $azul = azul();
    $rojo = rojo();
    $editada = (new CreateProductVariant)($product, 'M', $azul);

    (new CreateProductVariant)($product, 'M', $rojo);

    expect(fn () => (new UpdateProductVariant)($product, $editada->id, 'M', $rojo))
        ->toThrow(DuplicateProductVariantException::class);

    expect($editada->refresh()->color_id)->toBe($azul->id);
});

test('a variant can be edited without changing its size and color', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, 'M', $azul);

    $edited = (new UpdateProductVariant)($product, $variant->id, 'M', $azul, '175.00');

    expect($edited->id)->toBe($variant->id)
        ->and($edited->price_override)->toEqual('175.00')
        ->and($product->variants()->count())->toBe(1);
});

test('editing a variant leaves its stock alone', function () {
    $product = productFor();
    $azul = azul();
    $variant = (new CreateProductVariant)($product, 'M', $azul, null, 7);

    $edited = (new UpdateProductVariant)($product, $variant->id, 'XL', rojo());

    expect($edited->refresh()->stock)->toBe(7)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('initial stock leaves a movement with the user and the reason behind it', function () {
    $product = productFor();
    $user = User::factory()->create();

    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 4, $user);

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

    $variant = (new CreateProductVariant)($product, 'M', azul());

    expect($variant->stock)->toBe(0)
        ->and($variant->inventoryMovements()->count())->toBe(0);
});

test('an adjustment moves the stock and leaves a movement saying why', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5, $user);
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
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, -4, 'Merma'))
        ->toThrow(InsufficientStockException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('an adjustment of zero units is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 0, 'Nada que contar'))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('an adjustment without a reason is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 2, '   '))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('a reason longer than the note column is refused', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 3);
    $adjust = new AdjustProductVariantStock;

    expect(fn () => $adjust($product, $variant->id, 2, str_repeat('a', 256)))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($variant->refresh()->stock)->toBe(3);
});

test('a negative initial stock is refused', function () {
    $product = productFor();

    expect(fn () => (new CreateProductVariant)($product, 'M', azul(), null, -1))
        ->toThrow(InvalidStockAdjustmentException::class);

    expect($product->variants()->count())->toBe(0);
});

test('deactivating a variant takes it out of the catalog without deleting it', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5, $user);
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
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 12);

    expect($product->load('variants')->stock_total)->toBe(12);

    (new ToggleProductVariant)($product, $variant->id);
    $product->unsetRelation('variants');

    expect($product->stock_total)->toBe(0);
});

test('the status shown by a product follows the stock of its variants', function () {
    $product = productFor();

    expect($product->display_status)->toBe('no_variants');

    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 4);

    expect($product->display_status)->toBe('active');

    (new AdjustProductVariantStock)($product, $variant->id, -4, 'Todo vendido en mostrador');
    $product->unsetRelation('variants');

    expect($product->display_status)->toBe('out_of_stock');
});

test('a variant that was never touched can be deleted', function () {
    $product = productFor();
    $user = User::factory()->create();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5, $user);

    (new DeleteProductVariant)($product, $variant->id);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeFalse()
        ->and(InventoryMovement::query()->where('product_variant_id', $variant->id)->count())->toBe(0)
        ->and($product->display_status)->toBe('no_variants');
});

test('a variant created with no stock can be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul());

    (new DeleteProductVariant)($product, $variant->id);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeFalse();
});

test('a variant that was sold cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul());
    OrderItem::factory()->for($variant, 'productVariant')->create();

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue();
});

test('a variant whose stock was adjusted cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5);

    (new AdjustProductVariantStock)($product, $variant->id, -5, 'Todo vendido');

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);

    expect(ProductVariant::query()->whereKey($variant->id)->exists())->toBeTrue()
        ->and($variant->inventoryMovements()->count())->toBe(2);
});

test('a variant with a movement that is not its initial stock cannot be deleted', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul());
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
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5);
    InventoryMovement::query()->where('product_variant_id', $variant->id)->delete();

    $variant->update(['stock' => 5]);

    expect(fn () => (new DeleteProductVariant)($product, $variant->id))
        ->toThrow(ProductVariantNotDeletableException::class);
});

test('no action can touch a variant of another product', function () {
    $ajena = (new CreateProductVariant)(productFor('PL-002'), 'M', rojo(), null, 5);
    $producto = productFor('PL-001');

    $acciones = [
        'update' => fn () => (new UpdateProductVariant)($producto, $ajena->id, 'XL', azul()),
        'adjust' => fn () => (new AdjustProductVariantStock)($producto, $ajena->id, 1, 'Ajuste indebido'),
        'toggle' => fn () => (new ToggleProductVariant)($producto, $ajena->id),
        'delete' => fn () => (new DeleteProductVariant)($producto, $ajena->id),
    ];

    foreach ($acciones as $nombre => $accion) {
        expect(fn () => $accion(), $nombre)->toThrow(ModelNotFoundException::class);
    }

    expect($ajena->refresh()->size)->toBe('M')
        ->and($ajena->stock)->toBe(5)
        ->and($ajena->is_active)->toBeTrue()
        ->and($ajena->inventoryMovements()->count())->toBe(1);
});

test('a variant is not left behind when creating it fails halfway', function () {
    $product = productFor();
    $azul = azul();
    $user = User::factory()->create();
    (new CreateProductVariant)($product, 'M', $azul);

    expect(fn () => (new CreateProductVariant)($product, 'M', $azul, null, 4, $user))
        ->toThrow(DuplicateProductVariantException::class);

    expect($product->variants()->count())->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('a failed deletion leaves the variant and its movements alone', function () {
    $product = productFor();
    $variant = (new CreateProductVariant)($product, 'M', azul(), null, 5);
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
