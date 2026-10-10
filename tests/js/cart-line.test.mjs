/**
 * Pruebas de escritorio de los componentes del carrito, sin navegador ni
 * dependencias: se corre el propio módulo de `resources/js/alpine` con un
 * `window` y unos elementos falsos.
 *
 * No sustituye a la suite de Pest —eso cubre el contrato del servidor—; cubre
 * justo lo que el servidor no puede: cómo reacciona el JavaScript del carrito
 * cuando se lo ejecuta. El fallo que motivó estas pruebas fue que
 * `cart-line.js` borraba el botón en vez de la fila, porque `this.$el` dentro
 * de `remove()` es el elemento que disparó el clic y no la raíz del componente.
 *
 * Se ejecuta con `node tests/js/cart-line.test.mjs` (Node 18+).
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import cartBadge from '../../resources/js/alpine/cart-badge.js';
import cartLine from '../../resources/js/alpine/cart-line.js';
import cartPage from '../../resources/js/alpine/cart-page.js';

/** Un elemento con lo justo que los componentes tocan: `remove()`. */
class FakeElement {
    constructor(name) {
        this.name = name;
        this.removed = false;
    }

    remove() {
        this.removed = true;
    }
}

/** Un `window` mínimo que guarda oyentes y los dispara por tipo de evento. */
function fakeWindow() {
    const listeners = new Map();

    return {
        addEventListener(type, callback) {
            if (!listeners.has(type)) {
                listeners.set(type, []);
            }

            listeners.get(type).push(callback);
        },
        removeEventListener() {},
        dispatchEvent(event) {
            (listeners.get(event.type) ?? []).forEach((callback) => callback(event));

            return true;
        },
    };
}

/** El `location` global con un `reload()` que solo se cuenta. */
function fakeLocation() {
    return {
        reloads: 0,
        reload() {
            this.reloads++;
        },
    };
}

/**
 * Una línea con su fila y su botón separados, tal como quedan en el DOM. `init()`
 * corre con la fila como `$el` (es el elemento del `x-data`); después se cambia
 * `$el` al botón, que es lo que vería el `this` de la expresión `@click`.
 */
function lineWithRow(id) {
    const row = new FakeElement(`fila-${id}`);
    const button = new FakeElement(`boton-${id}`);

    const component = cartLine({
        id,
        quantity: 1,
        subtotal: 1000,
        status: 'available',
        available_stock: 5,
        notice: '',
        urls: { quantity: `/carrito/lineas/${id}`, remove: `/carrito/lineas/${id}` },
    });

    component.$el = row;
    component.init();
    component.$el = button;

    component.request = async () => ({ summary: { items: [], subtotal: 0 }, count: 0 });

    return { row, button, component };
}

test('remove() borra la fila y no el botón que disparó el clic', async () => {
    globalThis.window = fakeWindow();
    globalThis.document = { querySelector: () => null };

    const { row, button, component } = lineWithRow(5);

    await component.remove();

    assert.equal(row.removed, true, 'se quitó la fila');
    assert.equal(button.removed, false, 'el botón sigue en su sitio');
});

test('eliminar dos líneas seguidas quita las dos filas', async () => {
    globalThis.window = fakeWindow();
    globalThis.document = { querySelector: () => null };

    const primera = lineWithRow(1);
    const segunda = lineWithRow(2);

    await primera.component.remove();
    await segunda.component.remove();

    assert.equal(primera.row.removed, true, 'la primera fila se quitó');
    assert.equal(segunda.row.removed, true, 'la segunda fila también');
    assert.equal(primera.button.removed, false);
    assert.equal(segunda.button.removed, false);
});

test('quitar la última línea deja la página en estado vacío', () => {
    globalThis.window = fakeWindow();

    const page = cartPage({ subtotal: 3000, units: 2, empty: false });

    // El resumen que devuelve el servidor cuando ya no queda ninguna línea.
    page.apply({ summary: { items: [], subtotal: 0 }, count: 0 });

    assert.equal(page.empty, true);
    assert.equal(page.units, 0);
    assert.equal(page.subtotal, 0);
});

test('el contador del encabezado solo pide el recuento al volver de la caché', async () => {    globalThis.window = fakeWindow();

    let fetches = 0;
    globalThis.fetch = async () => {
        fetches++;

        return { ok: true, json: async () => ({ count: 7 }) };
    };

    const badge = cartBadge({ count: 2, countUrl: '/carrito/contador' });
    badge.init();

    globalThis.window.dispatchEvent({ type: 'pageshow', persisted: false });
    await Promise.resolve();
    assert.equal(fetches, 0, 'una carga normal no pide nada');

    globalThis.window.dispatchEvent({ type: 'pageshow', persisted: true });
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(fetches, 1, 'la vuelta desde la caché sí pide el recuento');
    assert.equal(badge.count, 7);
});

test('la página del carrito se recarga al volver de la caché', () => {
    globalThis.window = fakeWindow();
    globalThis.location = fakeLocation();

    const page = cartPage({ subtotal: 0, units: 0, empty: true });
    page.init();

    globalThis.window.dispatchEvent({ type: 'pageshow', persisted: false });
    assert.equal(globalThis.location.reloads, 0, 'una carga normal no recarga');

    globalThis.window.dispatchEvent({ type: 'pageshow', persisted: true });
    assert.equal(globalThis.location.reloads, 1, 'la vuelta desde la caché recarga');
});

test('el nombre accesible del carrito cuenta las unidades y concuerda el singular', () => {
    globalThis.window = fakeWindow();

    const vacio = cartBadge({ count: 0, countUrl: '/carrito/contador' });
    assert.equal(vacio.ariaLabel(), 'Carrito', 'sin unidades no hay número');

    const una = cartBadge({ count: 1, countUrl: '/carrito/contador' });
    assert.equal(una.ariaLabel(), 'Carrito (1 unidad)', 'una unidad va en singular');

    const varias = cartBadge({ count: 4, countUrl: '/carrito/contador' });
    assert.equal(varias.ariaLabel(), 'Carrito (4 unidades)', 'varias van en plural');
});
