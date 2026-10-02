@props([
    'title',
    'titleId',
    'maxWidth' => 'sm:max-w-lg',
])

{{-- Único patrón de modal del panel admin (categorías, subcategorías y usuarios).

     Invariantes que no se deben tocar:
     - Contenedor `fixed inset-0`: siempre el viewport completo. Ningún ancestro puede tener
       un `transform` distinto de `none` (ver el comentario de `<main>` en layouts/admin).
     - El nodo nunca se monta ni se desmonta: Alpine gobierna la visibilidad con
       `x-show="$data.open"` (enlazado a `showForm` mediante `adminModal($wire)`), así el
       cierre puede animarse. `x-show` y `x-transition` van en el MISMO elemento.
     - El contenedor lleva `pointer-events-none` para no interceptar clics al cerrarse.
     - Cuerpo con `min-h-0 flex-1 overflow-y-auto`: sin `min-h-0` el scroll acaba en la página. --}}
<div
    x-data="adminModal($wire)"
    class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
>
    <div
        x-cloak
        x-show="$data.open"
        class="pointer-events-auto absolute inset-0 bg-tinta/40"
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
        class="pointer-events-auto relative flex max-h-[90vh] w-full {{ $maxWidth }} flex-col overflow-hidden rounded-2xl border border-arena bg-crema shadow-[0_28px_56px_-28px_rgb(30_27_24/0.5)]"
        x-transition:enter="duration-[350ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
        x-transition:enter-start="translate-y-3 scale-[0.96] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        x-transition:leave="duration-[250ms] ease-in"
        x-transition:leave-start="translate-y-0 scale-100 opacity-100"
        x-transition:leave-end="translate-y-3 scale-[0.96] opacity-0"
    >
        {{-- Filete de latón superior: el único adorno del modal. --}}
        <div class="h-0.5 shrink-0 bg-laton-degradado" aria-hidden="true"></div>

        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-arena px-6 py-5">
            <h2 id="{{ $titleId }}" class="font-display text-2xl font-medium text-tinta">
                {{ $title }}
            </h2>

            <button
                type="button"
                wire:click="closeForm"
                class="rounded-full p-1.5 text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde focus:outline-2 focus:outline-offset-2 focus:outline-verde"
                aria-label="Cerrar formulario"
            >
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex shrink-0 flex-col-reverse gap-3 border-t border-arena bg-hueso/50 px-6 py-4 sm:flex-row sm:justify-end">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>