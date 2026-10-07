export default function priceRange(config) {
    return {
        min: config.min,
        max: config.max,
        step: config.step ?? 1000,
        from: config.from ?? config.min,
        to: config.to ?? config.max,

        get span() {
            return Math.max(this.max - this.min, 1);
        },

        get fromPct() {
            return ((this.from - this.min) / this.span) * 100;
        },

        get toPct() {
            return ((this.to - this.min) / this.span) * 100;
        },

        clampFrom() {
            const value = Number(this.from);
            this.from = Number.isFinite(value) ? Math.min(Math.max(value, this.min), this.to) : this.min;
        },

        clampTo() {
            const value = Number(this.to);
            this.to = Number.isFinite(value) ? Math.max(Math.min(value, this.max), this.from) : this.max;
        },
    };
}
