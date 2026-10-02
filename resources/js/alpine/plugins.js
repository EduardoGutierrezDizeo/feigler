/**
 * Plugins de Alpine que amplían el conjunto de directivas del núcleo.
 *
 * Se registran en `Alpine.plugin(...)` dentro del listener `alpine:init`, que es
 * el punto correcto para este proyecto: `Alpine.start()` emite `alpine:init`
 * *antes* de recorrer el DOM, así que la directiva queda disponible para el
 * primer `initTree` y también para los elementos que Livewire inserte después.
 *
 * El orden importa y lo garantiza el módulo en sí, no la posición en el HTML:
 * ambos bundles de entrada son scripts `type="module"` (diferidos por defecto),
 * así que se ejecutan antes que `DOMContentLoaded`. Livewire arranca Alpine
 * dentro de ese evento, de modo que cualquier listener de `alpine:init` registrado
 * desde un bundle se activa a tiempo — y en las páginas sin Livewire es
 * `resources/js/app.js` quien llama a `Alpine.start()` de forma síncrona.
 *
 * Ningún módulo debe importar Alpine: en el panel de administración lo provee
 * Livewire y arrancar una segunda instancia rompe todas las páginas.
 */
import collapse from '@alpinejs/collapse';

document.addEventListener('alpine:init', () => {
    // `x-collapse` anima la altura de un elemento que lleva `x-show`. Hoy el panel
    // no lo usa en ninguna vista: se deja registrado para que los módulos que
    // necesiten desplegables animen su altura sin tener que importar nada.
    Alpine.plugin(collapse);
});
