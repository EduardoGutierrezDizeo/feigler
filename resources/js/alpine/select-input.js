/**
 * Componente Alpine personalizado para reemplazar la lista nativa de <select>.
 *
 * - El <select> nativo permanece en el DOM (oculto visualmente, legible para
 *   Livewire y tests). Se usa como fuente de verdad.
 * - El panel lee las opciones del DOM al abrir: <option>, <optgroup label> y
 *   estados disabled/selected/placeholder.
 * - Posicionamiento fijo con JS (sin x-anchor). Se abre hacia arriba si no cabe.
 * - Sincroniza cambios desde Livewire (wire:model) hacia el botón y el select.
 * - Dispara eventos `input` y `change` con `bubbles: true` al elegir.
 * - ARIA completo, navegación por teclado y escritura tipo "typeahead".
 * - Solo activo en punteros finos (pointer: fine). En táctil se usa el nativo.
 * - Conserva id para label for (el botón recibe aria-labelledby o el label
 *   apunta al select nativo; mantenemos el id en el select oculto y referenciamos
 *   desde el botón con aria-controls/aria-haspopup y el label for funciona si
 *   apuntamos al id original: el select nativo conserva su id y tabindex -1).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('selectInput', (wireModel = null) => ({
        open: false,
        focusedIndex: -1,
        options: [],
        buttonLabel: '',
        isDisabled: false,
        isError: false,
        variant: 'field',
        pointerFine: false,
        modelName: wireModel,

        init() {
            this.pointerFine = window.matchMedia('(pointer: fine)').matches;
            const select = this.$refs.select;

            if (! select) {
                return;
            }

            this.isDisabled = select.disabled;
            this.variant = select.dataset.variant || 'field';
            this.isError = select.classList.contains('border-rojo') || select.classList.contains('border-ladrillo');

            this.syncFromSelect();

            // Sincroniza cuando cambia el valor desde Livewire
            if (wireModel) {
                this.$watch('wireModelValue', () => {
                    this.syncFromSelect();
                });
            }

            // Sincroniza cuando el usuario cambia el select nativo (por si acaso)
            select.addEventListener('change', () => {
                this.syncFromSelect();
                this.$dispatch('select-changed', { value: select.value });
            });

            // Cierra al hacer scroll fuera del panel (ventana u otro contenedor,
            // p. ej. un modal) o con resize. El scroll interno del propio panel
            // —el que produce la rueda y la barra del ratón— no debe cerrarlo.
            const isPanelScroll = (event) => {
                const panel = this.$refs.panel;

                if (! panel || ! (event.target instanceof Node)) {
                    return false;
                }

                return event.target === panel || panel.contains(event.target);
            };
            const closeOnScroll = (event) => {
                if (this.open && ! isPanelScroll(event)) {
                    this.close();
                }
            };
            // El resize va dirigido a window, que no es un Node: este listener
            // no mira event.target, solo cierra.
            const closeOnResize = () => {
                if (this.open) {
                    this.close();
                }
            };
            window.addEventListener('scroll', closeOnScroll, true);
            window.addEventListener('resize', closeOnResize);

            // Cierra con Escape global cuando abierto
            this.$watch('open', (value) => {
                if (value) {
                    document.body.style.overflow = '';
                }
            });
        },

        get wireModelValue() {
            if (! this.modelName || ! this.$wire) {
                return null;
            }
            return this.$wire.get(this.modelName);
        },

        syncFromSelect() {
            const select = this.$refs.select;
            if (! select) {
                return;
            }
            const selected = select.options[select.selectedIndex];
            this.buttonLabel = selected ? selected.text : '';
            this.isDisabled = select.disabled;
        },

        toggle() {
            if (! this.pointerFine || this.isDisabled) {
                return;
            }
            if (this.open) {
                this.close();
            } else {
                this.openPanel();
            }
        },

        openPanel() {
            if (! this.pointerFine || this.isDisabled || this.open) {
                return;
            }
            this.buildOptions();
            this.open = true;
            this.focusedIndex = this.findSelectedIndex();
            this.$nextTick(() => {
                this.positionPanel();
                this.scrollToFocused();
                this.$refs.panel?.focus({ preventScroll: true });
            });
        },

        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            this.$refs.button?.focus({ preventScroll: true });
        },

        buildOptions() {
            const select = this.$refs.select;
            const panelOptions = [];
            if (! select) {
                return;
            }
            let index = 0;
            for (let i = 0; i < select.children.length; i++) {
                const child = select.children[i];
                if (child.tagName === 'OPTGROUP') {
                    panelOptions.push({
                        type: 'group',
                        label: child.label,
                        disabled: child.disabled || false,
                    });
                    for (let j = 0; j < child.children.length; j++) {
                        const opt = child.children[j];
                        if (opt.tagName === 'OPTION') {
                            panelOptions.push({
                                type: 'option',
                                value: opt.value,
                                text: opt.text,
                                disabled: opt.disabled,
                                selected: opt.selected,
                                placeholder: opt.dataset.placeholder === 'true' || (opt.value === '' && opt.text.startsWith('—')),
                                index,
                            });
                            index++;
                        }
                    }
                } else if (child.tagName === 'OPTION') {
                    const opt = child;
                    panelOptions.push({
                        type: 'option',
                        value: opt.value,
                        text: opt.text,
                        disabled: opt.disabled,
                        selected: opt.selected,
                        placeholder: (opt.value === '' && opt.text.startsWith('—')) || opt.text === '— Sin portada —',
                        index,
                    });
                    index++;
                }
            }
            this.options = panelOptions;
        },

        findSelectedIndex() {
            const flat = this.options.filter(o => o.type === 'option');
            const found = flat.findIndex(o => o.selected);
            return found >= 0 ? found : 0;
        },

        positionPanel() {
            const button = this.$refs.button;
            const panel = this.$refs.panel;
            if (! button || ! panel) {
                return;
            }
            const rect = button.getBoundingClientRect();
            const panelRect = panel.getBoundingClientRect();
            const viewportHeight = window.innerHeight;
            const spaceBelow = viewportHeight - rect.bottom;
            const spaceAbove = rect.top;
            const margin = 8;

            // Abre hacia arriba si no cabe abajo
            if (spaceBelow < panelRect.height + margin && spaceAbove > spaceBelow) {
                panel.style.top = (rect.top - panelRect.height - margin) + 'px';
            } else {
                panel.style.top = (rect.bottom + margin) + 'px';
            }
            panel.style.left = rect.left + 'px';
            panel.style.width = rect.width + 'px';
            panel.style.position = 'fixed';
            panel.style.zIndex = '9999';
        },

        selectOption(option) {
            if (option.disabled) {
                return;
            }
            const select = this.$refs.select;
            if (! select) {
                return;
            }
            const flatOptions = select.querySelectorAll('option');
            // Busca por valor
            let target = Array.from(flatOptions).find(o => o.value === option.value);
            if (! target) {
                target = flatOptions[option.index] || null;
            }
            if (target) {
                select.value = target.value;
            }
            // Dispara eventos con bubbles
            const inputEvent = new Event('input', { bubbles: true });
            const changeEvent = new Event('change', { bubbles: true });
            select.dispatchEvent(inputEvent);
            select.dispatchEvent(changeEvent);
            this.syncFromSelect();
            this.close();
        },

        onButtonKeydown(event) {
            if (! this.pointerFine || this.isDisabled) {
                return;
            }
            switch (event.key) {
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    this.openPanel();
                    break;
                case 'ArrowDown':
                    event.preventDefault();
                    this.openPanel();
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    this.openPanel();
                    break;
            }
        },

        onPanelKeydown(event) {
            const flatOptions = this.options.filter(o => o.type === 'option' && ! o.disabled);
            const currentFlatIndex = this.options
                .filter(o => o.type === 'option')
                .findIndex(o => o === this.getFlatOptionAt(this.focusedIndex));

            switch (event.key) {
                case 'Escape':
                    event.preventDefault();
                    this.close();
                    break;
                case 'Tab':
                    event.preventDefault();
                    this.close();
                    break;
                case 'Enter':
                case ' ':
                    event.preventDefault();
                    const opt = this.getOptionAtFocused();
                    if (opt) {
                        this.selectOption(opt);
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
                    this.focusFirst();
                    break;
                case 'End':
                    event.preventDefault();
                    this.focusLast();
                    break;
                default:
                    if (event.key.length === 1) {
                        this.typeahead(event.key);
                    }
                    break;
            }
        },

        getOptionAtFocused() {
            let idx = 0;
            for (let i = 0; i < this.options.length; i++) {
                const o = this.options[i];
                if (o.type === 'option' && ! o.disabled) {
                    if (idx === this.focusedIndex) {
                        return o;
                    }
                    idx++;
                } else if (o.type === 'option') {
                    // skip disabled in focus count? but we track by flat enabled? easier: track by visible enabled index
                }
            }
            // simpler: track by index among enabled options
            return null;
        },

        moveFocus(delta) {
            const enabled = this.options
                .map((o, i) => ({ o, i }))
                .filter(x => x.o.type === 'option' && ! x.o.disabled);
            if (enabled.length === 0) {
                return;
            }
            const current = enabled.findIndex(x => x.i === this.currentOptionIndex());
            let next = current + delta;
            if (next < 0) {
                next = enabled.length - 1;
            }
            if (next >= enabled.length) {
                next = 0;
            }
            this.focusedIndex = enabled[next].i;
            this.focusFocusedOption();
        },

        currentOptionIndex() {
            const enabled = this.options.map((o, i) => ({ o, i })).filter(x => x.o.type === 'option' && ! x.o.disabled);
            return enabled[this.focusedIndex]?.i ?? -1;
        },

        /**
         * Enfoca la opción marcada y la deja visible moviendo SOLO el scrollTop
         * del panel. preventScroll evita que el navegador desplace el elemento
         * a la vista —y con él a los ancestros, lo que dispararía el scroll
         * global que cierra el panel—, y el ajuste manual sobre offsetTop y
         * offsetHeight evita llamar al desplazamiento del navegador, que
         * también mueve contenedores externos.
         * Lo usan solo los atajos de teclado, nunca el ratón.
         */
        focusFocusedOption() {
            const panel = this.$refs.panel;
            const target = panel?.querySelector(`[data-option-index="${this.focusedIndex}"]`);
            if (target) {
                target.focus({ preventScroll: true });
            }
            this.scrollToFocused();
        },

        focusFirst() {
            const enabled = this.options.map((o, i) => ({ o, i })).filter(x => x.o.type === 'option' && ! x.o.disabled);
            if (enabled.length > 0) {
                this.focusedIndex = enabled[0].i;
                this.focusFocusedOption();
            }
        },

        focusLast() {
            const enabled = this.options.map((o, i) => ({ o, i })).filter(x => x.o.type === 'option' && ! x.o.disabled);
            if (enabled.length > 0) {
                this.focusedIndex = enabled[enabled.length - 1].i;
                this.focusFocusedOption();
            }
        },

        scrollToFocused() {
            const panel = this.$refs.panel;
            if (! panel) {
                return;
            }
            const el = panel.querySelector(`[data-option-index="${this.focusedIndex}"]`);
            if (! el) {
                return;
            }
            const top = el.offsetTop;
            const bottom = top + el.offsetHeight;
            const visibleTop = panel.scrollTop;
            const visibleBottom = visibleTop + panel.clientHeight;
            if (top < visibleTop) {
                panel.scrollTop = top;
            } else if (bottom > visibleBottom) {
                panel.scrollTop = bottom - panel.clientHeight;
            }
        },

        typeahead(key) {
            // simple typeahead: buscar por primera letra
            const enabled = this.options.map((o, i) => ({ o, i })).filter(x => x.o.type === 'option' && ! x.o.disabled);
            const lower = key.toLowerCase();
            const current = this.currentOptionIndex();
            const start = enabled.findIndex(x => x.i === current);
            const candidates = [...enabled.slice(start + 1), ...enabled.slice(0, start + 1)];
            const found = candidates.find(x => x.o.text.toLowerCase().startsWith(lower));
            if (found) {
                this.focusedIndex = found.i;
                this.focusFocusedOption();
            }
        },

        getFlatOptionAt(index) {
            return this.options[index];
        },
    }));
});
