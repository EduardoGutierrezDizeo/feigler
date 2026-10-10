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
use Illuminate\Support\Facades\DB;

/*
 * La página y las acciones del carrito: GET /carrito pinta las líneas con su
 * estado, y PATCH/DELETE /carrito/lineas/{id} cambian la cantidad o eliminan la
 * línea contra el carrito actual. Todo vuelve en JSON con el resumen fresco y el
 * contador para que la pantalla re-pinte sin recargar, el subtotal se lee del
 * precio en vivo de cada variante y una línea que no existe o que es de otro
 * carrito responde el mismo 404 genérico.
 */

/**
 * El catálogo mínimo (categoría, talla y color) para levantar variantes.
 *
 * Se usa un prefijo propio porque cada archivo de tests define sus helpers como
 * funciones globales y los nombres no pueden chocar entre archivos.
 *
 * @return array{categoria: Category, talla: Size, negro: Color}
 */
function catalogoPaginaCarrito(): array
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
function variantePaginaCarrito(array $catalogo, int $stock = 10, bool $activa = true, int $precio = 150000, array $producto = []): ProductVariant
{
    $product = Product::factory()->create(array_merge([
        'category_id' => $catalogo['categoria']->getKey(),
        'status' => 'active',
        'base_price' => $precio,
    ], $producto));

    return ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'size_id' => $catalogo['talla']->getKey(),
        'is_active' => $activa,
        'stock' => $stock,
    ]);
}

test('un visitante sin carrito ve la página vacía y no crea carrito ni manda cookie', function () {
    $respuesta = $this->get('/carrito');

    $respuesta->assertOk()
        ->assertSee('Tu carrito está vacío.')
        ->assertSee('Ir a la tienda')
        ->assertSee('Ver novedades')
        ->assertSee('>Carrito</span>', false)
        ->assertCookieMissing(Cart::TOKEN_COOKIE);

    expect(Cart::count())->toBe(0);
});

test('la página con líneas muestra sus prendas, precios, total y contador de un visitante', function () {
    $catalogo = catalogoPaginaCarrito();
    $camisa = variantePaginaCarrito($catalogo, stock: 5, precio: 90000);
    $pantalon = variantePaginaCarrito($catalogo, stock: 3, precio: 130000);

    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $camisa->getKey(), 'quantity' => 2]);
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $pantalon->getKey(), 'quantity' => 1]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee($camisa->product->name, false)
        ->assertSee($pantalon->product->name, false)
        ->assertSee('Negro', false)
        ->assertSee('$180.000', false)
        ->assertSee('$130.000', false)
        ->assertSee('$310.000', false)
        ->assertSee('3 unidades', false)
        ->assertSee('Carrito · 3');
});

test('un cliente autenticado ve el carrito de su cuenta', function () {
    $usuario = User::factory()->create();
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10);

    $carrito = Cart::factory()->account($usuario)->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 3]);

    $this->actingAs($usuario)
        ->get('/carrito')
        ->assertOk()
        ->assertSee($variante->product->name, false)
        ->assertSee('Carrito · 3')
        ->assertCookieMissing(Cart::TOKEN_COOKIE);
});

test('subir la cantidad de una línea actualiza la base, el resumen y el contador', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 1]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => 4])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', '')
        ->assertJsonPath('count', 4)
        ->assertJsonPath('summary.subtotal', 200000)
        ->assertJsonPath('summary.items.0.quantity', 4)
        ->assertJsonPath('summary.items.0.unit_price', 50000);

    expect($linea->fresh()->quantity)->toBe(4);
});

test('bajar la cantidad actualiza el subtotal y el resumen', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 3]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => 1])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('summary.subtotal', 50000)
        ->assertJsonPath('summary.items.0.quantity', 1);

    expect($linea->fresh()->quantity)->toBe(1);
});

