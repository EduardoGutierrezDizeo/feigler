<?php

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a movement points at its variant, its order and the user behind it', function () {
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create();
    $user = User::factory()->create();

    $movement = InventoryMovement::factory()
        ->for($variant, 'variant')
        ->for($order)
        ->for($user)
        ->create();

    expect($movement->variant->is($variant))->toBeTrue()
        ->and($movement->order->is($order))->toBeTrue()
        ->and($movement->user->is($user))->toBeTrue()
        ->and($variant->inventoryMovements()->count())->toBe(1);
});

test('an entry adds stock and an exit takes it away', function () {
    $entrada = InventoryMovement::factory()->create()->refresh();
    $salida = InventoryMovement::factory()->salida()->create()->refresh();

    expect($entrada->type)->toBe('ajuste_entrada')
        ->and($entrada->quantity)->toBeGreaterThan(0)
        ->and($salida->type)->toBe('ajuste_salida')
        ->and($salida->quantity)->toBeLessThan(0);
});

test('a variant with an inventory movement cannot be deleted', function () {
    $variant = ProductVariant::factory()->create();
    InventoryMovement::factory()->for($variant, 'variant')->create();

    expect(fn () => $variant->delete())->toThrow(QueryException::class);

    expect($variant->exists)->toBeTrue()
        ->and($variant->inventoryMovements()->count())->toBe(1);
});
