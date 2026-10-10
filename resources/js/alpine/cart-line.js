/**
 * Una línea de la página del carrito.
 *
 * Recibe la línea tal como la imprime el servidor: id, cantidad, subtotal,
 * estado, stock disponible, aviso y las direcciones de sus acciones. Cada línea
 * habla con su propio `PATCH /carrito/lineas/{id}` y `DELETE
 * /carrito/lineas/{id}`; el servidor responde con el resumen completo y el
 * contador, y la línea re-pinta su parte (cantidad, subtotal, avisos), o
 * desaparece si ya no existe, antes de avisar al resto de la página con el
 * evento `cart-changed` en la ventana.
 *
 * La ficha pública (product-purchase.js) también hace fetch con CSRF del layout:
 * acá el encabezado deja de mostrarse solo en la primera carga.
 */
import formatMoney from './money.js';

/**
 * @param {{ id: number, quantity: number, subtotal: number, status: string, available_stock: number, notice: string, urls: { quantity: string, remove: string } }} line
 */
export default function cartLine(line) {
    return {
        id: line.id,
        quantity: Number(line.quantity),
        subtotal: Number(line.subtotal),
        status: line.status,
        availableStock: Number(line.available_stock),
        unavailable: line.status === 'unavailable',
        notice: line.notice ?? '',
        failed: '',
        busy: false,
        urls: line.urls,

        get quantityLabel() {
            return this.unavailable ? `${this.quantity} unidades` : `Unidades: ${this.quantity}`;
        },

        get subtotalLabel() {
            return formatMoney(this.subtotal);
        },

        async request(url, options) {
            const response = await fetch(url, {
                method: options.method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
                body: options.body ? JSON.stringify(options.body) : null,
            });

            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(data.message ?? 'No pudimos actualizar el carrito. Intenta de nuevo.');
            }

            return data;
        },

        applyResults(data) {
            const line = (data.summary?.items ?? []).find((item) => item.id === this.id) ?? null;

            if (line === null) {
                this.$el.remove();

                this.dispatch(data);

                return;
            }

            this.quantity = Number(line.quantity);
            this.subtotal = Number(line.subtotal);
            this.status = line.status;
            this.unavailable = line.status === 'unavailable';
            this.availableStock = Number(line.available_stock);
            this.notice = data.message ?? line.notice ?? '';
            this.failed = '';
            this.rebuildOptions();

            this.dispatch(data);
        },

        dispatch(data) {
            window.dispatchEvent(new CustomEvent('cart-changed', { detail: data }));
        },

        async setQuantity(event) {
            await this.saveQuantity(Number(event.target.value));
        },

        /**
         * Mueve la cantidad un paso desde el contador -/+ sin pasarse del stock
         * disponible: al bajar desde una cifra superior al stock (línea avisada)
         * se frena directo en el máximo permitido.
         */
        stepQuantity(delta) {
            const quantity = Math.max(1, Math.min(this.quantity + delta, this.availableStock));

            if (quantity === this.quantity) {
                return;
            }

            this.saveQuantity(quantity);
        },

        async saveQuantity(quantity) {
            if (! Number.isInteger(quantity) || quantity < 1 || quantity > this.availableStock || quantity === this.quantity) {
                return;
            }

            this.failed = '';
            this.busy = true;

            try {
                this.applyResults(await this.request(this.urls.quantity, {
                    method: 'PATCH',
                    body: { quantity },
                }));
            } catch (error) {
                this.failed = error.message;

                if (this.$refs.quantity) {
                    this.$refs.quantity.value = this.quantity;
                }
            } finally {
                this.busy = false;
            }
        },

        rebuildOptions() {
            const select = this.$refs.quantity;

            if (select === undefined) {
                return;
            }

            const max = Math.max(10, this.quantity);

            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }

            for (let n = 1; n <= max; n++) {
                const option = document.createElement('option');

                option.value = n;
                option.textContent = n;
                option.disabled = n > this.availableStock;
                select.appendChild(option);
            }

            select.value = this.quantity;
        },

        async remove() {
            this.failed = '';
            this.busy = true;

            try {
                this.applyResults(await this.request(this.urls.remove, {
                    method: 'DELETE',
                }));
            } catch (error) {
                this.failed = error.message;
            } finally {
                this.busy = false;
            }
        },
    };
}