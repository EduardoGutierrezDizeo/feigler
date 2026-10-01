/**
 * Componente Alpine global para el diálogo de confirmación.
 *
 * Se registra vía `Alpine.data('confirmDialog', ...)`, pero solo cuando este
 * módulo se importa: el barrel `resources/js/alpine/index.js` lo carga y a su
 * vez lo importan los dos bundles de entrada (app.js y admin.js). En Blade se
 * usa como `x-data="confirmDialog"`, y la visibilidad se ata a `$data.open` en
 * lugar de `open` para que un registro fallido no termine resolviendo contra
 * el global `window.open`.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('confirmDialog', () => ({
        open: false,
        title: '',
        message: '',
        confirmLabel: 'Confirmar',
        destructive: false,
        onConfirm: null,

        ask(event) {
            const options = event.detail ?? {}

            this.title = options.title ?? '¿Confirmas la acción?'
            this.message = options.message ?? ''
            this.confirmLabel = options.confirmLabel ?? 'Confirmar'
            this.destructive = Boolean(options.destructive)
            this.onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null
            this.open = true
        },

        confirm() {
            const action = this.onConfirm

            this.reset()

            if (action) {
                action()
            }
        },

        reset() {
            this.open = false
            this.title = ''
            this.message = ''
            this.confirmLabel = 'Confirmar'
            this.destructive = false
            this.onConfirm = null
        },

        focusables() {
            const selector = 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])'

            return [...$el.querySelectorAll(selector)].filter(
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

        init() {
            this.$watch('open', (value) => {
                if (value) {
                    document.body.classList.add('overflow-y-hidden')
                    this.$nextTick(() => this.focusables()[0]?.focus())
                } else {
                    document.body.classList.remove('overflow-y-hidden')
                }
            })
        },
    }))
})