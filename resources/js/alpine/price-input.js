/**
 * Componente Alpine global para los campos de dinero.
 *
 * Se registra vía `Alpine.data('priceInput', ...)` desde el barrel
 * `resources/js/alpine/index.js`, que importan los dos bundles de entrada. En
 * Blade se usa como `x-data="priceInput($wire, 'basePrice', '89.900,50')"`, así que
 * el proveedor recibe el proxy `$wire`, el nombre de la propiedad de Livewire y
 * el monto ya formateado que el servidor puso en la vista.
 *
 * EL PUNTO Y LA COMA SON SOLO DE PRESENTACIÓN. El input no lleva `wire:model` a
 * propósito: Alpine es dueño del texto que se ve y le manda a Livewire el número
 * pelado, que es lo que viaja a la validación `numeric` y a la columna. Con
 * `wire:model`, Livewire leería del DOM «89.900,50», `numeric` lo rechazaría y la
 * base guardaría el string con punto y coma en lugar del 89900.50.
 *
 * El separador de miles es el punto y el de decimales la coma, los mismos que usa
 * `number_format($monto, $decimales, ',', '.')` en las vistas del panel, de modo
 * que el campo, el listado y los mensajes de validación hablan el mismo idioma.
 *
 * El servidor manda de vuelta con `$watch` cada vez que el precio cambia desde
 * PHP —"abrir otro producto, o el `resetForm()` que limpia el modal tras
 * guardar"— y entonces el input se repinta con sus separadores. Mientras se
 * escribe, lo que llega es el mismo número que ya se está mostrando, así que la
 * comparación evita reescribir el input y el cursor no salta de sitio.
 */

/**
 * Cuántos decimales se aceptan, los mismos que guarda `decimal(10, 2)`.
 *
 * @type {number}
 */
const MAX_DECIMALS = 2

/**
 * Separa el monto en entero y decimales, entendiendo las dos formas en que se
 * puede escribir un dinero.
 *
 * Con los dos separadores a la vez no hay duda posible: el último es el decimal y
 * el otro es de miles, así que «89.900,50» y «89,900.50» valen lo mismo. Con uno
 * solo, la coma es el decimal, porque en esta app el punto agrupa y la coma separa
 * céntimos, y un punto seguido de exactamente tres dígitos también agrupa («89.900»,
 * «1.234.567») mientras que cualquier otra cosa es un decimal («89900.50», «12.5»).
 *
 * El caso degenerado es un número pegado en formato inglés sin centavos, «89,900»:
 * aquí se lee como 89 con 90 céntimos, porque en el único formato de la app la
 * coma es decimal. Es el precio de no preguntarle a quien escribe qué idioma
 * numérico está usando.
 *
 * @param {string|number|null} value
 * @returns {{whole: string, decimals: string}}
 */
function splitAmount(value) {
    const cleaned = String(value ?? '').replace(/[^\d.,]/g, '')

    if (cleaned === '') {
        return { whole: '', decimals: '' }
    }

    let whole = cleaned
    let decimals = ''

    const lastComma = cleaned.lastIndexOf(',')
    const lastDot = cleaned.lastIndexOf('.')

    if (lastComma > -1 && lastDot > -1) {
        const decimalSeparator = Math.max(lastComma, lastDot)

        whole = cleaned.slice(0, decimalSeparator).replace(/[.,]/g, '')
        decimals = cleaned.slice(decimalSeparator + 1)
    } else if (lastComma > -1) {
        whole = cleaned.slice(0, lastComma)
        decimals = cleaned.slice(lastComma + 1)
    } else if (lastDot > -1) {
        const tail = cleaned.slice(lastDot + 1)

        if (tail.length === 3) {
            whole = cleaned.replace(/\./g, '')
        } else {
            whole = cleaned.slice(0, lastDot)
            decimals = tail
        }
    }

    return {
        whole: whole.replace(/^0+(?=\d)/, ''),
        decimals: decimals.replace(/\D/g, '').slice(0, MAX_DECIMALS),
    }
}

/**
 * Agrupa el entero con puntos cada tres dígitos y conserva los decimales donde el
 * usuario los puso.
 *
 * Se admiten las dos formas de entrada: el «89900.50» que viene de la base y el
 * «89.900,50» o «89,900.50» que se puede pegar. Los ceros de más delante se
 * quitan, y un monto escrito solo con decimales («,50») se completa con el cero
 * entero.
 *
 * @param {string|number|null} value
 * @returns {string}
 */
export function formatPrice(value) {
    const { whole, decimals } = splitAmount(value)

    if (whole === '' && decimals === '') {
        return ''
    }

    const grouped = (whole === '' ? '0' : whole).replace(/\B(?=(\d{3})+(?!\d))/g, '.')

    return decimals === '' ? grouped : `${grouped},${decimals}`
}

