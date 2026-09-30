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
    </head>
    <body class="bg-cream font-sans text-charcoal antialiased">
        <div class="flex min-h-screen flex-col items-center bg-cream pt-6 sm:justify-center sm:pt-0">
            <div>
                <a href="/" class="block">
                    <x-application-logo class="h-20 w-20 fill-current text-brand-green" />
                </a>
                <p class="mt-3 text-center font-display text-2xl font-semibold tracking-wide text-brand-green">
                    Feigler
                </p>
                <p class="mt-1 text-center text-xs uppercase tracking-[0.3em] text-clay">
                    Ropa con carácter
                </p>
            </div>

            <div class="mt-8 w-full overflow-hidden border border-sand bg-parchment px-6 py-8 shadow-sm sm:max-w-md sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>