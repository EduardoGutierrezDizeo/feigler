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
        <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css'])

        @livewireStyles

        {{-- Hides Alpine-powered elements until Livewire's Alpine initializes them. --}}
        <style>[x-cloak] { display: none !important; }</style>
        {{-- Registers the Alpine stores and the FLIP animation helper used by the
             admin panel. Must not import Alpine: Livewire already bundles it and
             booting a second instance would break every page. --}}
        @vite(['resources/js/admin.js'])
    </head>
    <body class="bg-cream font-sans text-charcoal antialiased">
        <div
            class="min-h-screen bg-cream"
            x-data="{ sidebarOpen: false }"
        >
            {{-- Parchment, not a solid dark block: the top bar keeps cream
                 dominant and only separates content with a thin border. --}}
            <!-- Sidebar (desktop): cream surface, separated by a thin border
                 instead of a block of colour. -->
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-e border-sand bg-cream lg:flex">
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Sidebar backdrop (mobile) -->
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
                class="fixed inset-0 z-40 bg-charcoal/40 lg:hidden"
            ></div>

            <!-- Sidebar (mobile): entra deslizándose desde el borde, con el
                 mismo par de duraciones que el diálogo de confirmación. -->
            <aside
                x-cloak
                x-show="sidebarOpen"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="-translate-x-full opacity-0"
                x-transition:enter-end="translate-x-0 opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="translate-x-0 opacity-100"
                x-transition:leave-end="-translate-x-full opacity-0"
                class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-e border-sand bg-cream lg:hidden"
            >
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Content -->
            <div class="min-w-0 lg:ps-64">
                {{-- La barra superior es solo móvil y tablet: en escritorio el
                     sidebar fijo ya ocupa la columna izquierda y la marca se
                     repite en su propio encabezado, de modo que el header solo
                     servía para dejar una franja vacía sobre el contenido.

                     Ocultarla con `lg` (y no antes) es obligatorio: el botón de
                     este mismo header es el que abre el sidebar colapsable, y ese
                     sidebar no existe por debajo de `lg`. El padding superior de
                     `<main>` (`lg:py-10`) queda como único aire sobre el
                     contenido, así que no sobra ningún espacio. --}}
                <header class="flex h-16 items-center justify-between gap-3 border-b border-sand bg-parchment px-4 sm:px-6 lg:hidden">
                    <button
                        type="button"
                        @click="sidebarOpen = ! sidebarOpen"
                        class="-ms-2 inline-flex shrink-0 items-center justify-center rounded-md p-2 text-brand-green transition-colors duration-150 ease-in-out hover:bg-sand/50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-green"
                    >
                        <span class="sr-only">Abrir menú lateral</span>
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div class="min-w-0 truncate text-sm font-medium text-clay">
                        {{ Auth::user()->name }}
                    </div>
                </header>

                {{-- Solo el contenido que cambia entre páginas entra con una
                     animación: el sidebar y la cabecera permanecen fijos.

                     OJO: el estado final NO lleva `translate-y-0`. Un `transform`
                     distinto de `none` convierte a `<main>` en bloque contenedor
                     de los descendientes `position: fixed` y en un contexto de
                     apilamiento. Los modales de Livewire viven dentro de
                     `$slot`, así que su `fixed inset-0` se resolvería contra
                     esta caja en vez de contra el viewport: el overlay no
                     taparía la cabecera y su alto seguiría al del contenido.
                     Sin transform, `fixed` vuelve a significar "la pantalla". --}}
                <main
                    x-cloak
                    x-data="pageEnter"
                    class="px-4 py-6 transition-[opacity,transform] duration-300 ease-out sm:px-6 sm:py-8 lg:px-10 lg:py-10"
                    :class="entered ? 'opacity-100' : 'translate-y-2 opacity-0'"
                >
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-toaster />
        <x-confirm-dialog />

        @livewireScripts
    </body>
</html>