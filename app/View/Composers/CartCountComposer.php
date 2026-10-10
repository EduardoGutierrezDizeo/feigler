<?php

namespace App\View\Composers;

use App\Services\Storefront\CartService;
use Illuminate\View\View;

/**
 * El número de unidades del carrito para la insignia del encabezado.
 *
 * El layout de la tienda es un componente anónimo cuyo prop `cartCount` vale 0
 * por defecto. Las páginas que ya calculan el número (la portada, los listados,
 * la ficha y el carrito) se lo pasan por atributo; las demás —Mi cuenta y las
 * pantallas de acceso— no lo pasaban y la insignia salía vacía aunque hubiera
 * unidades. Este composer cubre justo ese hueco.
 *
 * Como los componentes anónimos entregan sus atributos también como datos de la
 * vista, si la página pasó `cart-count` la clave `cartCount` ya está en
 * `$view->getData()`: en ese caso no se toca nada y la página conserva su valor.
 * Solo cuando falta se calcula una vez con `currentCount()`, que es una sola
 * consulta de agregado (y ninguna si el visitante no trae cookie). El layout se
 * renderiza una vez por petición, así que esto es, como mucho, una consulta por
 * petición.
 */
class CartCountComposer
{
    public function __construct(private readonly CartService $cart) {}

    public function compose(View $view): void
    {
        if (array_key_exists('cartCount', $view->getData())) {
            return;
        }

        $view->with('cartCount', $this->cart->currentCount());
    }
}
