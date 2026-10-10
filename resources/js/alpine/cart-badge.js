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
 *
 * Al volver con el botón «atrás» el navegador puede restaurar la página desde
 * la caché de sesión (bfcache) sin volver a ejecutar nada: el HTML trae el
 * contador viejo y ningún evento lo habría refrescado. Para eso está
 * `refresh()`: el `pageshow` con `persisted` avisa que la página vino de la
 * caché y el encabezado vuelve a pedir el número (solo si cambió, para no
 * parpadear). El `countUrl` llega desde Blade con la ruta nombrada.
 */
export default function cartBadge(config = {}) {
    return {
        count: Number(config.count) || 0,
        countUrl: config.countUrl ?? '/carrito/contador',

        init() {
            window.addEventListener('cart-updated', (event) => {
                if (Number.isFinite(event.detail?.count)) {
                    this.count = event.detail.count;
                }
            });

            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    this.refresh();
                }
            });
        },

        async refresh() {
            try {
                const response = await fetch(this.countUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (! response.ok) {
                    return;
                }

                const data = await response.json();

                if (Number.isFinite(data.count) && data.count !== this.count) {
                    this.count = data.count;
                }
            } catch {
                // Sin red no hay número nuevo que mostrar: se conserva el que trajo el HTML.
            }
        },

        label() {
            return this.count > 0 ? `Carrito · ${this.count}` : 'Carrito';
        },
    };
}