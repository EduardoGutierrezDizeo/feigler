<?php

use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingSkuPrefixException;
use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function rootCategory(string $skuPrefix, string $name = 'Prenda'): Category
{
    return Category::factory()->create(['name' => $name, 'sku_prefix' => $skuPrefix]);
}

test('products are numbered in their own series per prefix', function () {
    $camisetas = rootCategory('CM', 'Camisetas');
    $polos = rootCategory('PL', 'Polos');

    $primera = Product::factory()->for($camisetas)->create();
    $segunda = Product::factory()->for($camisetas)->create();
    $otraSerie = Product::factory()->for($polos)->create();

    expect($primera->reference)->toBe('CM-001')
        ->and($segunda->reference)->toBe('CM-002')
        ->and($otraSerie->reference)->toBe('PL-001');
});

test('the counter keeps counting past three digits', function () {
    $polos = rootCategory('PL', 'Polos');

    Product::factory()->for($polos)->create(['reference' => 'PL-998']);
    Product::factory()->for($polos)->create(['reference' => 'PL-999']);

    expect(Product::factory()->for($polos)->create()->reference)->toBe('PL-1000')
        ->and(Product::factory()->for($polos)->create()->reference)->toBe('PL-1001');
});

test('a reference in another format is left out of the count', function () {
    $polos = rootCategory('PL', 'Polos');

    Product::factory()->for($polos)->create(['reference' => 'PL-ABC']);
    $siguiente = Product::factory()->for($polos)->create(['reference' => 'PL-007']);

    expect($siguiente->refresh()->reference)->toBe('PL-007')
        ->and(Product::nextReferenceFor($polos))->toBe('PL-008');
});

test('a subcategory borrows the prefix of its root', function () {
    $raiz = rootCategory('CM', 'Camisetas');
    $hija = Category::factory()->for($raiz, 'parent')->create(['name' => 'Manga corta']);

    $producto = Product::factory()->for($hija)->create();

    expect($hija->sku_prefix)->toBeNull()
        ->and($producto->reference)->toBe('CM-001')
        ->and(Product::nextReferenceFor($hija))->toBe('CM-002');
});

test('a product keeps its reference when its category changes', function () {
    $camisetas = rootCategory('CM', 'Camisetas');
    $polos = rootCategory('PL', 'Polos');
    $producto = Product::factory()->for($camisetas)->create();

    $producto->update(['category_id' => $polos->id]);

    expect($producto->refresh()->reference)->toBe('CM-001');
});

test('a product cannot be created when its root category has no sku prefix', function () {
    $raiz = Category::factory()->create(['name' => 'Sin prefijo', 'sku_prefix' => null]);

    expect(fn () => Product::factory()->for($raiz)->create())
        ->toThrow(MissingSkuPrefixException::class, 'La categoría «Sin prefijo» no tiene prefijo de SKU. Asígnalo en Categorías antes de crear productos.');
});

test('the sku of a variant is the reference, the size and the color code', function () {
    $product = Product::factory()->for(rootCategory('PL', 'Polos'))->create();
    $color = Color::factory()->create(['code' => 'AZU']);

    expect(ProductVariant::makeSku($product, 'M', $color))->toBe('PL-001-M-AZU')
        ->and(ProductVariant::makeSku($product, 'm', $color))->toBe('PL-001-M-AZU')
        ->and(ProductVariant::makeSku($product, '  extra large ', $color))->toBe('PL-001-EXTRALARGE-AZU')
        ->and(ProductVariant::makeSku($product, '42', $color))->toBe('PL-001-42-AZU');
});

test('a sku cannot be built for a product without a reference', function (?string $reference) {
    $product = Product::factory()->for(rootCategory('PL', 'Polos'))->create(['name' => 'Polo Clásico']);
    $color = Color::factory()->create(['code' => 'AZU']);

    DB::table('products')->where('id', $product->id)->update(['reference' => $reference]);

    expect(fn () => ProductVariant::makeSku($product->fresh(), 'M', $color))
        ->toThrow(LogicException::class, 'El producto «Polo Clásico» no tiene referencia; no se puede generar el SKU.');
})->with([
    'referencia nula' => [null],
    'referencia vacía' => [''],
]);

test('an entry adds stock and a sale takes it away, both leaving a movement', function () {
    $variant = ProductVariant::factory()->create(['stock' => 0]);

    $entrada = $variant->recordStockChange('ajuste_entrada', 10, note: 'Recepción de mercancía');
    $salida = $variant->recordStockChange('venta_online', 4);

    expect($variant->refresh()->stock)->toBe(6)
        ->and($entrada->type)->toBe('ajuste_entrada')
        ->and($entrada->quantity)->toBe(10)
        ->and($entrada->note)->toBe('Recepción de mercancía')
        ->and($salida->type)->toBe('venta_online')
        ->and($salida->quantity)->toBe(-4)
        ->and(InventoryMovement::count())->toBe(2)
        ->and($variant->inventoryMovements()->count())->toBe(2);
});

