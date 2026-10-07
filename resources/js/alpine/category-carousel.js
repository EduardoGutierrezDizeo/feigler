export default function categoryCarousel(config) {
    return {
        sections: config.sections ?? [],
        section: config.initial ?? null,
        page: 0,
        pages: 1,
        atStart: true,
        atEnd: false,

        get current() {
            return this.sections.find((s) => s.key === this.section) ?? this.sections[0] ?? { categories: [], label: '', url: '#' };
        },

        init() {
            this.$nextTick(() => this.measure());
        },

        panel(key = this.section) {
            return this.$el.querySelector(`[data-category-panel="${key}"]`);
        },

        track() {
            return this.panel()?.querySelector('[data-category-track]');
        },

        measure() {
            const track = this.track();
            if (!track) return;
            this.pages = Math.max(1, Math.ceil((track.scrollWidth - 8) / track.clientWidth));
            this.update();
        },

        update() {
            const track = this.track();
            if (!track) return;
            this.atStart = track.scrollLeft <= 4;
            this.atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
            this.page = Math.min(this.pages - 1, Math.round(track.scrollLeft / track.clientWidth));
        },

        scroll(direction) {
            const track = this.track();
            track?.scrollBy({ left: direction * track.clientWidth * 0.8, behavior: 'smooth' });
        },

        goTo(index) {
            const track = this.track();
            track?.scrollTo({ left: index * track.clientWidth, behavior: 'smooth' });
        },

        paintPanel(key, active) {
            const panel = this.panel(key);
            if (!panel) return;
            panel.inert = !active;
            panel.classList.toggle('invisible', !active);
            panel.classList.toggle('opacity-0', !active);
            panel.classList.toggle('translate-y-1', !active);
            panel.classList.toggle('pointer-events-none', !active);
            if (active) {
                panel.removeAttribute('aria-hidden');
            } else {
                panel.setAttribute('aria-hidden', 'true');
            }
        },

        setSection(key) {
            if (key === this.section) return;
            const previous = this.section;
            this.section = key;
            this.$nextTick(() => {
                this.paintPanel(previous, false);
                this.paintPanel(key, true);
                this.panel(key)?.querySelector('[data-category-track]')?.scrollTo({ left: 0 });
                this.measure();
            });
        },
    };
}