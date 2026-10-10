/**
 * La página del carrito: el contenedor de todas las líneas.
 *
 * El servidor pinta la primera carga completa; acá solo se re-pinta cuando una
 * línea terminó de hablar (evento `cart-changed` en la ventana, que la línea o
 * una cantidad exterior como el agregar de la ficha disparan con el resumen
 * fresco): subtotal, unidades, el estado vacío y el contador del encabezado.
 *
 * `apply` es el método al que llama el `@cart-changed.window` del contenedor.
 * Dispara además `cart-updated`, el mismo evento que consume cart-badge.js.
 */
import formatMoney from './money.js';

/**
 * @param {{ subtotal: number, units: number, empty: boolean }} config
 */
export default function cartPage(config) {
    return {
        subtotal: Number(config.subtotal),
        units: Number(config.units),
        empty: Boolean(config.empty),

        get subtotalLabel() {
            return formatMoney(this.subtotal);
        },

        get unitsLabel() {
            return this.units === 1 ? '1 unidad' : `${this.units} unidades`;
        },

        apply(data) {
            const items = data.summary?.items ?? [];

            this.subtotal = Number(data.summary?.subtotal ?? this.subtotal);
            this.units = Number(data.count ?? items.reduce((sum, item) => sum + Number(item.quantity), 0));
            this.empty = items.length === 0;

            window.dispatchEvent(new CustomEvent('cart-updated', {
                detail: { count: this.units },
            }));
        },
    };
}