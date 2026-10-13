<?php

use App\Actions\Products\DeleteProductVariant;
use App\Exceptions\CartItemNotOwnedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Services\Storefront\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A category with a size and a color to build sellable variants in.
 *
 * @return array{categoria: Category, talla: Size, negro: Color}
 */
function carritoCatalogo(): array
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
 * A product of the catalog that a cart can be tried against.
 */
function carritoProducto(array $catalogo, array $product = [], int $stock = 10, bool $varianteActiva = true, array $variante = []): Product
{
    $product = Product::factory()->create(array_merge([
        'category_id' => $catalogo['categoria']->getKey(),
        'base_price' => 150000,
    ], $product));

    ProductVariant::factory()->create(array_merge([
        'product_id' => $product->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'size_id' => $catalogo['talla']->getKey(),
        'is_active' => $varianteActiva,
        'stock' => $stock,
    ], $variante));

    return $product;
}

/**
 * A CartService that sees this user and this cookie on its request.
 */
function carritoDe(?User $user = null, ?string $token = null): CartService
{
    $request = Request::create('/carrito', 'GET');

    if ($token !== null) {
        $request->cookies->set(Cart::TOKEN_COOKIE, $token);
    }

    if ($user !== null) {
        $request->setUserResolver(fn (): ?User => $user);
    }

    return (new CartService)->usingRequest($request);
}

test('un visitante que agrega su primer artículo estrena carrito, token y cookie de 30 días', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 10)->variants()->first();

    $servicio = carritoDe();

    $resultado = $servicio->add($variante, 3);

    expect($resultado)->toMatchArray([
        'added' => 3,
        'quantity' => 3,
        'available' => 10,
        'capped' => false,
        'reason' => null,
    ]);

    $carrito = Cart::whereNull('user_id')->whereNotNull('token')->first();

    expect($carrito)->not->toBeNull()
        ->and($carrito->items()->count())->toBe(1)
        ->and($carrito->items()->first()->quantity)->toBe(3)
        ->and($carrito->last_activity_at)->not->toBeNull();

    $cookie = $servicio->queuedCookie();

    expect($cookie)->toBeInstanceOf(Cookie::class)
        ->and($cookie->getName())->toBe(Cart::TOKEN_COOKIE)
        ->and($cookie->getValue())->toBe($carrito->token)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());
});

test('un visitante que ya tiene carrito suma en la misma línea sin duplicarla', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 10)->variants()->first();

    $primera = carritoDe();
    $primera->add($variante, 2);
    $token = $primera->resolve()->token;

    $segunda = carritoDe(token: $token);
    $resultado = $segunda->add($variante, 4);

    expect($resultado)->toMatchArray(['added' => 4, 'quantity' => 6])
        ->and($segunda->count())->toBe(6)
        ->and(Cart::where('token', $token)->with('items')->first()->items)->toHaveCount(1);
});

test('un cliente autenticado usa el carrito de su cuenta y no la cookie', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $usuario = User::factory()->create();

    // El navegador ya tenía un carrito de visitante al iniciar sesión.
    $visitante = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 2,
    ]);

    $servicio = carritoDe(user: $usuario, token: $visitante->token);
    $resultado = $servicio->add($variante, 1);

    $carrito = Cart::where('user_id', $usuario->getKey())->first();

    expect($carrito)->not->toBeNull()
        ->and($carrito->token)->toBeNull()
        ->and($resultado)->toMatchArray(['added' => 1, 'quantity' => 1, 'capped' => false])
        ->and($carrito->items()->count())->toBe(1)
        ->and($servicio->queuedCookie())->toBeNull();

    // El carrito del visitante queda intacto y sin cuenta: no se toma ni se mezcla.
    expect($visitante->refresh()->user_id)->toBeNull()
        ->and($visitante->items()->sum('quantity'))->toBe(2);
});

test('agregar de más topa la línea al stock disponible y lo avisa', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 10)->variants()->first();

    $servicio = carritoDe();

    $primero = $servicio->add($variante, 15);
    expect($primero)->toMatchArray([
        'added' => 10,
        'quantity' => 10,
        'available' => 10,
        'capped' => true,
        'reason' => null,
    ]);

    $segundo = $servicio->add($variante, 5);
    expect($segundo)->toMatchArray(['added' => 0, 'quantity' => 10, 'capped' => true])
        ->and($servicio->count())->toBe(10);
});

