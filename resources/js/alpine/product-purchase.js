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
            return this.variant !== null && this.selectedStock > 0 && this.quantity >= 1 && this.quantity <= this.selectedStock;
        },

        stockFor(size) {
            const found = this.variants.find((v) => v.color === this.color?.id && v.size === size);
            return found?.stock ?? 0;
        },

        selectColor(index) {
            this.colorIndex = index;
            this.imageIndex = 0;
            if (this.size !== null && this.stockFor(this.size) <= 0) this.size = null;
            this.quantity = 1;
        },

        selectSize(size) {
            if (this.stockFor(size) <= 0) return;
            this.size = size;
            this.quantity = Math.min(Math.max(this.quantity, 1), this.selectedStock);
        },

        step(delta) {
            const max = this.size === null ? 99 : Math.max(this.selectedStock, 1);
            this.quantity = Math.min(Math.max(this.quantity + delta, 1), max);
        },

        next() {
            if (this.images.length) this.imageIndex = (this.imageIndex + 1) % this.images.length;
        },

        prev() {
            if (this.images.length) this.imageIndex = (this.imageIndex - 1 + this.images.length) % this.images.length;
        },

        addToCart() {
            if (!this.canAdd) return;
            this.$dispatch('add-to-cart', { variantId: this.variant.id, quantity: this.quantity });
        },
    };
}
