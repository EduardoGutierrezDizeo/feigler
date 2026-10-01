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
        <div class="min-h-screen bg-cream">
            {{-- La navegación queda fuera de la animación: es fija. --}}
            @include('layouts.navigation')

            {{-- Encabezado y contenido cambian en cada página, así que entran
                 juntos con un solo fundido para que el título no aparezca de
                 golpe mientras el resto de la vista se desvanece. --}}
            <div
                x-cloak
                x-data="pageEnter"
                class="transition-[opacity,transform] duration-300 ease-out"
                :class="entered ? 'opacity-100' : 'translate-y-2 opacity-0'"
            >
                @isset($header)
                    <header class="border-b border-sand">
                        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