test('fijar más de lo que queda topa la línea y avisa en singular', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 1, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 1]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => 8])
        ->assertOk()
        ->assertJsonPath('message', CartController::MESSAGE_CAPPED_SET_ONE)
        ->assertJsonPath('count', 1)
        ->assertJsonPath('summary.items.0.quantity', 1);

    expect($linea->fresh()->quantity)->toBe(1);
});

test('fijar más de lo que queda topa la línea y avisa en plural', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 3, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => 9])
        ->assertOk()
        ->assertJsonPath('message', sprintf(CartController::MESSAGE_CAPPED_SET_MANY, 3, 3))
        ->assertJsonPath('count', 3)
        ->assertJsonPath('summary.items.0.quantity', 3);

    expect($linea->fresh()->quantity)->toBe(3);
});

test('eliminar una línea la saca del carrito y refresca el resumen', function () {
    $catalogo = catalogoPaginaCarrito();
    $primera = variantePaginaCarrito($catalogo, precio: 40000);
    $segunda = variantePaginaCarrito($catalogo, precio: 60000);
    $carrito = Cart::factory()->create();
    $lineaUno = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $primera->getKey(), 'quantity' => 2]);
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $segunda->getKey(), 'quantity' => 1]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->deleteJson('/carrito/lineas/'.$lineaUno->getKey())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('count', 1)
        ->assertJsonPath('summary.subtotal', 60000)
        ->assertJsonPath('summary.items.0.id', $segunda->getKey())
        ->assertJsonPath('summary.items.0.quantity', 1);

    expect(CartItem::find($lineaUno->getKey()))->toBeNull();
});

test('eliminar la última línea deja el carrito vacío en el resumen', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->deleteJson('/carrito/lineas/'.$linea->getKey())
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('summary.items', [])
        ->assertJsonPath('summary.subtotal', 0);
});

test('una línea inexistente y una de otro carrito responden el mismo 404 genérico', function () {
    $catalogo = catalogoPaginaCarrito();
    $variante = variantePaginaCarrito($catalogo, stock: 10);
    $mio = Cart::factory()->create();
    $ajeno = Cart::factory()->create();
    $lineaAgena = CartItem::factory()->create(['cart_id' => $ajeno->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $mio->token)
        ->deleteJson('/carrito/lineas/999999')
        ->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', CartController::MESSAGE_ITEM_NOT_FOUND);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $mio->token)
        ->patchJson('/carrito/lineas/'.$lineaAgena->getKey(), ['quantity' => 5])
        ->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', CartController::MESSAGE_ITEM_NOT_FOUND);

    expect(CartItem::find($lineaAgena->getKey()))->not->toBeNull()
        ->and(CartItem::find($lineaAgena->getKey())->quantity)->toBe(2);
});

test('las líneas con stock insuficiente avisan con el máximo disponible en singular', function () {
    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => variantePaginaCarrito(catalogoPaginaCarrito(), stock: 1)->getKey(),
        'quantity' => 2,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee(CartController::MESSAGE_INSUFFICIENT_ONE, false);
});

test('las líneas con stock insuficiente avisan con el máximo disponible en plural', function () {
    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => variantePaginaCarrito(catalogoPaginaCarrito(), stock: 3)->getKey(),
        'quantity' => 5,
    ]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee(sprintf(CartController::MESSAGE_INSUFFICIENT_MANY, 3), false);
});

test('una línea cuyo producto se agotó por completo avisa que no quedan unidades', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 0);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 1]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee(CartController::MESSAGE_SOLD_OUT, false);
});

test('una línea no disponible se muestra atenuada, sin cantidad, y no suma al subtotal', function () {
    $catalogo = catalogoPaginaCarrito();
    $disponible = variantePaginaCarrito($catalogo, stock: 5, precio: 80000);
    $apagada = variantePaginaCarrito($catalogo, stock: 5, precio: 120000, activa: false);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $disponible->getKey(), 'quantity' => 2]);
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $apagada->getKey(), 'quantity' => 3]);

    $respuesta = $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)->get('/carrito');
    $html = $respuesta->getContent();

    $respuesta->assertOk()
        ->assertSee(CartController::MESSAGE_LINE_UNAVAILABLE, false)
        ->assertSee('$160.000', false)
        ->assertDontSee('$520.000', false);

    // La apagada no suma: su subtotal ($360.000) queda en el HTML oculto por
    // x-show y el resumen muestra solo el de la disponible ($160.000 aparece en
    // la línea y en el total, dos veces).
    expect(substr_count($html, '$360.000'))->toBe(1)
        ->and(substr_count($html, '$160.000'))->toBe(2);
});

