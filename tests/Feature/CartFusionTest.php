<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Services\Storefront\CartService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * La fusión del carrito al entrar: cuando un cliente inicia sesión o se
 * registra, el carrito que dejó como visitante pasa a su cuenta. Solo actúa
 * sobre clientes activos y solo con el carrito de visitante que la cookie
 * señala; el personal interno y las cuentas inactivas no tocan nada. Si ambos
 * carritos tienen la misma variante, las cantidades se suman con tope al stock,
 * y cuando hubo tope la sesión guarda un aviso que la página del carrito
 * muestra una sola vez.
 */

/**
 * El catálogo mínimo (categoría, talla y color) para levantar variantes.
 *
 * @return array{categoria: Category, talla: Size, negro: Color}
 */
function catalogoFusion(): array
{
    $categoria = numberedCategory('FUS', 'Fusión');
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
function varianteFusion(array $catalogo, int $stock = 10, bool $activa = true): ProductVariant
{
    $producto = Product::factory()->create([
        'category_id' => $catalogo['categoria']->getKey(),
        'status' => 'active',
    ]);

    return ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'size_id' => $catalogo['talla']->getKey(),
        'is_active' => $activa,
        'stock' => $stock,
    ]);
}

/**
 * Un cliente de la tienda, con los roles sembrados y la contraseña «password».
 */
function fusionCliente(): User
{
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);
    $user->assignRole('cliente');

    return $user;
}

/**
 * Un carrito de visitante con una línea de la variante dada.
 */
function carritoFusionado(ProductVariant $variante, int $cantidad): Cart
{
    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => $cantidad,
    ]);

    return $carrito;
}

/**
 * ¿La última respuesta manda la cookie cart_token ya caducada?
 */
function cookieFusionOlvidada($respuesta): ?Cookie
{
    $cookie = collect($respuesta->baseResponse->headers->getCookies())
        ->first(fn (Cookie $c): bool => $c->getName() === Cart::TOKEN_COOKIE);

    return $cookie !== null && $cookie->isCleared() ? $cookie : null;
}

test('al iniciar sesión, el carrito del visitante queda en manos de la cuenta', function () {
    $catalogo = catalogoFusion();
    $variante = varianteFusion($catalogo, stock: 10);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($variante, 2);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($cliente);

    // El mismo carrito pasa a la cuenta: sin token y con su línea intacta.
    $cuenta = Cart::query()->whereKey($visitante->getKey())->sole();

    expect($cuenta->user_id)->toBe($cliente->getKey())
        ->and($cuenta->token)->toBeNull()
        ->and($cuenta->items()->first()->quantity)->toBe(2)
        ->and(Cart::query()->whereNull('user_id')->count())->toBe(0);
});

test('la cookie del visitante se olvida cuando su carrito pasa a la cuenta', function () {
    $variante = varianteFusion(catalogoFusion(), stock: 10);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($variante, 1);

    $respuesta = $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password']);

    $respuesta->assertRedirect();

    expect(cookieFusionOlvidada($respuesta))->not->toBeNull();
});

test('con los dos carritos, la misma variante se suma con tope y las demás se conservan', function () {
    $catalogo = catalogoFusion();
    $compartida = varianteFusion($catalogo, stock: 4);
    $soloVisitante = varianteFusion($catalogo, stock: 5);
    $soloCuenta = varianteFusion($catalogo, stock: 5);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($compartida, 2);
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => $soloVisitante->getKey(),
        'quantity' => 1,
    ]);

    $cuenta = Cart::factory()->account($cliente)->create();
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $compartida->getKey(),
        'quantity' => 3,
    ]);
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $soloCuenta->getKey(),
        'quantity' => 2,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    $final = $cuenta->refresh();

    expect($final->items()->where('product_variant_id', $compartida->getKey())->value('quantity'))->toBe(4)
        ->and($final->items()->where('product_variant_id', $soloVisitante->getKey())->value('quantity'))->toBe(1)
        ->and($final->items()->where('product_variant_id', $soloCuenta->getKey())->value('quantity'))->toBe(2)
        ->and($final->items()->count())->toBe(3)
        ->and(Cart::query()->whereKey($visitante->getKey())->exists())->toBeFalse();
});

test('el aviso de fusión se ve una sola vez en el carrito, solo cuando hubo tope', function () {
    $catalogo = catalogoFusion();
    $compartida = varianteFusion($catalogo, stock: 4);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($compartida, 2);

    $cuenta = Cart::factory()->account($cliente)->create();
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $compartida->getKey(),
        'quantity' => 3,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    $this->get('/carrito')
        ->assertOk()
        ->assertSee(CartService::MESSAGE_FUSION_CAPPED);

    $this->get('/carrito')
        ->assertOk()
        ->assertDontSee(CartService::MESSAGE_FUSION_CAPPED);
});

