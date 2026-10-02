<?php

use App\Enums\StoreSection;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function categoryWithPrefix(string $skuPrefix, string $name = 'Prenda'): Category
{
    return Category::factory()->create(['name' => $name, 'sku_prefix' => $skuPrefix]);
}

test('products are numbered in their own series per prefix', function () {
    $camisetas = categoryWithPrefix('CM', 'Camisetas');
    $polos = categoryWithPrefix('PL', 'Polos');

    $primera = Product::factory()->for($camisetas)->create();
    $segunda = Product::factory()->for($camisetas)->create();
    $otraSerie = Product::factory()->for($polos)->create();

    expect($primera->reference)->toBe('CM-001')
        ->and($segunda->reference)->toBe('CM-002')
        ->and($otraSerie->reference)->toBe('PL-001');
});

test('the counter keeps counting past three digits', function () {
    $polos = categoryWithPrefix('PL', 'Polos');

    Product::factory()->for($polos)->create(['reference' => 'PL-998']);
    Product::factory()->for($polos)->create(['reference' => 'PL-999']);

    expect(Product::factory()->for($polos)->create()->reference)->toBe('PL-1000')
        ->and(Product::factory()->for($polos)->create()->reference)->toBe('PL-1001');
});

test('a reference in another format is left out of the count', function () {
    $polos = categoryWithPrefix('PL', 'Polos');

    Product::factory()->for($polos)->create(['reference' => 'PL-ABC']);
    $siguiente = Product::factory()->for($polos)->create(['reference' => 'PL-007']);

    expect($siguiente->refresh()->reference)->toBe('PL-007')
        ->and(Product::nextReferenceFor($polos))->toBe('PL-008');
});

test('a prefix that starts like another one does not steal its series', function () {
    // `PL` es el prefijo viejo del catálogo y `PLH` el nuevo de los polos de hombre:
    // uno es prefijo del otro, así que la búsqueda de referencias tiene que exigir
    // el guion justo detrás del prefijo y no solo el comienzo.
    $polosViejos = categoryWithPrefix('PL', 'Polos');
    $polosHombre = categoryWithPrefix('PLH', 'Polos de hombre');

    Product::factory()->for($polosViejos)->create(['reference' => 'PL-001']);
    Product::factory()->for($polosViejos)->create(['reference' => 'PL-002']);

    expect(Product::nextReferenceFor($polosHombre))->toBe('PLH-001')
        ->and(Product::factory()->for($polosHombre)->create()->reference)->toBe('PLH-001')
        ->and(Product::factory()->for($polosHombre)->create()->reference)->toBe('PLH-002')
        ->and(Product::nextReferenceFor($polosViejos))->toBe('PL-003');
});

test('a product takes the section of its category', function () {
    $polosHombre = categoryWithPrefix('PLH', 'Polos de hombre');
    $polosMujer = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Polos', 'sku_prefix' => 'PLM']);

    $hombre = Product::factory()->for($polosHombre)->create();
    $mujer = Product::factory()->for($polosMujer)->create();

    expect($hombre->section)->toBe(StoreSection::Hombre)
        ->and($mujer->section)->toBe(StoreSection::Mujer);
});

test('a product keeps its reference when its category changes', function () {
    $camisetas = categoryWithPrefix('CM', 'Camisetas');
    $polos = categoryWithPrefix('PL', 'Polos');
    $producto = Product::factory()->for($camisetas)->create();

    $producto->update(['category_id' => $polos->id]);

    expect($producto->refresh()->reference)->toBe('CM-001');
});

test('the sku of a variant is the reference, the size and the color code', function () {
    $product = Product::factory()->for(categoryWithPrefix('PL', 'Polos'))->create();
    $color = Color::factory()->create(['code' => 'AZU']);

    expect(ProductVariant::makeSku($product, 'M', $color))->toBe('PL-001-M-AZU')
        ->and(ProductVariant::makeSku($product, 'm', $color))->toBe('PL-001-M-AZU')
        ->and(ProductVariant::makeSku($product, '  extra large ', $color))->toBe('PL-001-EXTRALARGE-AZU')
        ->and(ProductVariant::makeSku($product, '42', $color))->toBe('PL-001-42-AZU');
});

test('a sku cannot be built for a product without a reference', function (?string $reference) {
    $product = Product::factory()->for(categoryWithPrefix('PL', 'Polos'))->create(['name' => 'Polo Clásico']);
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
    $category = categoryWithPrefix('PL', 'Polos');
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

test('the displayed status reads the loaded variants instead of querying them', function () {
    $category = categoryWithPrefix('PL', 'Polos');

    $inactivo = Product::factory()->for($category)->inactive()->create();
    $sinVariantes = Product::factory()->for($category)->create();
    $agotado = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($agotado)->create(['stock' => 0]);
    $soloVarianteInactiva = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($soloVarianteInactiva)->inactive()->create(['stock' => 9]);
    $disponible = Product::factory()->for($category)->create();
    ProductVariant::factory()->for($disponible)->create(['stock' => 4]);

    $ids = collect([$inactivo, $sinVariantes, $agotado, $soloVarianteInactiva, $disponible])
        ->map(fn (Product $product): int => $product->id)
        ->all();

    // Con la relación cargada: los cuatro estados se resuelven sin tocar la base de
    // datos, porque `variants` ya está en memoria para `stock_total` y para el recuento.
    $cargados = Product::query()->with('variants')->whereKey($ids)->get();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $estadosCargados = $cargados
        ->mapWithKeys(fn (Product $product): array => [$product->id => $product->display_status])
        ->all();

    $consultas = count(DB::connection()->getQueryLog());

    DB::connection()->disableQueryLog();

    // Sin cargar: el accessor cae a la consulta, pero el resultado es el mismo.
    $estadosSinCargar = Product::query()->whereKey($ids)->get()
        ->mapWithKeys(fn (Product $product): array => [$product->id => $product->display_status])
        ->all();

    expect($estadosSinCargar)->toBe([
        $inactivo->id => 'inactive',
        $sinVariantes->id => 'no_variants',
        $agotado->id => 'out_of_stock',
        $soloVarianteInactiva->id => 'out_of_stock',
        $disponible->id => 'active',
    ])
        ->and($estadosCargados)->toBe($estadosSinCargar)
        ->and($consultas)->toBe(0);
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
    $primeraRoja = ProductImage::factory()->for($product)->for($rojo, 'color')->create(['order' => 1]);
    $segundaRoja = ProductImage::factory()->for($product)->for($rojo, 'color')->create(['order' => 0]);

    $gallery = $mediana->gallery()->get();

    expect($gallery->pluck('id')->all())->toBe([$segunda->id, $primera->id])
        ->and($grande->gallery()->pluck('id')->all())->toBe([$segunda->id, $primera->id])
        ->and($medianaRoja->gallery()->pluck('id')->all())->toBe([$segundaRoja->id, $primeraRoja->id])
        ->and($mediana->gallery()->count())->toBe(2)
        ->and($product->images()->count())->toBe(4)
        ->and($product->imagesForColor($azul)->count())->toBe(2)
        ->and($product->imagesForColor($rojo)->count())->toBe(2);
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
