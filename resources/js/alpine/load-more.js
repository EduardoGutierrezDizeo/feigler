/**
 * «Mostrar más» de la sección, sin recargar la página.
 *
 * El enlace «Mostrar más» navega a la misma página con un paso más de «mostrar»
 * (mostrar 24, 36...). En vez de dejar que el navegador haga esa navegación —
 * que recolocaría la ventana arriba del todo—, se hace la petición con fetch,
 * se recortan del resultado solo las tarjetas nuevas (las que no están ya en el
 * grid, por su `data-product-id`) y se añaden debajo de las que ya estaban. El
 * scroll no se mueve de sitio: nada desplaza la ventana.
 *
 * Hay que marcar qué tarjetas son nuevas porque cada página devuelve el listado
 * completo hasta su «mostrar»: sin el recorte se duplicarían las ya visibles.
 *
 * Sin JavaScript el enlace sigue siendo un enlace normal y la sección funciona
 * recargando la página; esto es solo una mejora progresiva.
 */
export default function loadMore(config) {
    return {
        url: config.url ?? null,
        shown: config.shown ?? 0,
        total: config.total ?? 0,
        loading: false,

        async load() {
            if (this.loading || !this.url) return;

            this.loading = true;

            try {
                const response = await fetch(this.url);
                const html = await response.text();

                const incoming = new DOMParser().parseFromString(html, 'text/html');
                const known = [...this.$refs.grid.querySelectorAll('[data-product-id]')]
                    .map((card) => card.dataset.productId);
                const fresh = [...incoming.querySelectorAll('[data-product-id]')]
                    .filter((card) => !known.includes(card.dataset.productId));

                for (const card of fresh) {
                    this.$refs.grid.appendChild(card);
                }

                this.shown += fresh.length;

                const next = incoming.querySelector('[data-load-more]');
                this.url = next?.dataset.loadMore ?? null;
            } finally {
                this.loading = false;
            }
        },
    };
}