test('every movement type stores its amount with the sign of its direction', function (string $type, int $expectedQuantity, int $expectedStock) {
    $variant = ProductVariant::factory()->create(['stock' => 10]);

    $movement = $variant->recordStockChange($type, 4);

    expect($movement->type)->toBe($type)
        ->and($movement->quantity)->toBe($expectedQuantity)
        ->and($variant->fresh()->stock)->toBe($expectedStock)
        ->and($movement->quantity < 0)->toBe(in_array($type, ProductVariant::STOCK_REDUCING_TYPES, true))
        ->and($variant->inventoryMovements()->count())->toBe(1);
})->with([
    'ajuste_entrada' => ['ajuste_entrada', 4, 14],
    'devolucion' => ['devolucion', 4, 14],
    'venta_online' => ['venta_online', -4, 6],
    'venta_pos' => ['venta_pos', -4, 6],
    'ajuste_salida' => ['ajuste_salida', -4, 6],
]);

test('a movement that would leave the stock below zero changes nothing', function () {
    $variant = ProductVariant::factory()->create(['stock' => 3]);

    expect(fn () => $variant->recordStockChange('ajuste_salida', 4))->toThrow(InsufficientStockException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and(InventoryMovement::count())->toBe(0);
});

test('a movement needs a quantity greater than zero', function () {
    $variant = ProductVariant::factory()->create(['stock' => 3]);

    expect(fn () => $variant->recordStockChange('ajuste_entrada', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $variant->recordStockChange('ajuste_entrada', -2))->toThrow(InvalidArgumentException::class);

    expect($variant->refresh()->stock)->toBe(3)
        ->and(InventoryMovement::count())->toBe(0);
});

test('the displayed status is computed instead of stored', function () {
    $category = rootCategory('PL', 'Polos');
    $color = Color::factory()->create();

    $inactivo = Product::factory()->for($category)->inactive()->create();
    ProductVariant::factory()->for($inactivo)->create(['stock' => 10]);

    $sinVariantes = Product::factory()->for($category)->create();

    $agotado = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($agotado)->create(['stock' => 0]);
    $disponible = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($disponible)->create(['stock' => 3]);

    $legacy = Product::factory()->for($category)->outOfStock()->create();
    ProductVariant::factory()->for($legacy)->create(['stock' => 7]);

    expect($inactivo->display_status)->toBe('inactive')
        ->and($sinVariantes->display_status)->toBe('no_variants')
        ->and($agotado->display_status)->toBe('out_of_stock')
        ->and($disponible->display_status)->toBe('active')
        ->and($legacy->status)->toBe('out_of_stock')
        ->and($legacy->display_status)->toBe('active');
});

test('the total stock leaves out the inactive variants', function () {
    $product = Product::factory()->create();

    ProductVariant::factory()->for($product)->create(['size' => 'S', 'stock' => 4]);
    ProductVariant::factory()->for($product)->create(['size' => 'M', 'stock' => 6]);
    ProductVariant::factory()->for($product)->inactive()->create(['size' => 'L', 'stock' => 99]);

    expect($product->refresh()->stock_total)->toBe(10);
});

test('every size of a color shares the same gallery', function () {
    $product = Product::factory()->create();
    $azul = Color::factory()->create(['code' => 'AZU']);
    $rojo = Color::factory()->create(['code' => 'ROJ']);

    $mediana = ProductVariant::factory()->for($product)->create(['size' => 'M', 'color_id' => $azul->id]);
    $grande = ProductVariant::factory()->for($product)->create(['size' => 'XL', 'color_id' => $azul->id]);
    $medianaRoja = ProductVariant::factory()->for($product)->create(['size' => 'M', 'color_id' => $rojo->id]);

    $primera = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 1]);
    $segunda = ProductImage::factory()->for($product)->for($azul, 'color')->create(['order' => 0]);
    ProductImage::factory()->for($product)->for($rojo, 'color')->create();
    ProductImage::factory()->for($product)->create();

    $gallery = $mediana->gallery()->get();

    expect($gallery->pluck('id')->all())->toBe([$segunda->id, $primera->id])
        ->and($grande->gallery()->pluck('id')->all())->toBe([$segunda->id, $primera->id])
        ->and($medianaRoja->gallery()->count())->toBe(1)
        ->and($mediana->gallery()->count())->toBe(2)
        ->and($product->images()->count())->toBe(4)
        ->and($product->imagesForColor($azul)->count())->toBe(2);
});

test('a product lists each of its colors once', function () {
    $product = Product::factory()->create();
    $azul = Color::factory()->create();
    $rojo = Color::factory()->create();

    ProductVariant::factory()->for($product)->create(['size' => 'S', 'color_id' => $azul->id]);
    ProductVariant::factory()->for($product)->create(['size' => 'M', 'color_id' => $azul->id]);
    ProductVariant::factory()->for($product)->create(['size' => 'S', 'color_id' => $rojo->id]);

    expect($product->colors()->modelKeys())->toBe([$azul->id, $rojo->id]);
});

test('a color in use by a variant cannot be deleted', function () {
    $color = Color::factory()->create();
    ProductVariant::factory()->create(['color_id' => $color->id]);

    expect(fn () => $color->delete())->toThrow(QueryException::class);

    expect($color->fresh())->not->toBeNull();
});
