/**
 * Componente Alpine del modal de direcciones de Mi cuenta.
 *
 * Se registra vía `Alpine.data('addressModal', ...)` desde el barrel
 * `resources/js/alpine/index.js`. En Blade se usa como
 * `x-data="addressModal({ open, mode, addressId, … })"`: el servidor decide el
 * estado inicial cuando el último envío falló, para que la pestaña Direcciones
 * reabra el modal en el modo correcto (crear o editar).
 *
 * Abre con el evento de ventana `open-address` (detail.mode: create | edit |
 * delete, y detail.id), que las tarjetas y el botón «Agregar dirección» disparan
 * con `$dispatch`. Dos vistas con `x-show` + `x-cloak` (nunca `x-if`): el
 * formulario de alta/edición y la confirmación de eliminación. El departamento
 * filtra las ciudades: los datos de ubicación llegan una sola vez y el selector
 * de ciudad se rellena en el cliente.
 */
export default function addressModal(config = {}) {
    const modes = ['create', 'edit', 'delete'];
    const fieldNames = ['recipient_name', 'phone', 'department_code', 'city_code', 'label', 'line1', 'line2', 'instructions'];
    const addresses = config.addresses ?? {};
    const oldInput = config.old ?? {};
    const createDefault = {
        ...(config.create ?? {}),
        recipient_name: config.create?.nombre ?? '',
        phone: config.create?.telefono ?? '',
    };

    return {
        open: Boolean(config.open),
        mode: modes.includes(config.mode) ? config.mode : 'create',
        addressId: config.addressId ?? null,
        storeUrl: config.storeUrl ?? '',
        updateUrl: config.updateUrl ?? '',
        deleteUrl: config.deleteUrl ?? '',
        form: {},
        deleteSummary: '',
        departments: config.ubicaciones?.departments ?? [],
        citiesByDepartment: config.ubicaciones?.cities ?? {},
        focusableSelector: 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])',
        returnFocusTo: null,

        init() {
            this.$watch('open', (value) => this.sync(value));

            this.prepare();

            if (this.open) {
                this.sync(true);
            }
        },

        get cityOptions() {
            return this.citiesByDepartment[this.form.department_code] ?? [];
        },

        get editAddress() {
            return this.addressId ? (addresses[this.addressId] ?? null) : null;
        },

        /**
         * Llena el formulario según el modo. Los datos viejos del último envío
         * tienen prioridad (recarga con errores); en edición, si no los hay, se
         * usan los datos guardados; en creación, el nombre y el teléfono del
         * perfil del cliente.
         */
        prepare() {
            const base = this.mode === 'edit'
                ? (this.editAddress ?? createDefault)
                : createDefault;

            this.form = Object.fromEntries(fieldNames.map((field) => [
                field,
                Object.hasOwn(oldInput, field) ? oldInput[field] : (base[field] ?? ''),
            ]));

            if (this.mode === 'delete') {
                this.deleteSummary = this.summaryOf(this.editAddress);
            }
        },

        /**
         * Abre el modal en un modo y, si se da, para una dirección concreta.
         * Cuando ya estaba abierto, solo conmuta de modo y vuelve a enfocar el
         * primer campo.
         */
        openModal(mode, id) {
            if (modes.includes(mode)) {
                this.mode = mode;
            }

            this.addressId = id ?? null;
            this.prepare();

            if (! this.open) {
                this.returnFocusTo = document.activeElement;
                this.open = true;
                this.$nextTick(() => this.focusFirst());

                return;
            }

            this.$nextTick(() => this.focusFirst());
        },

        /**
         * Al cambiar de departamento se limpia la ciudad: la dependencia vive en
         * el cliente, porque los municipios ya están en la página.
         */
        changeDepartment() {
            this.form.city_code = '';
        },

        close() {
            this.open = false;
        },

        summaryOf(address) {
            if (! address) {
                return '';
            }

            const line2 = address.line2 ? ', '.concat(address.line2) : '';

            return ''.concat(address.line1, line2, ', ', address.city, ' · ', address.department);
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