/**
 * Quita lo que es solo presentación y devuelve el número que se guarda.
 *
 * @param {string|number|null} value
 * @returns {string}
 */
export function unformatPrice(value) {
    const { whole, decimals } = splitAmount(value)

    if (whole === '' && decimals === '') {
        return ''
    }

    const integer = whole === '' ? '0' : whole

    return decimals === '' ? integer : `${integer}.${decimals}`
}

/**
 * El número que hay que escribir a partir del texto anterior, del nuevo y de la
 * tecla que se acaba de pulsar.
 *
 * Volver a reinterpretar el texto del input en cada tecla no sirve: en cuanto el
 * campo agrupa, «89.900» + «5» es «89.9005», y adivinar si ese punto agrupa o
 * separa céntimos devuelve 89 con 90 en vez de 899005. Lo que sí es inequívoco es
 * la tecla: si al final del campo se escribió un carácter más, o se borró uno,
 * el número es el de antes más o menos ese carácter, sin reinterpretar nada.
 *
 * Solo cuando el cambio no es una tecla al final —pegar, seleccionar, escribir en
 * medio— se interpreta el texto entero desde cero.
 *
 * @param {string} before Texto que había en el campo.
 * @param {string} after Texto que hay ahora.
 * @param {string} current Número sin formatear que representa `before`.
 * @param {boolean} caretAtEnd
 * @returns {string}
 */
function nextAmount(before, after, current, caretAtEnd) {
    if (caretAtEnd && before.length > 0) {
        if (after.length === before.length + 1 && after.startsWith(before)) {
            return current + after.slice(before.length)
        }

        if (after.length === before.length - 1 && before.startsWith(after)) {
            return current.slice(0, -1)
        }
    }

    return unformatPrice(after)
}

/**
 * El mismo número, con los decimales que la columna admite.
 *
 * Un punto sin cifras detrás se conserva: es la coma recién escrita esperando a
 * los céntimos, y perderlo haría que el «5» siguiente acabara pegado a los miles.
 *
 * @param {string} amount
 * @returns {string}
 */
function capDecimals(amount) {
    const dot = amount.indexOf('.')

    if (dot === -1) {
        return amount
    }

    const decimals = amount.slice(dot + 1)

    return decimals.length > MAX_DECIMALS ? `${amount.slice(0, dot)}.${decimals.slice(0, MAX_DECIMALS)}` : amount
}

/**
 * Si dos textos son el mismo número.
 *
 * La comparación es numérica y no de strings porque «1234.5» y «1234.50» son la
 * misma cantidad con distinto número de decimales: si se compararan como texto,
 * el eco del servidor añadiría el cero final y reescribiría el campo debajo del
 * cursor. También hace que un punto esperando los céntimos («89900.») cuente
 * como igual al «89900» que el servidor tiene guardado mientras tanto.
 *
 * @param {string} first
 * @param {string} second
 * @returns {boolean}
 */
function sameAmount(first, second) {
    if (first === second) {
        return true
    }

    return first !== '' && second !== '' && Number.isFinite(Number(first)) && Number(first) === Number(second)
}

document.addEventListener('alpine:init', () => {
    Alpine.data('priceInput', (wire, property, initial) => ({
        display: formatPrice(initial),

        /** El número sin formatear que hay detrás de lo que se ve. */
        amount: unformatPrice(formatPrice(initial)),

        init() {
            wire.$watch(property, (value) => {
                // Lo que llega es el mismo número que ya está escrito —el eco de
                // `$set`, o el `resetForm()` que vacía el modal—, así que no se
                // reescribe el campo y el cursor no salta de sitio. Si el número
                // es otro de verdad, es que lo cambió el servidor y se adopta.
                if (sameAmount(unformatPrice(value), this.amount)) {
                    return
                }

                this.amount = unformatPrice(value)
                this.display = formatPrice(this.amount)
            })
        },

        /**
         * Se llama en cada tecla: reformatea lo que hay escrito y manda el número
         * pelado a Livewire.
         *
         * Una coma o un punto escritos al final abren la parte decimal en vez de
         * perderse: si desapareciera en el mismo instante en que se pulsa, los
         * céntimos terminarían pegados a los miles y con «89.900» + «,» + «5» el
         * campo acabaría en «899.005».
         */
        format() {
            const element = this.$el
            const before = this.display
            const after = element.value
            const caretAtEnd = element.selectionStart === after.length && element.selectionEnd === after.length

            if (after !== before && /[.,]$/.test(after) && caretAtEnd && this.amount.indexOf('.') === -1) {
                this.amount += '.'
            } else {
                this.amount = nextAmount(before, after, this.amount, caretAtEnd)
            }

            this.amount = capDecimals(this.amount)
            this.display = formatPrice(this.amount)

            if (this.display !== '' && this.amount.endsWith('.')) {
                this.display += ','
            }

            wire.$set(property, unformatPrice(this.amount))
        },
    }))
})