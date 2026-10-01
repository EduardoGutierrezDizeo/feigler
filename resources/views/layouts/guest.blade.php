<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Hides Alpine-powered elements until Alpine initializes them. --}}
        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="bg-cream font-sans text-charcoal antialiased">
        <div class="flex min-h-screen flex-col items-center bg-cream pt-10 sm:justify-center sm:pt-0">
            <div class="text-center">
                {{-- Tertiary wood tone as the small brand badge, never as a surface. --}}
                <a href="/" class="inline-flex rounded-lg p-2 transition-opacity duration-150 ease-in-out hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green">
                    <span class="inline-flex items-center justify-center rounded-lg bg-wood p-2">
                        <x-application-logo class="h-9 w-9 fill-cream" />
                    </span>
                </a>
                <p class="mt-4 font-display text-2xl font-semibold tracking-wide text-brand-green">
                    Feigler
                </p>
                <p class="mt-1.5 text-xs uppercase tracking-[0.3em] text-clay">
                    Ropa con carácter
                </p>
            </div>

            <div class="mt-12 w-full max-w-md border-t border-sand px-6 pt-10 sm:mt-14 sm:border-t-0 sm:px-0 sm:pt-0">
                {{-- El formulario es lo único que cambia entre pantallas de
                     acceso, así que es lo único que entra con animación. El
                     estado final no lleva `translate-y-0` a propósito: un
                     `transform` distinto de `none` haría de este div un bloque
                     contenedor para cualquier descendiente `position: fixed`. --}}
                <div
                    x-cloak
                    x-data="pageEnter"
                    class="transition-[opacity,transform] duration-300 ease-out"
                    :class="entered ? 'opacity-100' : 'translate-y-2 opacity-0'"
                >
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>