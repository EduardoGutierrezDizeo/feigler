<?php

use App\Http\Controllers\Storefront\CartController;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Services\Storefront\CartService;
use App\Support\StorefrontPreview;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * El botón «Agregar al carrito» de la ficha: el POST que recibe y el contador
 * del encabezado que se pinta en cada página.
 *
 * La validación vive en el servidor —la variante tiene que existir, estar
 * activa y pertenecer a un producto visible—, la cookie del visitante viaja en
 * el Set-Cookie de la misma respuesta, y el contador se lee con una sola
 * consulta que nunca crea carritos.
 */

/**
 * El catálogo mínimo (categoría, talla y color) para levantar variantes.
 *
 * @return array{categoria: Category, talla: Size, negro: Color}
 */
function catalogoDeCarrito(): array
{
    $categoria = numberedCategory('CRT', 'Carrito');
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
function varianteDeCarrito(array $catalogo, int $stock = 10, bool $activa = true, array $productAttributes = []): ProductVariant
{
    $producto = Product::factory()->create(array_merge([
        'category_id' => $catalogo['categoria']->getKey(),
        'status' => 'active',
    ], $productAttributes));

    return ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'size_id' => $catalogo['talla']->getKey(),
        'is_active' => $activa,
        'stock' => $stock,
    ]);
}

test('un visitante agrega desde la ficha y la respuesta manda la cookie cart_token', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    $response = $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', CartController::MESSAGE_ADDED)
        ->assertJsonPath('count', 1)
        ->assertJsonPath('result.added', 1);

    $carrito = Cart::whereNull('user_id')->first();

    expect($carrito)->not->toBeNull()
        ->and($carrito->items()->first()->quantity)->toBe(1);

    $response->assertCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->assertCookieNotExpired(Cart::TOKEN_COOKIE);
});

test('una segunda petición con la misma cookie ve el mismo carrito: sobrevive a la recarga', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])->assertOk();
    $token = Cart::whereNull('user_id')->sole()->token;

    $segunda = $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()]);

    $segunda->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('result.added', 1);

    $carrito = Cart::where('token', $token)->withCount('items')->sole();

    expect($carrito->items_count)->toBe(1)
        ->and($carrito->items()->first()->quantity)->toBe(2);

    // La recarga (una ficha de nuevo) muestra el contador actualizado.
    $this->withCookie(Cart::TOKEN_COOKIE, $token)
        ->get('/producto/'.$variante->product->slug)
        ->assertOk()
        ->assertSee('Carrito · 2');
});

test('navegar la tienda sin agregar no crea carrito ni manda cookie, y el contador queda en cero', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    $this->get('/producto/'.$variante->product->slug)->assertOk();
    $this->get('/tienda')->assertOk();

    expect(Cart::count())->toBe(0);

    $ficha = $this->get('/producto/'.$variante->product->slug);

    $ficha->assertOk()
        ->assertCookieMissing(Cart::TOKEN_COOKIE)
        ->assertDontSee('Carrito · 1')
        ->assertSee('>Carrito</span>', false);
});

test('un cliente autenticado agrega al carrito de su cuenta y no recibe cookie', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 5);
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertCookieMissing(Cart::TOKEN_COOKIE);

    $carrito = Cart::where('user_id', $usuario->getKey())->sole();

    expect($carrito->token)->toBeNull()
        ->and($carrito->items()->sum('quantity'))->toBe(1)
        ->and(Cart::whereNull('user_id')->count())->toBe(0);
});

test('una petición sin variante se rechaza antes de tocar nada', function () {
    $this->postJson('/carrito/agregar', [])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', CartController::MESSAGE_UNAVAILABLE);

    expect(Cart::count())->toBe(0);
});

test('una variante con stock 0 no agrega nada y no estrena carrito', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 0);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', CartController::MESSAGE_OUT_OF_STOCK);

    expect(Cart::count())->toBe(0);
});

test('agregar de más topa la línea al stock y lo avisa con el contador intacto', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 1);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertOk()
        ->assertJsonPath('message', CartController::MESSAGE_ADDED)
        ->assertJsonPath('count', 1);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('count', 1)
        ->assertJsonPath('message', sprintf(CartController::MESSAGE_CAPPED_ONE, 1));
});

test('cuando queda una sola unidad, el mensaje de tope está en singular', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 1);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])->assertOk();

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('message', 'Solo queda 1 unidad de esta talla; tu carrito ya tiene 1.');
});

test('cuando quedan varias unidades, el mensaje de tope está en plural', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 3);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])->assertOk();
    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])->assertOk();
    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])->assertOk();

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertOk()
        ->assertJsonPath('count', 3)
        ->assertJsonPath('message', 'Solo quedan 3 unidades de esta talla; tu carrito ya tiene 3.');
});

