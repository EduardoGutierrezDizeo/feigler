<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;

test('un carrito pertenece a una cuenta o a un visitante, nunca a ambos', function () {
    $usuario = User::factory()->create();

    $visitante = Cart::factory()->create();

    expect($visitante->user_id)->toBeNull()
        ->and($visitante->token)->not->toBeNull()
        ->and($visitante->belongsToAccount())->toBeFalse();

    $cuenta = Cart::factory()->account($usuario)->create();

    expect($cuenta->user_id)->toBe($usuario->getKey())
        ->and($cuenta->token)->toBeNull()
        ->and($cuenta->belongsToAccount())->toBeTrue()
        ->and($usuario->cart()->is($cuenta))->toBeTrue();
});

test('una línea de carrito enlaza su carrito y su variante', function () {
    $variante = ProductVariant::factory()->create();
    $linea = CartItem::factory()->for($variante, 'productVariant')->create();

    expect($linea->cart_id)->not->toBeNull()
        ->and($linea->product_variant_id)->toBe($variante->getKey())
        ->and($linea->productVariant->is($variante))->toBeTrue()
        ->and($linea->cart->items()->count())->toBe(1);
});

test('una variante no puede repetirse en el mismo carrito', function () {
    $linea = CartItem::factory()->create();

    $this->expectException(QueryException::class);

    CartItem::factory()->create([
        'cart_id' => $linea->cart_id,
        'product_variant_id' => $linea->product_variant_id,
    ]);
});
