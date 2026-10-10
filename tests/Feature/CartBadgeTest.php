<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * La insignia del carrito del encabezado en TODAS las páginas de la tienda.
 *
 * El layout es un componente anónimo cuyo prop `cartCount` vale 0 por defecto.
 * Un view composer sobre `components.store.layout` calcula el número solo cuando
 * la página no lo pasó (Mi cuenta y las pantallas de acceso), respeta el valor de
 * las páginas que sí lo calculan (portada, listados, ficha y carrito) y no añade
 * consultas: como mucho una por petición.
 */

/**
 * El catálogo mínimo (categoría, talla y color) para levantar variantes.
 *
 * @return array{categoria: Category, talla: Size, negro: Color}
 */
function catalogoInsignia(): array
{
    $categoria = numberedCategory('INS', 'Insignia');
    $talla = Size::query()->create([
        'category_id' => $categoria->getKey(),
        'name' => 'M',
        'order' => 1,
        'is_active' => true,
    ]);
    $negro = Color::factory()->create(['name' => 'Negro', 'hex' => '#000000']);

    return compact('categoria', 'talla', 'negro');
}

/**
 * Una variante en el catálogo dado, de un producto visible por defecto.
 */
function varianteInsignia(array $catalogo, int $stock = 10): ProductVariant
{
    $producto = Product::factory()->create([
        'category_id' => $catalogo['categoria']->getKey(),
        'status' => 'active',
    ]);

    return ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'size_id' => $catalogo['talla']->getKey(),
        'is_active' => true,
        'stock' => $stock,
    ]);
}

/**
 * Un cliente de la tienda con el rol que exige /cuenta.
 */
function clienteInsignia(): User
{
    $user = User::factory()->unverified()->create();
    $user->assignRole(Role::findOrCreate('cliente', 'web'));

    return $user;
}

/**
 * Las consultas registradas que tocan la tabla `cart_items` (el agregado del contador).
 */
function consultasDeCarrito(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'cart_items'))
        ->count();
}

test('tras registrarse, la primera pantalla (Mi cuenta) ya trae el número del carrito', function () {
    $catalogo = catalogoInsignia();
    $primera = varianteInsignia($catalogo);
    $segunda = varianteInsignia($catalogo);

    // El visitante agrega dos prendas distintas con su cookie de carrito.
    $this->postJson('/carrito/agregar', ['variant_id' => $primera->getKey(), 'quantity' => 1])->assertOk();
    $token = Cart::query()->whereNull('user_id')->sole()->token;
    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->postJson('/carrito/agregar', ['variant_id' => $segunda->getKey(), 'quantity' => 1])
        ->assertOk();

    $this->withCookie(Cart::TOKEN_COOKIE, $token)->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'insignia@merge.test',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'access_modal' => 'register',
    ])->assertRedirect(route('account.index'));

    // La primera pantalla real que ve el cliente ya pinta la insignia con el número.
    $this->get(route('account.index'))
        ->assertOk()
        ->assertSee('cartBadge({ count: 2', false)
        ->assertSee('aria-label="Carrito (2 unidades)"', false);
});

test('un cliente con unidades ve su número en Mi cuenta', function () {
    $usuario = clienteInsignia();
    $variante = varianteInsignia(catalogoInsignia());

    $carrito = Cart::factory()->account($usuario)->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 3,
    ]);

    $this->actingAs($usuario)
        ->get(route('account.index'))
        ->assertOk()
        ->assertSee('cartBadge({ count: 3', false)
        ->assertSee('aria-label="Carrito (3 unidades)"', false);
});

test('un visitante sin carrito ve la insignia en cero y no nace carrito ni cookie', function () {
    foreach (['/login', '/register'] as $ruta) {
        $this->get($ruta)
            ->assertOk()
            ->assertSee('cartBadge({ count: 0', false)
            ->assertCookieMissing(Cart::TOKEN_COOKIE);
    }

    // /cuenta exige sesión: un visitante no llega a ver la insignia.
    $this->get(route('account.index'))->assertRedirect(route('login'));

    expect(Cart::count())->toBe(0);
});

test('cada pantalla de acceso y Mi cuenta pintan el número real', function () {
    $variante = varianteInsignia(catalogoInsignia());

    // Pantallas de invitado: el número sale del carrito de visitante.
    $visitante = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 4,
    ]);

    foreach (['/login', '/register', '/forgot-password', '/reset-password/token-de-prueba'] as $ruta) {
        $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
            ->get($ruta)
            ->assertOk()
            ->assertSee('cartBadge({ count: 4', false);
    }

    // Pantallas que exigen sesión: el número sale del carrito de la cuenta.
    $usuario = clienteInsignia();
    $cuenta = Cart::factory()->account($usuario)->create();
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 4,
    ]);

    foreach (['/confirm-password', '/verify-email', route('account.index')] as $ruta) {
        $this->actingAs($usuario)
            ->get($ruta)
            ->assertOk()
            ->assertSee('cartBadge({ count: 4', false);
    }
});

test('el contador cuesta como mucho una consulta por petición', function () {
    $variante = varianteInsignia(catalogoInsignia());

    $visitante = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 2,
    ]);

    DB::enableQueryLog();

    // 1) Pantalla de acceso de un visitante SIN carrito: ninguna consulta de carrito.
    DB::flushQueryLog();
    $this->get('/login')->assertOk();
    $sinCarrito = consultasDeCarrito();

    // 2) La misma pantalla CON carrito de visitante: exactamente una (la del composer).
    DB::flushQueryLog();
    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)->get('/login')->assertOk();
    $conCarrito = consultasDeCarrito();

    expect($sinCarrito)->toBe(0)
        ->and($conCarrito)->toBe(1);

    // 3) La ficha ya pasa `cartCount`: el composer no añade ninguna consulta extra.
    DB::flushQueryLog();
    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->get('/producto/'.$variante->product->slug)
        ->assertOk();
    $ficha = consultasDeCarrito();

    expect($ficha)->toBe(1);

    // 4) Mi cuenta con carrito de la cuenta: exactamente una.
    $usuario = clienteInsignia();
    $cuenta = Cart::factory()->account($usuario)->create();
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 2,
    ]);

    DB::flushQueryLog();
    $this->actingAs($usuario)->get(route('account.index'))->assertOk();
    $miCuenta = consultasDeCarrito();

    expect($miCuenta)->toBe(1);

    DB::disableQueryLog();
});
