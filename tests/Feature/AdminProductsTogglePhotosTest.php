<?php

use App\Livewire\Admin\Products\Index;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/*
 * El aviso al activar un producto: un producto que no tiene fotos en ninguno de
 * sus colores se activa igual, pero el panel avisa en tono de advertencia con el
 * texto del diseño. Si tiene fotos, o si se está desactivando, no hay advertencia.
 */

test('al activar un producto sin fotos se activa y muestra la advertencia', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo sin fotos',
        'status' => 'inactive',
    ]);
    $talla = sizeOfProduct($producto);
    ProductVariant::factory()->for($producto)->for($talla)->create();

    expect($producto->images()->exists())->toBeFalse();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('toggleActive', $producto->id)
        ->assertDispatched('toast', tone: 'warning', message: '«Polo sin fotos» está activo, pero todavía no tiene fotos: en la tienda se verá con un degradado.');

    expect($producto->refresh()->status)->toBe('active');
});

test('al activar un producto con fotos no se muestra la advertencia', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo con fotos',
        'status' => 'inactive',
    ]);
    $talla = sizeOfProduct($producto);
    ProductVariant::factory()->for($producto)->for($talla)->create();
    $color = $producto->colors()->first() ?? ProductImage::factory()->for($producto)->create()->color;
    ProductImage::factory()->primary()->for($producto)->for($color)->create();

    expect($producto->images()->exists())->toBeTrue();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('toggleActive', $producto->id)
        ->assertDispatched('toast', tone: 'success', message: 'Producto «Polo con fotos» activado correctamente.');

    expect($producto->refresh()->status)->toBe('active');
});

test('al desactivar un producto no se muestra la advertencia', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo desactivado',
        'status' => 'active',
    ]);
    $talla = sizeOfProduct($producto);
    ProductVariant::factory()->for($producto)->for($talla)->create();

    expect($producto->images()->exists())->toBeFalse();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('toggleActive', $producto->id)
        ->assertDispatched('toast', tone: 'success', message: 'Producto «Polo desactivado» desactivado correctamente.')
        ->assertNotDispatched('toast', tone: 'warning');

    expect($producto->refresh()->status)->toBe('inactive');
});
