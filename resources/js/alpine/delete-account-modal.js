/**
 * Componente Alpine del modal de confirmación para eliminar la cuenta.
 *
 * Se registra vía `Alpine.data('deleteAccountModal', ...)` desde el barrel
 * `resources/js/alpine/index.js`. En Blade se usa como
 * `x-data="deleteAccountModal({ open })"`: el servidor decide si arranca
 * abierto cuando el último envío falló (bolsa «deleteAccount»), igual que los
 * otros modales de la tienda.
 *
 * Abre con el evento de ventana `open-delete-account`, que el botón de la
 * pestaña dispara con `$dispatch`. Cierra con Escape, con el fondo o con el
 * botón «Cancelar», bloquea el scroll y devuelve el foco al que abrió. Al
 * abrir enfoca el campo de contraseña: X y Cancelar devuelven el foco junto.
 */
export default function deleteAccountModal(config = {}) {
    return {
        open: Boolean(config.open),
        returnFocusTo: null,
        focusableSelector: 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])',

        init() {
            this.$watch('open', (value) => this.sync(value));

            if (this.open) {
                this.sync(true);
            }
        },

        openModal() {
            if (! this.open) {
                this.returnFocusTo = document.activeElement;
                this.open = true;
                this.$nextTick(() => this.focusPassword());

                return;
            }

            this.$nextTick(() => this.focusPassword());
        },

        close() {
            this.open = false;
        },

        /**
         * Efectos secundarios de la visibilidad: bloquea el scroll del fondo al
         * abrir, enfoca el campo de contraseña y devuelve el foco al que abrió.
         */
        sync(isOpen) {
            document.body.classList.toggle('overflow-hidden', isOpen);

            if (isOpen) {
                this.returnFocusTo = this.returnFocusTo ?? document.activeElement;
                this.$nextTick(() => this.focusPassword());

                return;
            }

            this.returnFocusTo?.focus?.();
            this.returnFocusTo = null;
        },

        focusPassword() {
            (this.$refs.deletePassword ?? this.$refs.panel)?.focus?.();
        },

        focusables() {
            const nodes = this.$refs.panel?.querySelectorAll(this.focusableSelector) ?? [];

            return [...nodes].filter((el) => el.offsetParent !== null || el === document.activeElement);
        },

        /**
         * Atrapa el Tab dentro del modal sin añadir dependencias: si el foco está
         * en el primer o último elemento visible, lo salta al otro extremo.
         */
        trap(event) {
            if (event.key !== 'Tab') {
                return;
            }

            const items = this.focusables();

            if (items.length === 0) {
                event.preventDefault();

                return;
            }

            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}