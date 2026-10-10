<?php

namespace App\Services\Storefront;

use App\Exceptions\CartItemNotOwnedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie as CookieQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Todo lo que un carrito puede hacer, en un solo sitio.
 *
 * {@link resolve()} encuentra el carrito de la persona que está en la tienda —
 * el de su cuenta cuando entró, o el del visitante que su cookie señala — y
 * cada operación (agregar, fijar cantidad, quitar, vaciar, contar, resumir)
 * trabaja siempre contra ese carrito. Ninguna de ellas puede tocar una línea
 * de un carrito ajeno: un token de cookie inválido, o el de un carrito que ya
 * pasó a una cuenta, se trata como «no hay carrito», con lo que las líneas de
 * otros nunca quedan a la vista ni admiten cambios.
 *
 * **Reglas que vive aquí y solo aquí.** El stock no se reserva: solo se lee en
 * vivo para topar lo que se pide y avisar. El precio no se guarda en la línea:
 * se lee de la variante (su `price_override`, o el `base_price` del producto
 * si no lo tiene) cada vez que se resume el carrito. Un carrito de visitante
 * no nace al visitar la tienda, sino con el primer artículo que se agrega. Y
 * toda operación que modifica el carrito refresca `last_activity_at`, para que
 * el carrito olvidado se distinga del que sigue en uso.
 *
 * Para los add() y setQuantity() se devuelve siempre el mismo resultado, para
 * que la pantalla (la ficha, la página del carrito) trate de una sola manera
 * los casos: `added` es lo que la operación pudo sumar, `quantity` la cantidad
 * final de la línea, `available` el stock leído en vivo, `capped` avisa si lo
 * pedido superó el stock y se topó, y `reason` distingue un artículo que ya no
 * se vende (`unavailable`) de uno que se agotó (`out_of_stock`).
 */
class CartService
{
    /**
     * Cuánto dura la cookie del visitante, en minutos: 30 días.
     */
    public const COOKIE_LIFETIME_MINUTES = 60 * 24 * 30;

    /**
     * El motivo: la variante ya no se vende (inactiva) o su producto ya no es
     * visible.
     */
    public const REASON_UNAVAILABLE = 'unavailable';

    /**
     * El motivo: la variante se vendió y no le queda stock.
     */
    public const REASON_OUT_OF_STOCK = 'out_of_stock';

    /**
     * El estado de una línea que se puede comprar tal cual está.
     */
    public const STATUS_AVAILABLE = 'available';

    /**
     * El estado de una línea cuya cantidad supera el stock actual.
     */
    public const STATUS_INSUFFICIENT_STOCK = 'insufficient_stock';

    /**
     * El estado de una línea que ya no se puede comprar: variante inactiva o
     * producto no visible.
     */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * El carrito ya resuelto de esta instancia, para que dos llamadas del mismo
     * ciclo no consulten (ni creen) dos veces el mismo carrito.
     */
    private ?Cart $cart = null;

    /**
     * Si `$cart` ya se resolvió: una vez resuelto, el carrito de esta estancia
     * no vuelve a buscarse, aunque sea el carrito vacío de un visitante sin
     * cookie.
     */
    private bool $resolved = false;

    /**
     * La cookie que hay que mandar al navegador del visitante: la nueva cuando
     * su carrito nace, o la renovada cuando ya existía. `null` cuando la persona
     * tiene cuenta, porque el carrito de una cuenta no necesita cookie.
     */
    private ?Cookie $queuedCookie = null;

    /**
     * La petición explícita que una instancia construida a mano (las pruebas)
     * debe ver, en lugar de la que el contenedor tenga en curso.
     */
    private ?Request $request = null;

    /**
     * La petición en curso.
     *
     * La instancia que arma el contenedor (scoped) resuelve la petición en el
     * momento de cada uso, para que un mismo proceso que atiende varias peticiones
     * (las pruebas, Octane) no se quede con la primera. Una instancia construida a
     * mano puede traer su propia petición con {@see usingRequest()}.
     */
    private function currentRequest(): Request
    {
        return $this->request ?? app('request');
    }

