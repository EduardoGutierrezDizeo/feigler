// El selector de cantidad siempre ofrece esta lista; las opciones que superan el
// stock de la combinación elegida se deshabilitan sin desaparecer.
const QUANTITY_OPTIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

export default function productPurchase(config) {
    return {
        name: config.name ?? '',
        colors: config.colors ?? [],
        sizes: config.sizes ?? [],
        variants: config.variants ?? [],
        lowStock: config.lowStock ?? 3,
        colorIndex: config.initialColor ?? 0,
        imageIndex: 0,
        size: null,
        quantity: 1,
        adding: false,
        sizeMessage: '',
        message: '',

        get color() {
            return this.colors[this.colorIndex] ?? null;
        },

        get images() {
            return this.color?.images ?? [];
        },

        get image() {
            return this.images[this.imageIndex] ?? null;
        },

        get variant() {
            if (this.size === null || !this.color) return null;
            return this.variants.find((v) => v.color === this.color.id && v.size === this.size) ?? null;
        },

        get selectedStock() {
            return this.variant?.stock ?? 0;
        },

        get showLowStock() {
            return this.size !== null && this.selectedStock > 0 && this.selectedStock <= this.lowStock;
        },

        get canAdd() {
            return this.variant !== null && this.selectedStock > 0;
        },

        // Sin talla elegida solo se ofrece la unidad 1, con el selector apagado;
        // con talla elegida se listan siempre las 10 y se habilitan solo las que
        // caben en el stock real.
        get quantityOptions() {
            return this.size === null ? [1] : QUANTITY_OPTIONS;
        },

        get quantityDisabled() {
            return this.size === null || this.selectedStock <= 0;
        },

        quantityEnabled(quantity) {
            return this.size !== null && this.selectedStock > 0 && quantity <= this.selectedStock;
        },

        // Si la cantidad elegida ya no cabe en la nueva combinación (o no hay
        // talla, o se agotó), vuelve a 1.
        clampQuantity() {
            if (this.size === null || this.selectedStock <= 0 || this.quantity > this.selectedStock) {
                this.quantity = 1;
            }
        },

        // El contador -/+ de la ficha, acotado a las unidades que quedan de la
        // combinación elegida: sin talla o agotada el tope es 1 y no se mueve.
        step(delta) {
            const quantity = Math.max(1, Math.min(this.quantity + delta, this.selectedStock));

            if (quantity !== this.quantity) {
                this.quantity = quantity;
            }
        },

        stockFor(size) {
            const found = this.variants.find((v) => v.color === this.color?.id && v.size === size);
            return found?.stock ?? 0;
        },

        selectColor(index) {
            this.colorIndex = index;
            this.imageIndex = 0;
            if (this.size !== null && this.stockFor(this.size) <= 0) this.size = null;
            this.sizeMessage = '';
            this.clampQuantity();
        },

        selectSize(size) {
            if (this.stockFor(size) <= 0) return;
            this.size = size;
            this.sizeMessage = '';
            this.clampQuantity();
        },

        next() {
            if (this.images.length) this.imageIndex = (this.imageIndex + 1) % this.images.length;
        },

        prev() {
            if (this.images.length) this.imageIndex = (this.imageIndex - 1 + this.images.length) % this.images.length;
        },

        async addToCart() {
            if (this.adding) return;

            if (this.size === null) {
                this.sizeMessage = 'Elige una talla para continuar.';
                return;
            }

            if (this.selectedStock <= 0) {
                this.message = 'Agotado: no quedan unidades de esta talla.';
                return;
            }

            this.adding = true;
            this.sizeMessage = '';
            this.message = '';

            try {
                const response = await fetch('/carrito/agregar', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                    },
                    body: JSON.stringify({ variant_id: this.variant.id, quantity: this.quantity }),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload.ok === false) {
                    this.message = payload.message ?? 'No se pudo agregar al carrito. Intenta de nuevo.';
                    return;
                }

                this.message = payload.message ?? 'Agregado al carrito.';
                this.quantity = 1;
                window.dispatchEvent(new CustomEvent('cart-updated', { detail: { count: payload.count } }));
            } catch {
                this.message = 'No se pudo agregar al carrito. Intenta de nuevo.';
            } finally {
                this.adding = false;
            }
        },
    };
}