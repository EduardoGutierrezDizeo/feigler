<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\Storefront\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El carrito desde la tienda.
 *
 * Suma una o varias unidades desde la ficha: la cantidad llega en el cuerpo del
 * POST, se valida como entero mayor que cero y el servidor sigue siendo quien
 * topa al stock real. La variante tiene que existir, estar activa y pertenecer a
 * un producto visible, y el stock se lee en vivo dentro de la operación. La
 * respuesta es JSON para que el botón de la ficha actualice el contador del
 * encabezado sin recargar; la cookie del visitante se encola dentro del propio
 * servicio.
 *
 * La página del carrito ve las líneas con el resumen de CartService y, para
 * cambiar la cantidad o eliminar una línea, expone dos acciones JSON que
 * devuelven el resumen fresco y el contador para que la pantalla se vuelva a
 * pintar sin recargar. Toda escritura valida la cantidad en el servidor y la
 * propiedad de la línea contra el carrito actual: una línea inexistente o de
 * otro carrito responde el mismo 404 genérico, sin revelar si existe en algún
 * sitio.
 */
class CartController extends Controller
{
    /**
     * El aviso cuando la variante ya no se vende o nunca figuró en el catálogo.
     */
    public const MESSAGE_UNAVAILABLE = 'Este producto ya no está disponible.';

    /**
     * El aviso cuando la variante se vendió y no le queda stock.
     */
    public const MESSAGE_OUT_OF_STOCK = 'Agotado: no quedan unidades de esta talla.';

    /**
     * El aviso cuando la unidad entró al carrito.
     */
    public const MESSAGE_ADDED = 'Agregado al carrito.';

    /**
     * El aviso cuando entraron varias unidades de una sola vez.
     */
    public const MESSAGE_ADDED_MANY = 'Agregaste %d unidades al carrito.';

    /**
     * El aviso cuando la cantidad pedida no es un entero mayor que cero.
     */
    public const MESSAGE_INVALID_QUANTITY = 'La cantidad debe ser un número entero mayor que cero.';

    /**
     * El aviso de una línea que ya no se puede comprar, en la página del carrito.
     */
    public const MESSAGE_LINE_UNAVAILABLE = 'Este artículo ya no está disponible.';

    /**
     * El aviso de una línea que pide más de la unidad que queda disponible.
     */
    public const MESSAGE_INSUFFICIENT_ONE = 'Solo queda 1 unidad disponible: baja la cantidad para ajustarla.';

    /**
     * El aviso de una línea que pide más de las unidades que quedan disponibles.
     */
    public const MESSAGE_INSUFFICIENT_MANY = 'Solo quedan %d unidades disponibles: baja la cantidad para ajustarla.';

    /**
     * El aviso de una línea cuyo producto se agotó por completo.
     */
    public const MESSAGE_SOLD_OUT = 'Se agotó: no quedan unidades. Elimina el artículo para sacarlo del carrito.';

    /**
     * El aviso cuando no se encuentra la línea: no existe, o es de otro carrito.
     */
    public const MESSAGE_ITEM_NOT_FOUND = 'No encontramos ese artículo en tu carrito.';

    /**
     * El aviso cuando al fijar la cantidad queda una sola unidad, en la página del carrito.
     */
    public const MESSAGE_CAPPED_SET_ONE = 'Solo queda 1 unidad de esta talla: tu carrito quedó en 1.';

    /**
     * El aviso cuando al fijar la cantidad quedan varias unidades, en la página del carrito.
     */
    public const MESSAGE_CAPPED_SET_MANY = 'Solo quedan %d unidades de esta talla: tu carrito quedó en %d.';

    /**
     * El aviso cuando lo pedido supera el stock y se topa, con una sola unidad
     * de la talla disponible.
     */
    public const MESSAGE_CAPPED_ONE = 'Solo queda 1 unidad de esta talla; tu carrito ya tiene %d.';

    /**
     * El aviso cuando lo pedido supera el stock y se topa, con varias unidades
     * de la talla disponibles.
     */
    public const MESSAGE_CAPPED_MANY = 'Solo quedan %d unidades de esta talla; tu carrito ya tiene %d.';

