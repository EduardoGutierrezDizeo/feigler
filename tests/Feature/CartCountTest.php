<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;

/*
 * El contador del encabezado: `GET /carrito/contador` devuelve solo las unidades
 * del carrito actual para que la insignia se refresque sin recargar (al volver
 * desde la caché del navegador). Nunca crea carrito ni cookie, y la respuesta no
 * se cachea.
 */

test('el contador devuelve las unidades del carrito de un visitante con cookie', function () {
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'quantity' => 3]);
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'quantity' => 4]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->getJson('/carrito/contador')
        ->assertOk()
        ->assertJson(['count' => 7])
        ->assertCookieMissing(Cart::TOKEN_COOKIE);
});

test('un visitante sin carrito recibe 0 sin que nazca carrito ni cookie', function () {
    $this->getJson('/carrito/contador')
        ->assertOk()
        ->assertJson(['count' => 0])
        ->assertCookieMissing(Cart::TOKEN_COOKIE);

    expect(Cart::count())->toBe(0);
});

test('un cliente autenticado recibe el contador de su cuenta', function () {
    $usuario = User::factory()->create();
    $carrito = Cart::factory()->account($usuario)->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'quantity' => 5]);

    $this->actingAs($usuario)
        ->getJson('/carrito/contador')
        ->assertOk()
        ->assertJson(['count' => 5]);
});

test('un cliente autenticado sin carrito recibe 0 sin que se cree uno', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->getJson('/carrito/contador')
        ->assertOk()
        ->assertJson(['count' => 0]);

    expect(Cart::count())->toBe(0);
});

test('el contador no se cachea', function () {
    $respuesta = $this->getJson('/carrito/contador');

    $respuesta->assertOk();

    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
});

test('el contador corta con 429 pasado el límite por minuto', function () {
    foreach (range(1, 30) as $ignorado) {
        $this->getJson('/carrito/contador')->assertOk();
    }

    $this->getJson('/carrito/contador')->assertStatus(429);
});
