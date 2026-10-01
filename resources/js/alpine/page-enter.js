/**
 * Entrada suave del contenido principal de cada página.
 *
 * Se registra vía `Alpine.data('pageEnter', ...)`, igual que `confirmDialog`: el
 * barrel `resources/js/alpine/index.js` lo carga y ambos bundles de entrada
 * (app.js y admin.js) importan ese barrel, así que está disponible en toda la
 * aplicación.
 *
 * En Blade se usa sobre el contenedor que cambia entre páginas, nunca sobre la
 * navegación fija:
 *
 *     <main x-cloak x-data="pageEnter"
 *           class="transition-[opacity,transform] duration-300 ease-out"
 *           :class="entered ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0'">
 *
 * `x-cloak` evita que el navegador llegue a pintar el contenido ya desplazado
 * antes de que Alpine arranque; la regla que lo oculta vive en la cabecera de
 * cada layout.
 */
const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

document.addEventListener('alpine:init', () => {
    Alpine.data('pageEnter', () => ({
        entered: false,

        init() {
            const reveal = () => {
                this.entered = true;
            };

            // Sin transición no hay nada que animar: se revela en el mismo
            // fotograma en que Alpine inicializa, de modo que el contenido nunca
            // queda invisible esperando un `requestAnimationFrame`.
            if (window.matchMedia(REDUCED_MOTION_QUERY).matches) {
                reveal();

                return;
            }

            // Dos fotogramas: el primero deja que el navegador pinte el estado
            // inicial y el segundo cambia las clases con la transición ya activa.
            window.requestAnimationFrame(() => window.requestAnimationFrame(reveal));
        },
    }));
});
