/**
 * Componente Alpine global para el modal de formularios del panel admin.
 *
 * Se registra vía `Alpine.data('adminModal', ...)` desde el barrel
 * `resources/js/alpine/index.js`, que importan los dos bundles de entrada. En
 * Blade se usa como `x-data="adminModal($wire)"`, así que el proveedor recibe el
 * proxy `$wire` como argumento. La visibilidad se ata a `$data.open` en lugar de
 * `open` para que un registro fallido no termine resolviendo contra el global
 * `window.open`.
 *
 * El `$wire` llega por parámetro y el enlace se declara en la propia definición
 * de `open`, nunca dentro de `init()`, por una razón concreta:
 * `$wire.entangle()` NO devuelve el valor de la propiedad, devuelve un
 * *interceptor* de Alpine. Ese interceptor solo se activa si la propiedad está
 * presente en el objeto de `x-data` cuando Alpine la recorre con
 * `initInterceptors()`, y ese recorrido ocurre ANTES de que se invoque `init()`.
 * Asignado en `init()`, el interceptor se quedaba como un objeto plano: truthy,
 * de modo que `x-show="$data.open"` abría el modal al cargar la página, y sin
 * enlace con el servidor, por lo que ni la X ni «Cancelar» conseguían cerrarlo.
 *
 * A diferencia de `confirmDialog`, aquí la visibilidad NO es un estado local:
 * la decide el componente Livewire padre. `open` es un `entangle` de la
 * propiedad `showForm`, de modo que `create()`, `createSubcategory()` y `edit()`
 * la abren desde el servidor y `closeForm()` la cierra, y en ambos sentidos
 * Alpine reproduce la transición de `x-show`.
 *
 * El enlace es bidireccional a propósito: el nodo del modal está siempre
 * montado (no hay `@if` en la vista de Livewire), así que la transición de
 * salida necesita un estado real al que volver en lugar de desaparecer del
 * DOM en el mismo instante en que el servidor borra el formulario.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('adminModal', (wire) => ({
        open: wire.entangle('showForm'),
        returnFocusTo: null,

        init() {
            this.$watch('open', (value) => this.syncVisibility(value))

            this.syncVisibility(this.open)
        },

        /**
         * Sincroniza los efectos secundarios del modal con su visibilidad:
         * bloquea el scroll del fondo, enfoca el primer campo al abrir y
         * devuelve el foco al elemento que abrió el formulario.
         */
        syncVisibility(isOpen) {
            document.body.classList.toggle('overflow-y-hidden', isOpen)

            if (isOpen) {
                this.returnFocusTo = document.activeElement
                this.$nextTick(() => this.initialFocus()?.focus())
            } else {
                this.returnFocusTo?.focus?.()
                this.returnFocusTo = null
            }
        },

        close() {
            this.$wire.closeForm()
        },

        /**
         * El primer campo del formulario lleva `data-modal-autofocus` para que
         * abrirlo no exija primero tabularmente el botón de cerrar. Si algún
         * formulario no lo declara, cae al primer elemento enfocable de la
         * tarjeta, que es el propio botón de cerrar.
         */
        initialFocus() {
            return this.$root.querySelector('[data-modal-autofocus]') ?? this.focusables()[0]
        },

        focusables() {
            const selector = 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])'

            return [...this.$root.querySelectorAll(selector)].filter(
                (el) => el.offsetParent !== null || el === document.activeElement,
            )
        },

        trap(event) {
            const focusables = this.focusables()

            if (focusables.length === 0) {
                event.preventDefault()

                return
            }

            const first = focusables[0]
            const last = focusables[focusables.length - 1]

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault()
                last.focus()
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        },
    }))
})