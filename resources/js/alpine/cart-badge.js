/**
 * El contador de unidades del encabezado de la tienda.
 *
 * Se registra vía `Alpine.data('cartBadge', ...)` desde el barrel
 * `resources/js/alpine/index.js`. En Blade se usa como
 * `x-data="cartBadge({ count: … })"`: el servidor pone el número real del
 * render inicial en una sola consulta, y el componente lo sustituye cuando la
 * ficha agrega algo al carrito.
 *
 * El aviso llega por el evento de ventana `cart-updated` (detail.count), que
 * el botón agregar dispara después de una respuesta exitosa; así el encabezado
 * no necesita conocer la ficha, igual que el modal de acceso con
 * `open-access`. Con cero se muestra solo «Carrito», sin el número.
 */
export default function cartBadge(config = {}) {
    return {
        count: Number(config.count) || 0,

        init() {
            window.addEventListener('cart-updated', (event) => {
                if (Number.isFinite(event.detail?.count)) {
                    this.count = event.detail.count;
                }
            });
        },

        label() {
            return this.count > 0 ? `Carrito · ${this.count}` : 'Carrito';
        },
    };
}