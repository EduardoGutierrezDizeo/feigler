/**
 * Pruebas de escritorio de `quantitySelect`, el desplegable de cantidad de la
 * tienda, sin navegador ni dependencias: se corre el propio módulo de
 * `resources/js/alpine` con unos elementos falsos.
 *
 * Lo que el servidor no puede cubrir es que el componente hable con el
 * `<select>` nativo —la fuente de verdad, oculta— cuando se lo ejecuta: que
 * `choose()` cambie su valor y dispare `input` + `change` (lo que leen `x-model`
 * y el `@change` del carrito), que las opciones se reconstruyan desde el select
 * y que las que superan el stock no sean elegibles.
 *
 * Se ejecuta con `node tests/js/quantity-select.test.mjs` (Node 18+).
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import quantitySelect from '../../resources/js/alpine/quantity-select.js';

/** Una `<option>` falsa con lo justo que el componente lee. */
class FakeOption {
    constructor(value, text, disabled = false) {
        this.value = String(value);
        this.text = String(text);
        this.disabled = disabled;
    }
}

/** Un `<select>` falso que registra los eventos que recibe. */
class FakeSelect {
    constructor(options) {
        this.options = options;
        this.value = options.length > 0 ? options[0].value : '';
        this.disabled = false;
        this.dispatched = [];
    }

    dispatchEvent(event) {
        this.dispatched.push(event.type);

        return true;
    }
}

/** Un elemento con `focus()` y `querySelector()` que nunca encuentra nada. */
function stubElement() {
    return {
        focused: 0,
        focus() {
            this.focused++;
        },
        querySelector() {
            return null;
        },
    };
}

/**
 * Monta el componente con `$refs`, `$nextTick` y un `window` mínimos. `init()`
 * corre con el `<select>` ya en su sitio, como en el DOM real.
 */
function mount(options) {
    const select = new FakeSelect(options);
    const button = stubElement();
    const panel = stubElement();

    const component = quantitySelect();
    component.$refs = { select, button, panel };
    component.$nextTick = (callback) => callback();
    component.init();

    return { component, select, button, panel };
}

test('choose() fija el valor del select y dispara input y change', () => {
    globalThis.window = { addEventListener() {} };
    globalThis.document = { querySelector: () => null };
    globalThis.MutationObserver = class {
        observe() {}
    };
    globalThis.Node = class {};

    const { component, select, button } = mount([
        new FakeOption(1, '1'),
        new FakeOption(2, '2'),
        new FakeOption(3, '3', true),
    ]);

    component.openPanel();

    assert.equal(component.open, true, 'el panel se abre');
    assert.equal(component.options.length, 3, 'se listan las tres opciones');

    component.choose(component.options[1]);

    assert.equal(select.value, '2', 'el select toma la cantidad elegida');
    assert.deepEqual(select.dispatched, ['input', 'change'], 'avisa a quien lo escucha');
    assert.equal(component.open, false, 'el panel se cierra al elegir');
    assert.equal(button.focused, 1, 'el foco vuelve al botón');
});

test('una opción deshabilitada no se puede elegir y queda fuera del recorrido', () => {
    globalThis.window = { addEventListener() {} };
    globalThis.MutationObserver = class {
        observe() {}
    };
    globalThis.Node = class {};

    const { component, select } = mount([
        new FakeOption(1, '1'),
        new FakeOption(2, '2'),
        new FakeOption(3, '3', true),
    ]);

    component.openPanel();
    component.choose(component.options[2]);

    assert.equal(select.value, '1', 'el valor no cambia al elegir una deshabilitada');
    assert.deepEqual(select.dispatched, [], 'no se avisa a nadie');

    const enabled = component.enabledOptions().map((entry) => entry.option.value);
    assert.deepEqual(enabled, ['1', '2'], 'la deshabilitada no es elegible');
});

test('al abrir, el foco arranca en la opción seleccionada', () => {
    globalThis.window = { addEventListener() {} };
    globalThis.MutationObserver = class {
        observe() {}
    };
    globalThis.Node = class {};

    const { component, select } = mount([
        new FakeOption(1, '1'),
        new FakeOption(2, '2'),
        new FakeOption(3, '3'),
    ]);

    select.value = '3';
    component.openPanel();

    assert.equal(component.focusedIndex, 2, 'el foco cae sobre la cantidad actual');
});

test('un select deshabilitado no abre el panel', () => {
    globalThis.window = { addEventListener() {} };
    globalThis.MutationObserver = class {
        observe() {}
    };
    globalThis.Node = class {};

    const { component, select } = mount([new FakeOption(1, '1')]);
    select.disabled = true;

    component.toggle();

    assert.equal(component.open, false, 'el panel sigue cerrado');
});
