/**
 * Panel de administración: capa Alpine compartida por las páginas de Livewire.
 *
 * Este bundle NO importa Alpine a propósito. Livewire ya lo incluye y lo
 * arranca dentro de livewire.js, así que registrar la store en `alpine:init`
 * la deja disponible antes de que se inicialice cualquier componente y evita el
 * error "Detected multiple instances of Alpine running".
 */

import { createRowMover } from './admin/row-mover.js';

const TOAST_TONES = ['success', 'error'];
const TOAST_DURATION = 5000;

let toastSequence = 0;

document.addEventListener('alpine:init', () => {
    window.Alpine.store('toast', {
        items: [],

        /**
         * Agrega un aviso a la pila y programa su desaparición.
         *
         * @param {string} message
         * @param {'success'|'error'} [tone]
         */
        push(message, tone = 'success') {
            const text = String(message ?? '').trim()

            if (! text) {
                return null
            }

            const item = {
                id: ++toastSequence,
                message: text,
                tone: TOAST_TONES.includes(tone) ? tone : 'success',
            }

            this.items.push(item)

            window.setTimeout(() => this.dismiss(item.id), TOAST_DURATION)

            return item
        },

        success(message) {
            return this.push(message, 'success')
        },

        error(message) {
            return this.push(message, 'error')
        },

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id)
        },

        clear() {
            this.items = []
        },
    })
})

/**
 * Puente entre los avisos que despacha Livewire desde PHP y la pila de toasts.
 * `$this->dispatch('toast', …)` llega a la ventana como un evento de navegador
 * una vez morphizado el DOM.
 */
window.addEventListener('toast', (event) => {
    const store = window.Alpine?.store('toast')

    if (! store) {
        return
    }

    store.push(event.detail?.message, event.detail?.tone)
})

/**
 * Anima el reordenamiento de las listas con FLIP (First-Last-Invert-Play).
 *
 * Se expone como `window.moveReorder` para que las filas llamen
 * `moveReorder.capture($el.closest('[data-flip-scope]'))` en el mismo clic que
 * dispara `wire:click`, capturando las posiciones antes de que salga la petición.
 *
 * Un único hook global cubre todas las páginas: los morph de Livewire siempre
 * ocurren después del anterior, así que en cualquier momento hay como máximo
 * una tanda de inversión/reproducción en curso.
 */
window.moveReorder = createRowMover({
    duration: 280,
    onFinish: (el) => {
        el.classList.remove('row-is-moving')
    },
})

if (window.Livewire?.hook) {
    window.Livewire.hook('morphed', ({ el, component }) => {
        window.moveReorder.morphed(el, component)
    })
}