test('una variante con stock 0 no agrega nada y no estrena carrito', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 0)->variants()->first();

    $servicio = carritoDe();
    $resultado = $servicio->add($variante, 1);

    expect($resultado)->toMatchArray([
        'added' => 0,
        'quantity' => 0,
        'reason' => CartService::REASON_OUT_OF_STOCK,
    ])->and(Cart::count())->toBe(0)
        ->and($servicio->queuedCookie())->toBeNull()
        ->and($servicio->count())->toBe(0);
});

test('agregar una cantidad inválida se rechaza sin tocar la base', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();

    expect(fn () => $servicio->add($variante, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $servicio->add($variante, -3))->toThrow(InvalidArgumentException::class)
        ->and(Cart::count())->toBe(0);
});

test('una variante inactiva o un producto no visible no se agregan', function () {
    $catalogo = carritoCatalogo();
    $inactiva = carritoProducto($catalogo, stock: 5, varianteActiva: false)->variants()->first();
    $apagado = carritoProducto($catalogo, stock: 5, product: ['status' => 'inactive'])->variants()->first();

    $servicio = carritoDe();

    $primera = $servicio->add($inactiva, 1);
    $segunda = $servicio->add($apagado, 1);

    expect($primera)->toMatchArray(['added' => 0, 'reason' => CartService::REASON_UNAVAILABLE])
        ->and($segunda)->toMatchArray(['added' => 0, 'reason' => CartService::REASON_UNAVAILABLE])
        ->and(Cart::count())->toBe(0);
});

test('setQuantity fija la cantidad, topa al stock y con 0 elimina la línea', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 10)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($variante, 2);
    $linea = $servicio->resolve()->items()->first();

    $baja = $servicio->setQuantity($linea, 1);
    expect($baja)->toMatchArray(['added' => -1, 'quantity' => 1, 'available' => 10, 'capped' => false, 'reason' => null]);

    $sube = $servicio->setQuantity($linea->refresh(), 99);
    expect($sube)->toMatchArray(['added' => 9, 'quantity' => 10, 'available' => 10, 'capped' => true, 'reason' => null]);

    $elimina = $servicio->setQuantity($linea->refresh(), 0);
    expect($elimina)->toMatchArray(['added' => -10, 'quantity' => 0, 'capped' => false])
        ->and($servicio->count())->toBe(0)
        ->and(CartItem::count())->toBe(0);
});

test('setQuantity con una cantidad negativa se rechaza', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($variante, 1);
    $linea = $servicio->resolve()->items()->first();

    expect(fn () => $servicio->setQuantity($linea, -1))->toThrow(InvalidArgumentException::class)
        ->and($linea->refresh()->quantity)->toBe(1);
});

test('setQuantity y remove sobre líneas de otro carrito o de un token inventado se rechazan', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $dueño = carritoDe();
    $dueño->add($variante, 2);
    $linea = $dueño->resolve()->items()->first();

    foreach ([carritoDe(), carritoDe(token: 'token-que-no-existe')] as $ajeno) {
        expect(fn () => $ajeno->setQuantity($linea, 1))->toThrow(CartItemNotOwnedException::class)
            ->and(fn () => $ajeno->remove($linea))->toThrow(CartItemNotOwnedException::class);
    }

    expect($linea->refresh()->quantity)->toBe(2)
        ->and(Cart::where('user_id', null)->count())->toBe(1);
});

test('remove quita una línea propia y clear vacía el carrito', function () {
    $catalogo = carritoCatalogo();
    $a = carritoProducto($catalogo, stock: 5)->variants()->first();
    $b = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($a, 2);
    $servicio->add($b, 1);

    $lineaB = $servicio->resolve()->items()->where('product_variant_id', $b->getKey())->first();
    $servicio->remove($lineaB);

    expect($servicio->count())->toBe(2)
        ->and($servicio->resolve()->items()->count())->toBe(1);

    $servicio->clear();

    expect($servicio->count())->toBe(0)
        ->and(CartItem::count())->toBe(0);
});

test('el precio del carrito se lee en vivo de la variante y del producto', function () {
    $catalogo = carritoCatalogo();
    $producto = carritoProducto($catalogo, stock: 5, product: ['base_price' => 120000]);

    $servicio = carritoDe();
    $servicio->add($producto->variants()->first(), 2);

    $antes = $servicio->summary();

    expect($antes['items'][0]['unit_price'])->toBe(120000)
        ->and($antes['items'][0]['subtotal'])->toBe(240000)
        ->and($antes['subtotal'])->toBe(240000);

    $producto->update(['base_price' => 180000]);

    $despues = $servicio->summary();

    expect($despues['items'][0]['unit_price'])->toBe(180000)
        ->and($despues['subtotal'])->toBe(360000);

    $producto->variants()->first()->update(['price_override' => 99000]);

    $conTope = $servicio->summary();

    expect($conTope['items'][0]['unit_price'])->toBe(99000)
        ->and($conTope['subtotal'])->toBe(198000);
});