test('un visitante agrega varias unidades de una vez y el contador y el carrito las reflejan', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => 4])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Agregaste 4 unidades al carrito.')
        ->assertJsonPath('count', 4)
        ->assertJsonPath('result.added', 4);

    $carrito = Cart::whereNull('user_id')->sole();

    expect($carrito->items()->first()->quantity)->toBe(4);
});

test('agregar varias cuando el carrito ya tiene unidades suma y topa al stock avisando en plural', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 5);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => 4])
        ->assertOk()
        ->assertJsonPath('count', 4);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('count', 5)
        ->assertJsonPath('result.added', 1)
        ->assertJsonPath('message', 'Solo quedan 5 unidades de esta talla; tu carrito ya tiene 5.');
});

test('agregar varias de la última unidad se topa y lo avisa en singular', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 1);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => 5])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('result.added', 1)
        ->assertJsonPath('message', 'Solo queda 1 unidad de esta talla; tu carrito ya tiene 1.');
});

test('una cantidad inválida se rechaza sin crear carrito ni cookie', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    foreach ([0, -1, 'abc', '', 2.5] as $invalida) {
        $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => $invalida])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', CartController::MESSAGE_INVALID_QUANTITY)
            ->assertCookieMissing(Cart::TOKEN_COOKIE);
    }

    expect(Cart::count())->toBe(0);
});

test('una cantidad enorme se topa al stock disponible', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 3);

    $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey(), 'quantity' => 9999])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('count', 3)
        ->assertJsonPath('result.added', 3)
        ->assertJsonPath('message', 'Solo quedan 3 unidades de esta talla; tu carrito ya tiene 3.');
});

test('una variante inexistente, inactiva o de un producto apagado se rechaza con seguridad', function () {
    $catalogo = catalogoDeCarrito();
    $inactiva = varianteDeCarrito($catalogo, stock: 5, activa: false);
    $apagado = varianteDeCarrito($catalogo, stock: 5, productAttributes: ['status' => 'inactive']);

    foreach ([999999, $inactiva->getKey(), $apagado->getKey()] as $variantId) {
        $this->postJson('/carrito/agregar', ['variant_id' => $variantId])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', CartController::MESSAGE_UNAVAILABLE);
    }

    expect(Cart::count())->toBe(0);
});

test('el contador aparece con el número en la ficha y en el listado', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 2,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/producto/'.$variante->product->slug)
        ->assertOk()
        ->assertSee('Carrito · 2');

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/tienda')
        ->assertOk()
        ->assertSee('Carrito · 2');
});

test('el contador aparece con el número también en la portada', function () {
    // La ruta / solo sirve la tienda real en local (routes/web.php), igual que en
    // HomePageTest: se cambia el entorno sobre la instancia ya arrancada.
    $this->app->instance('env', 'local');

    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 2,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/')
        ->assertOk()
        ->assertSee('Carrito · 2');
});

test('la cookie cart_token viaja cifrada, nunca con el uuid en claro', function () {
    $variante = varianteDeCarrito(catalogoDeCarrito(), stock: 10);

    $respuesta = $this->postJson('/carrito/agregar', ['variant_id' => $variante->getKey()])
        ->assertCookie(Cart::TOKEN_COOKIE);

    $token = Cart::whereNull('user_id')->sole()->token;

    // La cookie del visitante no está en las excepciones de cifrado: lo que viaja
    // en el Set-Cookie es el valor cifrado, que no puede coincidir con el token.
    $cookie = collect($respuesta->baseResponse->headers->getCookies())
        ->first(fn (Cookie $c): bool => $c->getName() === Cart::TOKEN_COOKIE);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->not->toBe($token);
});

test('las vistas previas /_vista/* siguen sirviendo con el contrato actualizado', function () {
    // En el entorno de pruebas las rutas /_vista no se registran (routes/web.php
    // las exige solo en local), así que se comprueba que las tres maquetas rinden
    // con el contrato nuevo, incluido el contador del encabezado.
    foreach ([
        'storefront.home' => StorefrontPreview::home(),
        'storefront.product' => StorefrontPreview::product(),
        'storefront.section' => StorefrontPreview::section(),
    ] as $view => $data) {
        $html = view($view, $data)->render();

        expect($html)->toBeString()
            ->and($html)->toContain('x-data="cartBadge({ count: 2 })"')
            ->and($html)->toContain('Carrito · 2');
    }
});

test('CartService se resuelve como scoped: misma instancia por petición y nueva después', function () {
    expect(app(CartService::class)->queuedCookie())->toBeNull();

    $primera = app(CartService::class);

    expect(app(CartService::class))->toBe($primera);

    app()->forgetScopedInstances();

    expect(app(CartService::class))->not->toBe($primera);
});
