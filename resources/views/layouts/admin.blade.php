<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css'])

        @livewireStyles

        {{-- Oculta los elementos de Alpine hasta que el Alpine de Livewire los inicializa. --}}
        <style>[x-cloak] { display: none !important; }</style>
        {{-- Registra los stores de Alpine y el helper FLIP del panel. No debe importar Alpine:
             Livewire ya lo incluye y una segunda instancia rompería todas las páginas. --}}
        @vite(['resources/js/admin.js'])
    </head>
    <body class="bg-crema font-sans text-tinta antialiased">
        <div class="min-h-screen" x-data="{ sidebarOpen: false }">
            <!-- Sidebar (escritorio): degradado verde Feigler -->
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-verde-degradado lg:flex">
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Fondo del sidebar (móvil) -->
            <div
                x-cloak
                x-show="sidebarOpen"
                @click="sidebarOpen = false"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-40 bg-tinta/40 lg:hidden"
            ></div>

            <!-- Sidebar (móvil): entra deslizándose desde el borde -->
            <aside
                x-cloak
                x-show="sidebarOpen"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="-translate-x-full opacity-0"
                x-transition:enter-end="translate-x-0 opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="translate-x-0 opacity-100"
                x-transition:leave-end="-translate-x-full opacity-0"
                class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-verde-degradado lg:hidden"
            >
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Contenido -->
            <div class="min-w-0 lg:ps-64">
                {{-- Barra superior solo en móvil y tablet: en escritorio el sidebar fijo ya
                     lleva la marca. Debe ocultarse con `lg` y no antes, porque su botón es el
                     que abre el sidebar colapsable, que no existe por debajo de `lg`. --}}
                <header class="flex h-16 items-center justify-between gap-3 border-b border-arena bg-crema px-4 sm:px-6 lg:hidden">
                    <button
                        type="button"
                        @click="sidebarOpen = ! sidebarOpen"
                        class="-ms-2 inline-flex shrink-0 items-center justify-center rounded-full p-2 text-verde transition-colors duration-150 ease-in-out hover:bg-hueso focus:outline-2 focus:outline-offset-2 focus:outline-verde"
                    >
                        <span class="sr-only">Abrir menú lateral</span>
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <span class="font-display text-base font-semibold uppercase tracking-[0.3em] text-verde">Feigler</span>

                    <div class="min-w-0 max-w-[8rem] truncate text-sm text-gris-calido">
                        {{ Auth::user()->name }}
                    </div>
                </header>

                {{-- OJO: el estado final de la animación de entrada NO lleva `translate-y-0`.
                     Un `transform` distinto de `none` convierte a `<main>` en bloque contenedor
                     de los descendientes `position: fixed`: el `fixed inset-0` de los modales
                     se resolvería contra esta caja y no contra el viewport. --}}
                <main
                    x-cloak
                    x-data="pageEnter"
                    class="px-4 py-6 transition-[opacity,transform] duration-300 ease-out sm:px-6 sm:py-8 lg:px-10 lg:py-10"
                    :class="entered ? 'opacity-100' : 'translate-y-2 opacity-0'"
                >
                    {{ $slot }}
                </main>
            </div>

            <x-toaster />

        </div>

        <x-confirm-dialog />

        @livewireScripts
    </body>
</html>
