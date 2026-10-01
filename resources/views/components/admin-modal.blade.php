@props([
    'title',
    'titleId',
    'maxWidth' => 'sm:max-w-lg',
])

{{-- Único patrón de modal para los formularios del panel admin (categorías,
     subcategorías y usuarios), para que su overlay y su tamaño no dependan del
     alto del contenido ni de la página.

     - Contenedor `fixed inset-0`: siempre el viewport completo, sin importar el
       scroll ni el tamaño de la lista que hay detrás.
     - Overlay `absolute inset-0`: vive dentro de ese mismo contenedor, así que
       queda anclado al viewport y no puede quedar por debajo de la cabecera.
     - Tarjeta centrada con `max-h-[90vh]` y `flex flex-col`: el alto lo decide el
       viewport, no el contenido.
     - Cuerpo con `min-h-0 flex-1 overflow-y-auto`: el `min-h-0` es obligatorio
       (sin él, un ítem flex mantiene `min-height: auto`, la tarjeta no baja de
       `max-h` y el scroll acaba en la página completa en vez de dentro).

     Ojo: ningún ancestro de este componente puede tener un `transform`
     distinto de `none`, o el `fixed inset-0` se resolvería contra esa caja.
     Ver el comentario de `<main>` en `layouts/admin.blade.php`.

     El nodo NO se monta ni se desmonta: las vistas de Livewire lo renderizan
     siempre y la visibilidad la gobierna Alpine con `x-show="$data.open"`,
     enlazado a la propiedad `showForm` del componente padre mediante
     `Alpine.data('adminModal')`. Por eso el cierre puede animarse: si el modal
     viviera dentro de un `@if ($showForm)`, el servidor borraría el nodo en el
     mismo instante en que `showForm` pasa a `false` y no quedaría nada que
     transicionar.

     `$wire` se pasa como argumento (`adminModal($wire)`) porque
     `$wire.entangle()` no devuelve el valor de la propiedad, sino un interceptor
     de Alpine que solo se activa si está en el objeto de `x-data` cuando Alpine
     lo recorre, y ese recorrido es anterior a `init()`. Ver la nota de
     `resources/js/alpine/admin-modal.js`.

     `x-show` y `x-transition` van en el MISMO elemento (overlay y tarjeta) a
     propósito: Alpine solo interpola la visibilidad de un elemento si él mismo
     declara la transición. El contenedor, en cambio, no lleva `x-show` sino
     `pointer-events-none`, para que la capa no siga interceptando los clics de
     la página una vez cerrado el modal. --}}
<div
    x-data="adminModal($wire)"
    class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
>
    <div
        x-cloak
        x-show="$data.open"
        class="pointer-events-auto absolute inset-0 bg-charcoal/40"
        wire:click="closeForm"
        aria-hidden="true"
        x-transition:enter="duration-[350ms] ease-out"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="duration-[250ms] ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    ></div>

    <div
        x-cloak
        x-show="$data.open"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        x-on:keydown.escape.window="$data.open && close()"
        x-on:keydown.tab="trap($event)"
        class="pointer-events-auto relative flex max-h-[90vh] w-full {{ $maxWidth }} flex-col overflow-hidden rounded-xl border border-sand bg-parchment shadow-xl"
        x-transition:enter="duration-[350ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
        x-transition:enter-start="translate-y-3 scale-[0.96] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        x-transition:leave="duration-[250ms] ease-in"
        x-transition:leave-start="translate-y-0 scale-100 opacity-100"
        x-transition:leave-end="translate-y-3 scale-[0.96] opacity-0"
    >
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-sand px-6 py-4">
            <h2 id="{{ $titleId }}" class="font-display text-xl font-semibold text-brand-green">
                {{ $title }}
            </h2>

            <button
                type="button"
                wire:click="closeForm"
                class="rounded-md p-1 text-clay transition-colors duration-150 ease-in-out hover:bg-sand/50 hover:text-brand-green focus:outline-2 focus:outline-offset-2 focus:outline-brand-green"
                aria-label="Cerrar formulario"
            >
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex shrink-0 flex-col-reverse gap-3 border-t border-sand px-6 py-4 sm:flex-row sm:justify-end">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
