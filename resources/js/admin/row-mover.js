const DEFAULT_DURATION = 340;
const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';
const GENERATION_ATTRIBUTE = 'data-flip-generation';

/**
 * Anima el desplazamiento de las filas de una lista tras un morph de Livewire
 * usando la técnica FLIP (First-Last-Invert-Play).
 *
 * 1. First:  `capture()` guarda el `top` de cada fila marcada antes de que la
 *            petición de Livewire salga hacia el servidor.
 * 2. Last:   `morphed()` vuelve a leer los `top` con el DOM ya actualizado.
 * 3. Invert: devuelve cada fila desplazada a su posición anterior con
 *            `transform` y sin transición, forzando el reflow con un doble
 *            `requestAnimationFrame`.
 * 4. Play:   libera el `transform` animando hacia `translate3d(0, 0, 0)` con
 *            una transición de `duration` ms.
 *
 * Se comparan todas las filas marcadas en lugar de solo la fila movida, así
 * que la fila con la que se intercambia la posición también se anima sin que el
 * servidor tenga que comunicarla.
 */
export function createRowMover({ duration = DEFAULT_DURATION, onFinish = null } = {}) {
    let firstTops = new Map();
    let generation = 0;

    const reducedMotion = () => window.matchMedia(REDUCED_MOTION_QUERY).matches;

    const rowsIn = (scope) => [...scope.querySelectorAll('[data-flip-row]')];

    const scopeOf = (el) => el?.closest?.('[data-flip-scope]') ?? null;

    /**
     * Registra las posiciones actuales de las filas animables de un scope.
     *
     * @param {HTMLElement|null} scope
     */
    function capture(scope) {
        if (!(scope instanceof HTMLElement)) {
            return;
        }

        firstTops = new Map(rowsIn(scope).map((row) => [row, row.getBoundingClientRect().top]));
    }

    function forget() {
        firstTops = new Map();
    }

    function settle(row) {
        row.style.removeProperty('transition');
        row.style.removeProperty('transform');
        row.removeAttribute(GENERATION_ATTRIBUTE);

        if (onFinish) {
            onFinish(row);
        }
    }

    function settleAll(rows) {
        for (const row of rows) {
            settle(row);
        }
    }

    /**
     * Paso 3: devuelve la fila a su posición anterior, sin transición.
     */
    function invert(row, delta) {
        row.setAttribute(GENERATION_ATTRIBUTE, String(++generation));
        row.classList.add('row-is-moving');
        row.style.transition = 'none';
        row.style.transform = `translate3d(0, ${delta}px, 0)`;
    }

    /**
     * Paso 4: libera el `transform` con una transición suave. El token de
     * generación evita que el `setTimeout` de una tanda anterior limpie los
     * estilos de una tanda más reciente que aún se está reproduciendo.
     */
    function play(row) {
        const token = row.getAttribute(GENERATION_ATTRIBUTE);

        row.style.transition = `transform ${duration}ms cubic-bezier(0.22, 1, 0.36, 1)`;
        row.style.transform = 'translate3d(0, 0, 0)';

        window.setTimeout(() => {
            if (row.getAttribute(GENERATION_ATTRIBUTE) === token) {
                settle(row);
            }
        }, duration);
    }

    function animate() {
        const deltas = new Map();
        const detached = [];

        for (const [row, top] of firstTops) {
            if (! row.isConnected) {
                detached.push(row);

                continue;
            }

            const delta = top - row.getBoundingClientRect().top;

            if (Math.abs(delta) < 0.5) {
                settle(row);

                continue;
            }

            deltas.set(row, delta);
        }

        forget();
        settleAll(detached);

        if (deltas.size === 0 || reducedMotion()) {
            settleAll(deltas.keys());

            return;
        }

        for (const [row, delta] of deltas) {
            invert(row, delta);
        }

        // Un solo reflow alcanza para registrar el estado invertido, pero se
        // piden dos frames para no competir con el pintado que dispara el morph.
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                for (const row of deltas.keys()) {
                    play(row);
                }
            });
        });
    }

    /**
     * Punto de entrada del morph de Livewire. Solo actúa si algún scope
     * animable capturó posiciones antes de la petición que generó el morph.
     */
    function morphed(el, component) {
        if (firstTops.size === 0) {
            return;
        }

        const scope = scopeOf(el) ?? scopeOf(component?.el);

        if (! scope) {
            forget();

            return;
        }

        if (! rowsIn(scope).some((row) => firstTops.has(row))) {
            return;
        }

        animate();
    }

    return { capture, morphed };
}