test('sin tope al stock, el aviso de fusión no aparece', function () {
    $catalogo = catalogoFusion();
    $variante = varianteFusion($catalogo, stock: 10);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($variante, 2);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    $this->get('/carrito')
        ->assertOk()
        ->assertDontSee(CartService::MESSAGE_FUSION_CAPPED);
});

test('un cliente se registra con su carrito de visitante y se queda con él', function () {
    $variante = varianteFusion(catalogoFusion(), stock: 10);

    // El carrito del visitante ya existía antes de registrarse.
    $visitante = carritoFusionado($variante, 3);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/register', [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'email' => 'ana@fusion.test',
            'phone' => '3001234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => '1',
        ])
        ->assertRedirect(route('account.index'));

    $usuario = User::query()->where('email', 'ana@fusion.test')->sole();

    expect(Cart::query()->whereKey($visitante->getKey())->sole())
        ->user_id->toBe($usuario->getKey())
        ->and($visitante->refresh()->token)->toBeNull()
        ->and($visitante->items()->first()->quantity)->toBe(3);

    $this->assertAuthenticatedAs($usuario);
});

test('sin carrito de visitante, o sin cookie, el login no crea ni toca carritos', function () {
    $cliente = fusionCliente();

    $this->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect()
        ->assertCookieMissing(Cart::TOKEN_COOKIE);

    expect(Cart::count())->toBe(0);
});

test('una cookie que no abre ningún carrito se ignora y no se toca', function () {
    $cliente = fusionCliente();

    $this->withCookie(Cart::TOKEN_COOKIE, 'una-firma-que-no-existe')
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    expect(Cart::count())->toBe(0);
});

