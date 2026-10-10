/**
 * Formato de precios en la tienda.
 *
 * Es el mismo estilo que imprime `number_format($amount, 0, ',', '.')` en Blade:
 * números sin decimales y separador de miles con punto. `formatMoney` recibe el
 * valor íntegro (sin centavos) y devuelve el texto con el símbolo `$` y el
 * punto de miles, para que el repintado del carrito coincida exactamente con lo
 * que el servidor imprime en la primera carga.
 *
 * @param {number|string} amount
 * @return {string}
 */
export default function formatMoney(amount) {
    const integer = String(Math.trunc(Number(amount) || 0))
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `$${integer}`;
}