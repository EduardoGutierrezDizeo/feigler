/**
 * Componente Alpine global para escribir un hexadecimal de color de dos maneras.
 *
 * Se registra vía `Alpine.data('colorHexInput', ...)` desde el barrel
 * `resources/js/alpine/index.js`, que importan los dos bundles de entrada. En Blade se
 * usa como `x-data="colorHexInput($wire, 'hex', '#1A2B3C')"`, así que el proveedor
 * recibe el proxy `$wire`, el nombre de la propiedad de Livewire y el hexadecimal que el
 * servidor puso en la vista.
 *
 * EL CAMPO DE TEXTO NO LLEVA `wire:model`, Y ESTO NO ES CASUAL. Un input con
 * `wire:model` y `x-model` a la vez tiene dos dueños peleándose por el mismo valor del
 * DOM: Alpine escribe cuando se teclea y Livewire lo reescribe en cada morph, así que
 * el texto se pierde a mitad de escribir y el cursor salta de sitio. Aquí Alpine es
 * dueño de los dos campos y manda el valor a Livewire con `$set()`, que es el mismo
 * camino que sigue `priceInput`. Cuando el servidor devuelve otra cosa —el eco de un
 * `$set`, o el `resetForm()` que limpia el modal tras guardar— lo recoge el `$watch` y
 * repinta los dos campos.
 *
 * LA MUESTRA SOLO SE MUEVE CON UN HEXADECIMAL COMPLETO. Un `<input type="color">` no
 * admite otra cosa, así que mientras el texto sea `#1a2b` la muestra se queda donde
 * estaba en lugar de saltando a negro en cada tecla. En el otro sentido el texto se
 * escribe siempre: elegir un color en la muestra es una Tecla y medio de teclear.
 */

/** Un `#` seguido de exactamente seis dígitos hexadecimales. */
const COLOR_HEX = /^#[0-9A-Fa-f]{6}$/

/** La muestra que se pinta cuando no hay un hexadecimal que enseñar. */
const FALLBACK_SWATCH = '#000000'

/**
 * Si el valor es un hexadecimal completo, que es lo único que la muestra admite.
 *
 * @param {string|null|undefined} value
 * @returns {boolean}
 */
function isColorHex(value) {
    return COLOR_HEX.test(String(value ?? '').trim())
}

document.addEventListener('alpine:init', () => {
    Alpine.data('colorHexInput', (wire, property, initial) => ({
        /** Lo que se ve en el campo de texto, incluso a medio escribir. */
        text: String(initial ?? ''),

        /** Lo que pinta el `<input type="color">`. */
        swatch: isColorHex(initial) ? String(initial).toUpperCase() : FALLBACK_SWATCH,

        init() {
            wire.$watch(property, (value) => {
                const hex = String(value ?? '').trim()

                this.text = hex
                this.swatch = isColorHex(hex) ? hex.toUpperCase() : FALLBACK_SWATCH
            })
        },

        /**
         * El texto manda: la muestra se mueve cuando lo escrito ya es un hexadecimal
         * completo, y el servidor recibe lo que hay —también lo que todavía no lo es,
         * para que sea la validación la que lo diga en su idioma y no este campo.
         */
        syncFromText() {
            const hex = this.text.trim()

            if (isColorHex(hex)) {
                this.swatch = hex.toUpperCase()
            }

            wire.$set(property, hex)
        },

        /**
         * La muestra manda: se escribe su valor en el texto y se manda al servidor.
         *
         * El navegador entrega el valor en minúsculas, y la columna guarda el
         * hexadecimal en mayúsculas, así que se sube antes de escribirlo para que el
         * campo y lo guardado se lean igual.
         */
        syncFromColor() {
            this.swatch = this.swatch.toUpperCase()
            this.text = this.swatch

            wire.$set(property, this.swatch)
        },
    }))
})