    /**
     * La petición a la que esta instancia debe atender cuando se construye a
     * mano, fuera del contenedor (las pruebas).
     */
    public function usingRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * El carrito de la persona que está en la tienda.
     *
     * Con cuenta: el de su cuenta, creándolo si todavía no existe. Sin cuenta:
     * el carrito del visitante que la cookie `cart_token` señala, siempre que
     * siga siendo un carrito de visitante (un token inválido o el de un carrito
     * que ya pasó a una cuenta se trata como si no hubiera cookie). Un
     * visitante sin carrito todavía no tiene nada guardado: se devuelve un
     * carrito vacío sin persistir, porque el carrito de visitante nace recién
     * con su primer artículo.
     */
    public function resolve(): Cart
    {
        if ($this->resolved) {
            return $this->cart ?? new Cart;
        }

        $user = $this->currentRequest()->user();

        if ($user !== null) {
            $this->cart = Cart::query()->firstOrCreate(['user_id' => $user->getKey()]);
            $this->resolved = true;

            return $this->cart;
        }

        $token = $this->currentRequest()->cookie(Cart::TOKEN_COOKIE);

        if ($token !== null) {
            $stored = Cart::query()
                ->where('token', $token)
                ->whereNull('user_id')
                ->first();

            if ($stored !== null) {
                $this->cart = $stored;
                $this->renewCookie($token);
            }
        }

        $this->cart ??= new Cart;
        $this->resolved = true;

        return $this->cart;
    }

