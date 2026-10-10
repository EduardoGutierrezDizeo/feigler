/**
 * Selector de cantidad desplegable para la tienda.
 *
 * El <select> nativo sigue siendo la fuente de verdad —oculto a la vista pero
 * presente en el DOM, legible por los tests y por el resto de los componentes—;
 * el botón y el panel solo lo reflejan. A diferencia del `selectInput` del panel
 * —limitado a punteros finos— este responde también al toque.
 *
 * El botón muestra la cantidad con un `x-text` enlazado al componente contenedor
 * (el scope encadenado de Alpine deja leer `quantity` del componente padre), y
 * el panel lista las mismas <option> del select: las que superan el stock quedan
 * atenuadas y no son elegibles.
 *
 * Se registra vía `Alpine.data('quantitySelect', ...)` desde el barrel
 * `resources/js/alpine/index.js`.
 */
export default function quantitySelect() {
    return {
        open: false,
        options: [],
        focusedIndex: -1,
        root: null,

        init() {
            // La raíz solo es fiable dentro de init(): en un método que corre con
            // el `this` de una expresión (`@click`), `$el` apunta al elemento que
            // dispara esa expresión, no al componente.
            this.root = this.$el;

            const select = this.$refs.select;

            if (! select) {
                return;
            }

            // Las <option> cambian con la talla, el stock o la cantidad; mientras
            // el panel está abierto se reconstruyen.
            const observer = new MutationObserver(() => {
                if (this.open) {
                    this.buildOptions();
                }
            });
            observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });

            // Igual que el resto de desplegables: se cierra al hacer scroll o
            // resize fuera del panel.
            window.addEventListener('scroll', (event) => {
                if (this.open && ! this.isInsidePanel(event.target)) {
                    this.close();
                }
            }, true);
            window.addEventListener('resize', () => {
                if (this.open) {
                    this.close();
                }
            });
        },

        get select() {
            return this.$refs.select;
        },

        get isDisabled() {
            return this.select ? this.select.disabled : true;
        },

        isInsidePanel(target) {
            const panel = this.$refs.panel;

            return panel instanceof Node ? panel.contains(target) : panel === target;
        },

        buildOptions() {
            const select = this.select;

            if (! select) {
                return;
            }

            this.options = Array.from(select.options).map((option) => ({
                value: option.value,
                text: option.text,
                disabled: option.disabled,
            }));
        },

        enabledOptions() {
            return this.options
                .map((option, index) => ({ option, index }))
                .filter((entry) => ! entry.option.disabled);
        },

        toggle() {
            if (this.isDisabled) {
                return;
            }

            this.open ? this.close() : this.openPanel();
        },

        openPanel() {
            if (this.isDisabled || this.open) {
                return;
            }

            this.buildOptions();

            const enabled = this.enabledOptions();
            const selected = enabled.find((entry) => entry.option.value === this.select.value);

            this.focusedIndex = selected ? selected.index : (enabled[0]?.index ?? -1);
            this.open = true;

            this.$nextTick(() => {
                this.$refs.panel?.focus({ preventScroll: true });
                this.scrollToFocused();
            });
        },

        close() {
            if (! this.open) {
                return;
            }

            this.open = false;
            this.$refs.button?.focus({ preventScroll: true });
        },

        choose(option) {
            if (option.disabled) {
                return;
            }

            const select = this.select;

            if (! select) {
                return;
            }

            select.value = option.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));

            this.close();
        },

        onButtonKeydown(event) {
            if (this.isDisabled) {
                return;
            }

            if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                this.openPanel();
            }
        },

        onPanelKeydown(event) {
            switch (event.key) {
                case 'Escape':
                    event.preventDefault();
                    this.close();
                    break;
                case 'Tab':
                    this.close();
                    break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    if (this.options[this.focusedIndex]) {
                        this.choose(this.options[this.focusedIndex]);
                    }
                    break;
                case 'ArrowDown':
                    event.preventDefault();
                    this.moveFocus(1);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    this.moveFocus(-1);
                    break;
                case 'Home':
                    event.preventDefault();
                    this.focusEdge(0);
                    break;
                case 'End':
                    event.preventDefault();
                    this.focusEdge(-1);
                    break;
            }
        },

        moveFocus(delta) {
            const enabled = this.enabledOptions();

            if (enabled.length === 0) {
                return;
            }

            const current = enabled.findIndex((entry) => entry.index === this.focusedIndex);
            let next = current + delta;

            if (next < 0) {
                next = enabled.length - 1;
            }

            if (next >= enabled.length) {
                next = 0;
            }

            this.focusedIndex = enabled[next].index;
            this.focusFocused();
        },

        focusEdge(edge) {
            const enabled = this.enabledOptions();

            if (enabled.length === 0) {
                return;
            }

            this.focusedIndex = edge === 0 ? enabled[0].index : enabled[enabled.length - 1].index;
            this.focusFocused();
        },

        focusFocused() {
            const target = this.$refs.panel?.querySelector(`[data-option-index="${this.focusedIndex}"]`);

            if (target) {
                target.focus({ preventScroll: true });
            }

            this.scrollToFocused();
        },

        scrollToFocused() {
            const panel = this.$refs.panel;

            if (! panel) {
                return;
            }

            const target = panel.querySelector(`[data-option-index="${this.focusedIndex}"]`);

            if (! target) {
                return;
            }

            const top = target.offsetTop;
            const bottom = top + target.offsetHeight;

            if (top < panel.scrollTop) {
                panel.scrollTop = top;
            } else if (bottom > panel.scrollTop + panel.clientHeight) {
                panel.scrollTop = bottom - panel.clientHeight;
            }
        },
    };
}