test('un carrito de visitante vacío no se reclama y la cuenta no estrena carrito', function () {
    $cliente = fusionCliente();
    $vacio = Cart::factory()->create();

    $this->withCookie(Cart::TOKEN_COOKIE, $vacio->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    expect($vacio->refresh()->user_id)->toBeNull()
        ->and($vacio->refresh()->token)->toBe($vacio->token)
        ->and(Cart::query()->where('user_id', $cliente->getKey())->count())->toBe(0);
});

test('un token que apunta a un carrito ya de cuenta se ignora en la fusión', function () {
    $cliente = fusionCliente();

    // Un carrito que ya está en manos de una cuenta, señalado por una cookie vieja.
    $cuenta = Cart::factory()->account($cliente)->create();
    $cuenta->forceFill(['token' => 'token-viejo'])->save();

    $this->withCookie(Cart::TOKEN_COOKIE, 'token-viejo')
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    expect($cuenta->refresh()->token)->toBe('token-viejo')
        ->and(Cart::count())->toBe(1);
});

test('el personal interno no fusiona: el carrito y la cookie del visitante quedan intactos', function () {
    test()->seed(RoleSeeder::class);
    $admin = User::factory()->create(['password' => 'password', 'is_active' => true]);
    $admin->assignRole('admin');

    $variante = varianteFusion(catalogoFusion(), stock: 10);
    $visitante = carritoFusionado($variante, 2);
    $token = $visitante->token;

    $respuesta = $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->post('/login', ['email' => $admin->email, 'password' => 'password']);

    $respuesta->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($admin);

    expect($visitante->refresh()->user_id)->toBeNull()
        ->and($visitante->refresh()->token)->toBe($token)
        ->and(Cart::query()->where('user_id', $admin->getKey())->count())->toBe(0)
        ->and($respuesta)->assertCookieMissing(Cart::TOKEN_COOKIE);
});

test('un cliente inactivo no fusiona y la cookie no se toca', function () {
    test()->seed(RoleSeeder::class);
    $inactivo = User::factory()->create(['password' => 'password', 'is_active' => false]);
    $inactivo->assignRole('cliente');

    $variante = varianteFusion(catalogoFusion(), stock: 10);
    $visitante = carritoFusionado($variante, 2);
    $token = $visitante->token;

    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->post('/login', ['email' => $inactivo->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    expect($visitante->refresh()->user_id)->toBeNull()
        ->and($visitante->refresh()->token)->toBe($token);
});

test('que el Login se dispare de nuevo no duplica líneas ni falla', function () {
    $catalogo = catalogoFusion();
    $variante = varianteFusion($catalogo, stock: 10);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($variante, 2);
    $token = $visitante->token;

    // Primera entrada: el carrito pasa a la cuenta.
    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    // La sesión se cierra y se vuelve a entrar, como en un ciclo de «recordarme»,
    // con la misma cookie vieja que ya no abre ningún carrito de visitante.
    $this->post('/logout')->assertRedirect();

    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    expect(Cart::query()->where('user_id', $cliente->getKey())->withCount('items')->sole()->items_count)->toBe(1)
        ->and(CartItem::query()->where('product_variant_id', $variante->getKey())->sole()->quantity)->toBe(2)
        ->and(Cart::query()->whereNull('user_id')->count())->toBe(0);
});

test('una línea no disponible o sin stock se conserva sumada en la fusión', function () {
    $catalogo = catalogoFusion();
    $agotada = varianteFusion($catalogo, stock: 0);
    $cliente = fusionCliente();

    $visitante = carritoFusionado($agotada, 2);

    $cuenta = Cart::factory()->account($cliente)->create();
    CartItem::factory()->create([
        'cart_id' => $cuenta->getKey(),
        'product_variant_id' => $agotada->getKey(),
        'quantity' => 1,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    // Sin stock no hay a qué topar: se suman las unidades y la línea queda como está.
    expect($cuenta->refresh()->items()->where('product_variant_id', $agotada->getKey())->value('quantity'))->toBe(3);
});

test('un fallo al fusionar se registra en el log y el inicio de sesión sigue adelante', function () {
    $cliente = fusionCliente();
    $visitante = carritoFusionado(varianteFusion(catalogoFusion(), stock: 10), 1);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'fallo forzado en la fusión'));

    $servicio = Mockery::mock(CartService::class);
    $servicio->shouldReceive('mergeVisitorCartIntoAccount')
        ->once()
        ->andThrow(new RuntimeException('fallo forzado en la fusión'));

    app()->instance(CartService::class, $servicio);

    $this->withCookie(Cart::TOKEN_COOKIE, $visitante->token)
        ->post('/login', ['email' => $cliente->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($cliente);

    // Los dos carritos quedan como estaban: no se fusionó nada a medias.
    expect($visitante->refresh()->user_id)->toBeNull();
});

test('la verificación del correo es solo para pagar: el visitante se registra sin verificar y conserva su carrito', function () {
    $catalogo = catalogoFusion();
    $primera = varianteFusion($catalogo, stock: 10);
    $segunda = varianteFusion($catalogo, stock: 10);

    // El visitante agrega dos prendas distintas por el endpoint real.
    $this->postJson('/carrito/agregar', ['variant_id' => $primera->getKey(), 'quantity' => 1])
        ->assertOk();

    $visitante = Cart::query()->whereNull('user_id')->sole();
    $token = $visitante->token;

    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->postJson('/carrito/agregar', ['variant_id' => $segunda->getKey(), 'quantity' => 1])
        ->assertOk();

    expect($visitante->items()->count())->toBe(2);

    // Se registra por el mismo endpoint del modal, sin verificar el correo.
    $respuesta = $this->withCookie(Cart::TOKEN_COOKIE, $token)->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana-sin-verificar@fusion.test',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'access_modal' => 'register',
    ]);

    $respuesta->assertRedirect(route('account.index'));

    $usuario = User::query()->where('email', 'ana-sin-verificar@fusion.test')->sole();

    // El correo sigue sin verificar: no es requisito para conservar el carrito.
    expect($usuario->email_verified_at)->toBeNull()
        ->and($usuario->hasVerifiedEmail())->toBeFalse();

    // El carrito del visitante pasó a la cuenta con sus dos líneas.
    $cuenta = Cart::query()->whereKey($visitante->getKey())->sole();

    expect($cuenta->user_id)->toBe($usuario->getKey())
        ->and($cuenta->token)->toBeNull()
        ->and($cuenta->items()->count())->toBe(2)
        ->and(Cart::query()->whereNull('user_id')->count())->toBe(0);

    // La cookie del visitante se olvida en la respuesta.
    expect(cookieFusionOlvidada($respuesta))->not->toBeNull();

    // El contador marca las dos unidades, ya autenticado.
    expect(app(CartService::class)->currentCount())->toBe(2);

    // La página del carrito responde 200 y pinta las dos líneas.
    $this->get('/carrito')
        ->assertOk()
        ->assertSee($primera->product->name, false)
        ->assertSee($segunda->product->name, false)
        ->assertSee('aria-label="Carrito (2 unidades)"', false);
});