test('una línea que se quedó sin variante activa o sin producto visible queda no disponible y fuera del subtotal', function () {
    $catalogo = carritoCatalogo();
    $producto = carritoProducto($catalogo, stock: 5);

    $servicio = carritoDe();
    $servicio->add($producto->variants()->first(), 2);

    $producto->variants()->first()->update(['is_active' => false]);

    $resumen = $servicio->summary();

    expect($resumen['items'])->toHaveCount(1)
        ->and($resumen['items'][0]['status'])->toBe(CartService::STATUS_UNAVAILABLE)
        ->and($resumen['subtotal'])->toBe(0);

    $producto->variants()->first()->update(['is_active' => true]);
    $producto->update(['status' => 'inactive']);

    $resumen = $servicio->summary();

    expect($resumen['items'][0]['status'])->toBe(CartService::STATUS_UNAVAILABLE)
        ->and($resumen['subtotal'])->toBe(0);
});

test('summary entrega los campos de pantalla y el subtotal de las líneas disponibles', function () {
    $catalogo = carritoCatalogo();
    $producto = carritoProducto($catalogo, stock: 8, product: ['base_price' => 100000]);
    $variante = $producto->variants()->first();

    ProductImage::factory()->primary()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $catalogo['negro']->getKey(),
        'path' => 'products/negro.jpg',
        'thumbnail_path' => 'products/thumb-negro.jpg',
        'order' => 1,
    ]);

    $servicio = carritoDe();
    $servicio->add($variante, 2);

    $resumen = $servicio->summary();

    expect($resumen['items'])->toHaveCount(1);

    $linea = $resumen['items'][0];

    expect($linea)->toMatchArray([
        'id' => $servicio->resolve()->items()->first()->getKey(),
        'variant_id' => $variante->getKey(),
        'quantity' => 2,
        'unit_price' => 100000,
        'subtotal' => 200000,
        'available_stock' => 8,
        'status' => CartService::STATUS_AVAILABLE,
        'product_name' => $producto->name,
        'product_slug' => $producto->slug,
        'product_url' => route('storefront.product', $producto->slug),
        'color_name' => $catalogo['negro']->name,
        'color_hex' => $catalogo['negro']->hex,
        'size_name' => $catalogo['talla']->name,
        'image' => Storage::disk('public')->url('products/thumb-negro.jpg'),
    ])->and($resumen['subtotal'])->toBe(200000);
});

test('count() es una sola consulta de agregado', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 10)->variants()->first();

    $servicio = carritoDe(user: User::factory()->create());
    $servicio->add($variante, 3);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $servicio->count();

    expect(count(DB::getQueryLog()))->toBe(1);

    DB::disableQueryLog();
});

test('summary() hace el mismo número de consultas con una línea y con cinco', function () {
    $catalogo = carritoCatalogo();
    $servicio = carritoDe(user: User::factory()->create());

    $servicio->add(carritoProducto($catalogo, stock: 5)->variants()->first(), 2);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $servicio->summary();
    $conUna = count(DB::getQueryLog());

    foreach (range(1, 4) as $ignorado) {
        $servicio->add(carritoProducto($catalogo, stock: 5)->variants()->first(), 2);
    }

    DB::flushQueryLog();
    $servicio->summary();
    $conCinco = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($conUna)->toBeGreaterThan(0)
        ->and($conCinco)->toBe($conUna);
});

test('el token de un carrito que ya pasó a una cuenta se trata como si no hubiera carrito', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($variante, 2);
    $token = $servicio->resolve()->token;

    // El visitante inició sesión y su carrito quedó en manos de la cuenta.
    Cart::where('token', $token)->update(['user_id' => User::factory()->create()->getKey(), 'token' => null]);

    $intruso = carritoDe(token: $token);

    expect($intruso->count())->toBe(0)
        ->and($intruso->summary())->toBe(['items' => [], 'subtotal' => 0])
        ->and(Cart::where('token', $token)->count())->toBe(0);
});

test('cada operación que modifica el carrito refresca last_activity_at', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($variante, 2);
    $carrito = $servicio->resolve();

    $carrito->forceFill(['last_activity_at' => now()->subDay()])->save();
    $antes = $carrito->last_activity_at;

    $servicio->setQuantity($carrito->items()->first(), 3);

    expect($carrito->refresh()->last_activity_at->gt($antes))->toBeTrue();
});