    /**
     * Sumar a la línea de una variante, o crearla, topándola al stock.
     *
     * `$quantity` debe ser entero positivo; se rechaza antes de tocar nada. La
     * variante tiene que seguir vendiéndose (variante activa y producto
     * visible) y, si le queda stock, la cantidad total de la línea se topa a lo
     * disponible: el resultado dice cuántas unidades se agregaron de verdad,
     * si hubo tope y, cuando la variante no se puede vender o se agotó, el
     * motivo. Con stock 0 no se agrega nada y, de paso, un visitante sin
     * carrito tampoco lo estrena.
     *
     * @return array{added: int, quantity: int, available: int, capped: bool, reason: string|null}
     */
    public function add(ProductVariant $variant, int $quantity = 1): array
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("La cantidad a agregar debe ser mayor que cero, se recibió {$quantity}.");
        }

        $cart = $this->resolve();

        return DB::transaction(function () use ($variant, $quantity, $cart): array {
            $current = $this->currentQuantity($cart, $variant);
            $sellable = $this->freshSellableVariant($variant);

            if ($sellable === null) {
                return $this->result($current, $current, 0, false, self::REASON_UNAVAILABLE);
            }

            $stock = (int) $sellable->stock;

            if ($stock <= 0) {
                return $this->result(0, $current, 0, true, self::REASON_OUT_OF_STOCK);
            }

            $this->persistVisitorCartIfNeeded($cart);

            $target = min($current + $quantity, $stock);

            if ($current === 0) {
                $cart->items()->create([
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => $target,
                ]);
            } else {
                $cart->items()
                    ->where('product_variant_id', $variant->getKey())
                    ->update(['quantity' => $target]);
            }

            $cart->touchActivity();

            return $this->result($target - $current, $target, $stock, $current + $quantity > $stock, null);
        });
    }

    /**
     * Fijar la cantidad de una línea, con el mismo tope al stock que add().
     *
     * Cantidad 0 elimina la línea, una cantidad negativa se rechaza, y solo se
     * opera sobre líneas del carrito actual: sobre una de otro carrito se lanza
     * CartItemNotOwnedException antes de tocar nada. Si la variante ya no se
     * vende o se agotó, la línea no se toca: lo dice el resultado.
     *
     * @return array{added: int, quantity: int, available: int, capped: bool, reason: string|null}
     */
    public function setQuantity(CartItem $item, int $quantity): array
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException("La cantidad de una línea no puede ser negativa, se recibió {$quantity}.");
        }

        $cart = $this->resolve();
        $this->guardOwned($item, $cart);
        $variant = $item->productVariant;
        $old = $item->quantity;

        if ($quantity === 0) {
            return DB::transaction(function () use ($item, $cart, $variant, $old): array {
                $item->delete();
                $cart->touchActivity();

                return $this->result(-$old, 0, $variant !== null ? $this->stockOf($variant) : 0, false, null);
            });
        }

        if ($variant === null) {
            return $this->result(0, $old, 0, false, self::REASON_UNAVAILABLE);
        }

        return DB::transaction(function () use ($item, $cart, $variant, $quantity, $old): array {
            $sellable = $this->freshSellableVariant($variant);

            if ($sellable === null) {
                return $this->result(0, $old, $this->stockOf($variant), false, self::REASON_UNAVAILABLE);
            }

            $stock = (int) $sellable->stock;
            $capped = $quantity > $stock;
            $target = min($quantity, $stock);

            if ($target === 0) {
                $item->delete();
                $cart->touchActivity();

                return $this->result(-$old, 0, $stock, true, self::REASON_OUT_OF_STOCK);
            }

            $item->forceFill(['quantity' => $target])->save();
            $cart->touchActivity();

            return $this->result($target - $old, $target, $stock, $capped, null);
        });
    }

    /**
     * Quitar una línea del carrito actual.
     *
     * @throws CartItemNotOwnedException cuando la línea es de otro carrito.
     */
    public function remove(CartItem $item): void
    {
        $cart = $this->resolve();
        $this->guardOwned($item, $cart);

        DB::transaction(function () use ($item, $cart): void {
            $item->delete();
            $cart->touchActivity();
        });
    }

    /**
     * Vaciar el carrito actual.
     */
    public function clear(): void
    {
        $cart = $this->resolve();

        if (! $cart->exists) {
            return;
        }

        DB::transaction(function () use ($cart): void {
            $cart->items()->delete();
            $cart->touchActivity();
        });
    }

    /**
     * El número de unidades en el carrito, para el contador del encabezado.
     *
     * Es una sola consulta de agregado (`sum` de una columna), nunca una carga
     * de líneas, y un visitante sin carrito responde 0 sin tocar la base.
     */
    public function count(): int
    {
        $cart = $this->resolve();

        if (! $cart->exists) {
            return 0;
        }

        return (int) $cart->items()->sum('quantity');
    }

    /**
     * El número de unidades del carrito para el render inicial de una página,
     * sin que el carrito nazca.
     *
     * Es la lectura que usa el encabezado de la tienda, que no puede resolver
     * el carrito: `resolve()` crearía el de un cliente que no lo tiene, y un
     * visitante sin carrito no debe quedar guardado solo por mirar una página.
     * Lee una sola consulta de agregado sobre `cart_items` y devuelve 0 sin
     * tocar la base cuando no hay carrito (visitante sin cookie o con cookie
     * que ya no es de un visitante).
     */
    public function currentCount(): int
    {
        $user = $this->currentRequest()->user();

        $query = CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id');

        if ($user !== null) {
            $query->where('carts.user_id', $user->getKey());
        } else {
            $token = $this->currentRequest()->cookie(Cart::TOKEN_COOKIE);

            if ($token === null) {
                return 0;
            }

            $query
                ->where('carts.token', $token)
                ->whereNull('carts.user_id');
        }

        return (int) $query->sum('cart_items.quantity');
    }

    /**
     * El resumen del carrito tal como una pantalla lo necesita.
     *
     * Trae las líneas con todo lo que la interfaz pinta: nombre del producto,
     * enlace por slug, color, talla, imagen del color de la variante (la misma
     * regla que la galería de la ficha: la principal del color primero), precio
     * unitario leído en vivo, cantidad, subtotal de línea, stock disponible y
     * un estado por línea — `available`, `insufficient_stock` (la cantidad
     * supera el stock actual) o `unavailable` (variante inactiva o producto no
     * visible). El subtotal general suma solo las líneas `available`, porque
     * una línea que no se puede comprar completa no puede comprometer dinero.
     *
     * El número de consultas no depende de cuántas líneas haya: las relaciones
     * se cargan por adelantado (variante, color, talla, producto, imágenes) y
     * la visibilidad de los productos se resuelve en una única consulta con el
     * mismo scope `visible()` que usan la portada y la ficha.
     *
     * @return array{items: list<array{id: int, variant_id: int, quantity: int, unit_price: int, subtotal: int, available_stock: int, status: string, product_name: string, product_slug: string, product_url: string, color_name: string, color_hex: string, size_name: string, image: string|null}>, subtotal: int}
     */
    public function summary(): array
    {
        $cart = $this->resolve();

        if (! $cart->exists) {
            return ['items' => [], 'subtotal' => 0];
        }

        $items = $cart->items()
            ->with([
                'productVariant.color',
                'productVariant.size',
                'productVariant.product.images',
            ])
            ->orderBy('id')
            ->get();

        $visibleByProduct = $this->visibleProducts($items);

        $lines = $items->map(fn (CartItem $item): array => $this->line($item, $visibleByProduct))->values();

        return [
            'items' => $lines->all(),
            'subtotal' => $lines->where('status', self::STATUS_AVAILABLE)->sum('subtotal'),
        ];
    }

    /**
     * Los problemas de stock o de disponibilidad que el checkout tendría que
     * resolver hoy, sin modificar nada.
     *
     * Sale del mismo resumen que ve la pantalla, así que no puede contradecirlo:
     * por cada línea que no está `available` se devuelve un problema con la
     * variante, el estado y lo pedido contra lo disponible. Una lista vacía
     * quiere decir que el carrito está listo para pagar.
     *
     * @return list<array{item_id: int, variant_id: int, product_name: string, status: string, requested: int, available: int}>
     */
    public function validateForCheckout(): array
    {
        $summary = $this->summary();

        return collect($summary['items'])
            ->filter(fn (array $line): bool => in_array($line['status'], [self::STATUS_UNAVAILABLE, self::STATUS_INSUFFICIENT_STOCK], true))
            ->map(fn (array $line): array => [
                'item_id' => $line['id'],
                'variant_id' => $line['variant_id'],
                'product_name' => $line['product_name'],
                'status' => $line['status'],
                'requested' => $line['quantity'],
                'available' => $line['available_stock'],
            ])
            ->values()
            ->all();
    }

    /**
     * La cookie que hay que mandar con la respuesta: la nueva cuando un
     * visitante estrena carrito, o la renovada cuando ya tenía uno.
     *
     * Vuelve `null` para un visitante que todavía no tiene carrito y para
     * cualquier persona con cuenta. La manda a cifrar el middleware de cookies,
     * como cualquier otra cookie de la aplicación.
     */
    public function queuedCookie(): ?Cookie
    {
        return $this->queuedCookie;
    }

    /**
     * La línea tal como la pinta una pantalla.
     *
     * @param  array<int, true>  $visibleByProduct  Los ids de los productos visibles, por id.
     * @return array{id: int, variant_id: int, quantity: int, unit_price: int, subtotal: int, available_stock: int, status: string, product_name: string, product_slug: string, product_url: string, color_name: string, color_hex: string, size_name: string, image: string|null}
     */
    private function line(CartItem $item, array $visibleByProduct): array
    {
        $variant = $item->productVariant;
        $product = $variant?->product;
        $price = $this->unitPrice($variant, $product);

        return [
            'id' => $item->getKey(),
            'variant_id' => $variant?->getKey() ?? $item->product_variant_id,
            'quantity' => $item->quantity,
            'unit_price' => $price,
            'subtotal' => $price * $item->quantity,
            'available_stock' => (int) ($variant?->stock ?? 0),
            'status' => $this->statusOf($item, $visibleByProduct),
            'product_name' => $product?->name ?? '',
            'product_slug' => $product?->slug ?? '',
            'product_url' => $product !== null ? route('storefront.product', $product->slug) : '',
            'color_name' => $variant?->color?->name ?? '',
            'color_hex' => $variant?->color?->hex ?? '',
            'size_name' => $variant?->size?->name ?? '',
            'image' => $product !== null && $variant !== null ? $this->colorImageThumbnail($product, $variant->color_id) : null,
        ];
    }

    /**
     * El estado de una línea, leyendo lo que summary() ya trajo cargado.
     *
     * No visible es la misma regla de Product::visible(), solo que resuelta en
     * memoria contra el conjunto que visible() ya eligió en una sola consulta:
     * si la variante está apagada, o su producto no está en ese conjunto, la
     * línea no se puede comprar.
     *
     * @param  array<int, true>  $visibleByProduct
     */
    private function statusOf(CartItem $item, array $visibleByProduct): string
    {
        $variant = $item->productVariant;
        $product = $variant?->product;

        if ($variant === null || $product === null || ! $variant->is_active || ! isset($visibleByProduct[$product->getKey()])) {
            return self::STATUS_UNAVAILABLE;
        }

        if ($item->quantity > (int) $variant->stock) {
            return self::STATUS_INSUFFICIENT_STOCK;
        }

        return self::STATUS_AVAILABLE;
    }

    /**
     * El precio unitario en vivo de la variante: su `price_override`, o el
     * `base_price` de su producto cuando no lo tiene, en pesos enteros como el
     * resto de la tienda.
     */
    private function unitPrice(ProductVariant $variant, ?Product $product): int
    {
        if ($product === null) {
            return 0;
        }

        return (int) round((float) ($variant->price_override ?? $product->base_price));
    }

    /**
     * Los ids de los productos visibles que están en el carrito, por id, en una
     * sola consulta con el scope que ya usa toda la tienda.
     *
     * @param  Collection<int, CartItem>  $items
     * @return array<int, true>
     */
    private function visibleProducts(Collection $items): array
    {
        $productIds = $items
            ->map(fn (CartItem $item): ?int => $item->productVariant?->product_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            return [];
        }

        return array_flip(Product::query()->visible()->whereIn('id', $productIds)->pluck('id')->all());
    }

    /**
     * La miniatura de la foto del color de la variante, con la misma regla que
     * la galería de la ficha: la principal del color primero y, entre las que
     * comparten orden, la subida antes.
     *
     * Lee de las imágenes que summary() ya cargó, así que nunca consulta por
     * línea.
     */
    private function colorImageThumbnail(Product $product, int $colorId): ?string
    {
        $image = $product->images
            ->where('color_id', $colorId)
            ->sortBy([['is_primary', 'desc'], ['order', 'asc'], ['id', 'asc']])
            ->first();

        return $image?->thumbnailUrl();
    }

    /**
     * La variante tal como está en la base: activa, con su producto visible.
     *
     * Es la única lectura de disponibilidad que las operaciones usan, para que
     * «agregar» y «fijar cantidad» no decidan con una copia vieja. Devuelve
     * `null` cuando la variante ya no se puede vender.
     */
    private function freshSellableVariant(ProductVariant $variant): ?ProductVariant
    {
        return ProductVariant::query()
            ->whereKey($variant->getKey())
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->visible())
            ->first();
    }

    /**
     * Lo que una línea guarda hoy para esta variante, o 0 si no hay línea.
     */
    private function currentQuantity(Cart $cart, ProductVariant $variant): int
    {
        if (! $cart->exists) {
            return 0;
        }

        return (int) $cart->items()
            ->where('product_variant_id', $variant->getKey())
            ->value('quantity');
    }

    /**
     * El stock actual de la variante, sin filtrar disponibilidad.
     */
    private function stockOf(ProductVariant $variant): int
    {
        return (int) ProductVariant::query()->whereKey($variant->getKey())->value('stock');
    }

    /**
     * Pedirle a un carrito de visitante que ya exista en la base y que su
     * cookie quede lista para el navegador.
     *
     * Es el único momento en que el carrito de un visitante nace: con el primer
     * artículo. El token se genera aquí, se guarda y se encola como cookie.
     */
    private function persistVisitorCartIfNeeded(Cart $cart): void
    {
        if ($cart->exists) {
            return;
        }

        $cart->token = (string) Str::uuid();
        $cart->last_activity_at = now();
        $cart->save();

        $this->renewCookie($cart->token);
    }

    /**
     * La operación a la que solo el dueño del carrito actual puede llegar.
     *
     * Un carrito que todavía no existe (visitante sin cookie) no puede ser el
     * dueño de ninguna línea, así que cualquier línea ajena se rechaza.
     *
     * @throws CartItemNotOwnedException
     */
    private function guardOwned(CartItem $item, Cart $cart): void
    {
        if (! $cart->exists || $item->cart_id !== $cart->getKey()) {
            throw CartItemNotOwnedException::forItem($item);
        }
    }

    /**
     * La cookie del visitante con su token, encolada para que expire dentro de
     * 30 días.
     *
     * El valor sale sin cifrar de aquí porque el middleware de cookies lo cifra
     * al enviarlo, igual que el resto. HttpOnly va puesto para que el script de
     * la página no pueda leerla.
     */
    private function renewCookie(string $token): void
    {
        $this->queuedCookie = cookie(
            Cart::TOKEN_COOKIE,
            $token,
            self::COOKIE_LIFETIME_MINUTES,
            '/',
            null,
            null,
            true,
            false,
            'lax',
        );

        // Toda respuesta que pase por el grupo `web` manda esta cookie en su
        // Set-Cookie (el middleware AddQueuedCookiesToResponse), sin que cada
        // pantalla tenga que acordarse de adjuntarla.
        CookieQueue::queue($this->queuedCookie);
    }

    /**
     * El resultado de una operación sobre una línea.
     *
     * @return array{added: int, quantity: int, available: int, capped: bool, reason: string|null}
     */
    private function result(int $added, int $quantity, int $available, bool $capped, ?string $reason): array
    {
        return [
            'added' => $added,
            'quantity' => $quantity,
            'available' => $available,
            'capped' => $capped,
            'reason' => $reason,
        ];
    }
}
