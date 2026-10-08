{{--
    Modal de acceso de la tienda (solo invitados). Reúne las tres vistas de
    acceso —iniciar sesión, crear cuenta y recuperar contraseña— como parciales
    compartidos con las páginas reales; el servidor decide la vista inicial y si
    arranca abierto. Se incluye una sola vez en x-store.layout, y las páginas de
    acceso lo suprimen con :access-modal="false" para no repetir formularios ni id.

    El estado vive en Alpine.data('accessModal'): abre con el evento de ventana
    `open-access` (detail.view), cierra con Escape, con el fondo o con el botón, y
    atrapa el Tab sin dependencias.
--}}
@props(['view' => 'login', 'open' => false])

@php
    $initialView = in_array($view, ['login', 'register', 'forgot'], true) ? $view : 'login';
@endphp

<div
    x-data="accessModal({ view: @js($initialView), open: @js((bool) $open) })"
    x-show="open"
    x-cloak
    @open-access.window="openView($event.detail?.view)"
    @keydown.escape.window="close()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="access-modal-title"
    class="fixed inset-0 z-50 flex items-end justify-center bg-tinta/50 sm:items-center"
    style="display: none"
>
    <div class="absolute inset-0" @click="close()" aria-hidden="true"></div>

    <div x-ref="panel" @click.stop @keydown="trap($event)"
         class="relative z-10 max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-crema p-6 shadow-lift sm:max-w-md sm:rounded-3xl sm:p-8">
        <button type="button" @click="close()" aria-label="Cerrar"
                class="absolute right-4 top-4 grid size-9 place-items-center rounded-full text-gris-calido transition hover:bg-hueso/60 hover:text-verde">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>

        <h2 id="access-modal-title" class="font-display text-3xl font-semibold text-verde" x-text="titles[view]"></h2>

        <div x-show="view === 'login'" x-cloak>
            @include('auth.partials.login-form', ['prefix' => 'login', 'bag' => 'login', 'inModal' => true])
        </div>

        <div x-show="view === 'register'" x-cloak>
            @include('auth.partials.register-form', ['prefix' => 'register', 'bag' => 'register', 'inModal' => true])
        </div>

        <div x-show="view === 'forgot'" x-cloak>
            @include('auth.partials.forgot-form', ['prefix' => 'forgot', 'bag' => 'forgot', 'inModal' => true])
        </div>
    </div>
</div>