test('validateForCheckout detecta los problemas sin modificar el carrito', function () {
    $catalogo = carritoCatalogo();
    $disponible = carritoProducto($catalogo, stock: 5);
    $ajustada = carritoProducto($catalogo, stock: 2);
    $apagada = carritoProducto($catalogo, stock: 3);

    $servicio = carritoDe();
    $servicio->add($disponible->variants()->first(), 2);
    $servicio->add($ajustada->variants()->first(), 2);
    $servicio->add($apagada->variants()->first(), 1);

    // El stock bajó después de agregar, y la tercera variante se apagó.
    $ajustada->variants()->first()->update(['stock' => 1]);
    $apagada->variants()->first()->update(['is_active' => false]);

    $problemas = $servicio->validateForCheckout();

    expect($problemas)->toHaveCount(2);

    $porVariante = collect($problemas)->keyBy('variant_id');

    expect($porVariante[$ajustada->variants()->first()->getKey()])->toMatchArray([
        'status' => CartService::STATUS_INSUFFICIENT_STOCK,
        'requested' => 2,
        'available' => 1,
    ])->and($porVariante[$apagada->variants()->first()->getKey()])->toMatchArray([
        'status' => CartService::STATUS_UNAVAILABLE,
        'requested' => 1,
        'available' => 3,
    ]);

    // Ninguna línea se tocó.
    expect(CartItem::query()->pluck('quantity')->sort()->values()->all())->toBe([1, 2, 2]);
});

test('validateForCheckout pasa en blanco cuando todo está disponible', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    $servicio = carritoDe();
    $servicio->add($variante, 2);

    expect($servicio->validateForCheckout())->toBe([]);
});

test('currentCount() lee el contador del encabezado sin crear carritos', function () {
    $catalogo = carritoCatalogo();
    $variante = carritoProducto($catalogo, stock: 5)->variants()->first();

    // Un visitante sin cookie lee 0 sin siquiera tocar la base.
    expect(carritoDe()->currentCount())->toBe(0)
        ->and(Cart::count())->toBe(0);

    // Un cliente sin carrito lee 0 y la cuenta no lo estrena.
    $cliente = User::factory()->create();

    expect(carritoDe(user: $cliente)->currentCount())->toBe(0)
        ->and(Cart::count())->toBe(0);

    // Un visitante con cookie de un carrito persistido lee la suma de sus líneas.
    $visitante = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 4,
    ]);

    expect(carritoDe(token: $visitante->token)->currentCount())->toBe(4)
        ->and(Cart::count())->toBe(1);

    // Un token que no existe, o cuyo carrito ya pasó a una cuenta, lee 0.
    expect(carritoDe(token: 'token-que-no-existe')->currentCount())->toBe(0);

    Cart::whereKey($visitante->getKey())->update([
        'user_id' => $cliente->getKey(),
        'token' => null,
    ]);

    expect(carritoDe(token: $visitante->token)->currentCount())->toBe(0);

    // La cuenta ahora lee lo que quedó en su carrito.
    CartItem::factory()->create([
        'cart_id' => $visitante->getKey(),
        'product_variant_id' => carritoProducto($catalogo, stock: 5)->variants()->first()->getKey(),
        'quantity' => 2,
    ]);

    expect(carritoDe(user: $cliente)->currentCount())->toBe(6)
        ->and(Cart::count())->toBe(1);
});

test('al borrar una variante, el contador y el resumen del carrito ya no la cuentan', function () {
    $catalogo = carritoCatalogo();

    // Una variante sin tocar (ni stock ni movimientos): la acción de borrado la
    // acepta, y las líneas de carrito se van en cascada con ella.
    $producto = carritoProducto($catalogo, stock: 0);
    $variante = $producto->variants()->first();

    $carrito = Cart::factory()->create();
    CartItem::factory()->create([
        'cart_id' => $carrito->getKey(),
        'product_variant_id' => $variante->getKey(),
        'quantity' => 3,
    ]);

    $servicio = carritoDe(token: $carrito->token);

    expect($servicio->count())->toBe(3)
        ->and($servicio->summary()['items'])->toHaveCount(1);

    (new DeleteProductVariant)($producto, $variante->getKey());

    expect(CartItem::query()->count())->toBe(0)
        ->and($servicio->count())->toBe(0)
        ->and($servicio->summary())->toBe(['items' => [], 'subtotal' => 0]);
});