    /**
     * Sumar unidades de una variante al carrito de quien esté en la tienda.
     */
    public function add(Request $request, CartService $cart): JsonResponse
    {
        $variant = $this->sellableVariant($request);

        if ($variant === null) {
            return response()->json([
                'ok' => false,
                'message' => self::MESSAGE_UNAVAILABLE,
            ], 422);
        }

        $quantity = $this->quantity($request);

        if ($quantity === null) {
            return response()->json([
                'ok' => false,
                'message' => self::MESSAGE_INVALID_QUANTITY,
            ], 422);
        }

        $result = $cart->add($variant, $quantity);

        if ($result['reason'] === CartService::REASON_UNAVAILABLE) {
            return response()->json([
                'ok' => false,
                'message' => self::MESSAGE_UNAVAILABLE,
            ], 422);
        }

        if ($result['reason'] === CartService::REASON_OUT_OF_STOCK) {
            return response()->json([
                'ok' => false,
                'message' => self::MESSAGE_OUT_OF_STOCK,
                'count' => $cart->count(),
                'result' => $result,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['capped']
                ? $this->cappedMessage($result)
                : $this->addedMessage($result),
            'count' => $cart->count(),
            'result' => $result,
        ]);
    }

    /**
     * La página del carrito de quien esté en la tienda.
     *
     * Las líneas salen del resumen de CartService ya decoradas para la vista:
     * cada una trae el aviso que le corresponde (disponible, stock insuficiente
     * o no disponible) y las direcciones de sus acciones. Un visitante sin
     * carrito ve la página vacía sin que ningún carrito ni cookie nazcan.
     */
    public function show(CartService $cart): View
    {
        return view('storefront.cart', ['cart' => $this->page($cart)]);
    }

    /**
     * Fijar la cantidad de una línea desde la página del carrito.
     *
     * La cantidad se valida como entero mayor que cero (0 no elimina por esta
     * ruta: eliminarla tiene su propia acción) y la línea tiene que pertenecer
     * al carrito actual; cualquier otra responde 404 sin revelar si existe.
     */
    public function updateQuantity(Request $request, CartService $cart): JsonResponse
    {
        $item = $this->ownedItem($request, $cart);

        if ($item === null) {
            return $this->itemNotFound();
        }

        $quantity = $this->quantity($request);

        if ($quantity === null) {
            return response()->json([
                'ok' => false,
                'message' => self::MESSAGE_INVALID_QUANTITY,
            ], 422);
        }

        return $this->changedResponse($cart, $cart->setQuantity($item, $quantity));
    }

    /**
     * Eliminar una línea de la página del carrito.
     */
    public function destroy(Request $request, CartService $cart): JsonResponse
    {
        $item = $this->ownedItem($request, $cart);

        if ($item === null) {
            return $this->itemNotFound();
        }

        $cart->remove($item);

        return $this->changedResponse($cart, null);
    }

    /**
     * La línea del carrito actual que pide la ruta, o `null`.
     *
     * Es el mismo filtro que CartService aplica en sus operaciones, resuelto una
     * vez: la línea tiene que existir y pertenecer al carrito que la cookie (o
     * la cuenta) señala. Una línea que no existe y una que es de otro carrito
     * devuelven ambas `null`, para que la respuesta no las distinga.
     */
    private function ownedItem(Request $request, CartService $cart): ?CartItem
    {
        $item = CartItem::find((int) $request->route('item'));

        if ($item === null) {
            return null;
        }

        $own = $cart->resolve();

        if (! $own->exists || $item->cart_id !== $own->getKey()) {
            return null;
        }

        return $item;
    }

    /**
     * La respuesta genérica de una línea que no se puede tocar.
     */
    private function itemNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => self::MESSAGE_ITEM_NOT_FOUND,
        ], 404);
    }

    /**
     * La respuesta de una escritura con el resumen fresco y el contador.
     *
     * `count` son las unidades del carrito y `summary` el resumen tal como lo
     * ve la página, con el aviso de cada línea: la pantalla re-pinta desde aquí.
     */
    private function changedResponse(CartService $cart, ?array $result): JsonResponse
    {
        $page = $this->page($cart);

        return response()->json([
            'ok' => true,
            'message' => $result !== null ? $this->changeNotice($result) : '',
            'count' => $page['units'],
            'summary' => [
                'items' => $page['items'],
                'subtotal' => $page['subtotal'],
            ],
        ]);
    }

    /**
     * El aviso de una escritura sobre una línea.
     *
     * Cuando la cantidad pedida superó el stock, lo dice con el número correcto
     * en singular o plural; una variante que dejó de venderse recibe el aviso de
     * artículo no disponible; el resto no avisa.
     */
    private function changeNotice(array $result): string
    {
        if ($result['capped']) {
            return $result['available'] === 1
                ? self::MESSAGE_CAPPED_SET_ONE
                : sprintf(self::MESSAGE_CAPPED_SET_MANY, $result['available'], $result['quantity']);
        }

        return $result['reason'] === CartService::REASON_UNAVAILABLE
            ? self::MESSAGE_LINE_UNAVAILABLE
            : '';
    }

    /**
     * Los datos de la página del carrito.
     *
     * @return array{items: list<array<string, mixed>>, subtotal: int, units: int, empty: bool}
     */
    private function page(CartService $cart): array
    {
        $summary = $cart->summary();

        $items = array_map(function (array $line): array {
            return array_merge($line, [
                'notice' => $this->lineNotice($line),
                'max_options' => max(10, (int) $line['quantity']),
                'urls' => [
                    'quantity' => route('storefront.cart.update', $line['id']),
                    'remove' => route('storefront.cart.destroy', $line['id']),
                ],
            ]);
        }, $summary['items']);

        return [
            'items' => $items,
            'subtotal' => (int) $summary['subtotal'],
            'units' => array_sum(array_column($summary['items'], 'quantity')),
            'empty' => $items === [],
        ];
    }

    /**
     * El aviso permanente de una línea, según su estado en el resumen.
     */
    private function lineNotice(array $line): string
    {
        return match ($line['status']) {
            CartService::STATUS_UNAVAILABLE => self::MESSAGE_LINE_UNAVAILABLE,
            CartService::STATUS_INSUFFICIENT_STOCK => $this->insufficientNotice((int) $line['available_stock']),
            default => '',
        };
    }

    /**
     * El aviso de stock insuficiente, con el máximo disponible y el plural correcto.
     */
    private function insufficientNotice(int $available): string
    {
        return match (true) {
            $available <= 0 => self::MESSAGE_SOLD_OUT,
            $available === 1 => self::MESSAGE_INSUFFICIENT_ONE,
            default => sprintf(self::MESSAGE_INSUFFICIENT_MANY, $available),
        };
    }

    /**
     * La cantidad a fijar, o `null` si no es un entero mayor que cero.
     *
     * Sin `quantity` se asume 1 (el botón clásico); cualquier otro valor que no
     * sea un entero positivo —entre ellos 0, negativos, texto, vacío o un número
     * fuera de rango— se rechaza antes de tocar el carrito.
     */
    private function quantity(Request $request): ?int
    {
        $value = $request->input('quantity', 1);

        $valid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $valid === false ? null : (int) $valid;
    }

    /**
     * El aviso de cuántas unidades entraron, en singular o plural.
     */
    private function addedMessage(array $result): string
    {
        return $result['added'] > 1
            ? sprintf(self::MESSAGE_ADDED_MANY, $result['added'])
            : self::MESSAGE_ADDED;
    }

    /**
     * El aviso de tope, con el número correcto en singular o plural.
     *
     * Queda una sola unidad: «Solo queda 1 unidad de esta talla; tu carrito ya
     * tiene N.». Quedan varias: «Solo quedan %d unidades de esta talla; tu
     * carrito ya tiene %d.».
     */
    private function cappedMessage(array $result): string
    {
        return $result['available'] === 1
            ? sprintf(self::MESSAGE_CAPPED_ONE, $result['quantity'])
            : sprintf(self::MESSAGE_CAPPED_MANY, $result['available'], $result['quantity']);
    }

    /**
     * La variante que todavía se puede sumar a un carrito, o `null`.
     *
     * Es el primer filtro del servidor: id presente, fila existente, variante
     * activa y producto visible, todo bajo el mismo scope que usa la tienda.
     * `add()` vuelve a leer la disponibilidad en vivo dentro de la operación,
     * así que una variante que se apagó entre la validación y el agregado no
     * escapa.
     */
    private function sellableVariant(Request $request): ?ProductVariant
    {
        $variantId = $request->input('variant_id');

        if (! is_numeric($variantId) || (int) $variantId < 1) {
            return null;
        }

        return ProductVariant::query()
            ->whereKey((int) $variantId)
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query): Builder => $query->visible())
            ->first();
    }
}