test('el subtotal se calcula sobre el precio en vivo de cada variante', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10, precio: 100000);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('$200.000', false);

    $variante->forceFill(['price_override' => 120000])->save();

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('$240.000', false)
        ->assertDontSee('$200.000', false);
});

test('una cantidad inválida se rechaza con 422 sin tocar la línea', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    foreach ([0, -1, 'abc', '', 2.5] as $cantidad) {
        $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
            ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => $cantidad])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', CartController::MESSAGE_INVALID_QUANTITY);
    }

    expect($linea->fresh()->quantity)->toBe(2);
});

test('el contador del encabezado refleja la cantidad cambiada al recargar', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 10, precio: 50000);
    $carrito = Cart::factory()->create();
    $linea = CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 1]);

    $this->withCredentials()->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->patchJson('/carrito/lineas/'.$linea->getKey(), ['quantity' => 4])
        ->assertJsonPath('count', 4);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('Carrito · 4');
});

test('el control de cantidad ofrece de uno a diez, marca la actual y deshabilita lo que supera el stock', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 3);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('value="1"', false)
        ->assertSee('value="2" selected', false)
        ->assertSee('disabled :disabled="4 > availableStock"', false)
        ->assertSee(':disabled="4 > availableStock"', false)
        ->assertSee('value="10"', false)
        ->assertDontSee('value="11"', false);
});

test('el contador del carrito baja y sube la cantidad sin pasarse del stock', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 3);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 2]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('@click="stepQuantity(-1)"', false)
        ->assertSee('@click="stepQuantity(1)"', false)
        ->assertSee(':disabled="busy || quantity <= 1"', false)
        ->assertSee(':disabled="busy || quantity >= availableStock"', false)
        ->assertSee('aria-label="Disminuir cantidad"', false)
        ->assertSee('aria-label="Aumentar cantidad"', false);
});

test('una cantidad mayor que diez se ofrece para que el valor actual aparezca', function () {
    $variante = variantePaginaCarrito(catalogoPaginaCarrito(), stock: 20);
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => $variante->getKey(), 'quantity' => 12]);

    $this->withCookie(Cart::TOKEN_COOKIE, $carrito->token)
        ->get('/carrito')
        ->assertOk()
        ->assertSee('value="12" selected', false)
        ->assertDontSee('value="13"', false);
});

test('la página del carrito hace el mismo número de consultas con una y con diez líneas', function () {
    $catalogo = catalogoPaginaCarrito();
    $carrito = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $carrito->getKey(), 'product_variant_id' => variantePaginaCarrito($catalogo)->getKey(), 'quantity' => 1]);
    $token = $carrito->token;

    $medir = function () use ($token): int {
        // Laravel no limpia las instancias `#[Scoped]` entre requests del mismo
        // proceso, así que sin este paso la segunda medición reutilizaría el
        // CartService ya resuelto y le faltaría la consulta del carrito.
        app()->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withCookie(Cart::TOKEN_COOKIE, $token)->get('/carrito')->assertOk();

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $consultas;
    };

    $conUna = $medir();

    for ($i = 0; $i < 9; $i++) {
        CartItem::factory()->create([
            'cart_id' => $carrito->getKey(),
            'product_variant_id' => variantePaginaCarrito($catalogo)->getKey(),
            'quantity' => 1,
        ]);
    }

    expect(CartItem::where('cart_id', $carrito->getKey())->count())->toBe(10);

    expect($medir())->toBe($conUna);
});
