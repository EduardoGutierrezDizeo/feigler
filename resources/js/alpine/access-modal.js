/**
 * Componente Alpine del modal de acceso de la tienda.
 *
 * Se registra vía `Alpine.data('accessModal', ...)` desde el barrel
 * `resources/js/alpine/index.js`, que importan los dos bundles de entrada. En
 * Blade se usa como `x-data="accessModal({ view: …, open: … })"`: el servidor
 * decide la vista y si arranca abierto según la bolsa de errores o la marca de
 * recuperación, para que un envío fallido del modal vuelva a mostrarlo abierto.
 *
 * El modal abre con el evento de ventana `open-access` (detail.view), que el
 * encabezado dispara con `$dispatch('open-access', { view: 'login' })`; así el
 * botón «Cuenta» de invitados no necesita conocer el componente. Solo hay una
 * vista visible a la vez (`x-show` + `x-cloak`, nunca `x-if`), de modo que los
 * tres formularios permanecen en el DOM y sus envíos siguen siendo normales.
 */
export default function accessModal(config = {}) {
    const views = ['login', 'register', 'forgot'];
    const initialView = views.includes(config.view) ? config.view : 'login';

    return {
        open: Boolean(config.open),
        view: initialView,
        titles: {
            login: 'Iniciar sesión',
            register: 'Crear cuenta',
            forgot: 'Recuperar contraseña',
        },
        returnFocusTo: null,
        focusableSelector: 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])',

        init() {
            this.$watch('open', (value) => this.sync(value));

            if (this.open) {
                this.sync(true);
            }
        },

        /**
         * Cambia de vista y abre el modal. Si ya estaba abierto, solo conmuta la
         * vista y vuelve a enfocar el primer campo de la nueva.
         */
        openView(view) {
            if (views.includes(view)) {
                this.view = view;
            }

            if (! this.open) {
                this.returnFocusTo = document.activeElement;
                this.open = true;
                this.$nextTick(() => this.focusFirst());

                return;
            }

            this.$nextTick(() => this.focusFirst());
        },

        close() {
            this.open = false;
        },

        /**
         * Efectos secundarios de la visibilidad: bloquea el scroll del fondo al
         * abrir, enfoca el primer campo y devuelve el foco al elemento que abrió.
         */
        sync(isOpen) {
            document.body.classList.toggle('overflow-hidden', isOpen);

            if (isOpen) {
                this.returnFocusTo = this.returnFocusTo ?? document.activeElement;
                this.$nextTick(() => this.focusFirst());

                return;
            }

            this.returnFocusTo?.focus?.();
            this.returnFocusTo = null;
        },

        focusFirst() {
            (this.focusables()[0] ?? this.$refs.panel)?.focus?.();
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
