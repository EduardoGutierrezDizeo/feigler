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
            <!-- Sidebar (desktop) -->
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-e border-wood/40 bg-wood lg:flex">
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Sidebar backdrop (mobile) -->
            <div
                x-cloak
                x-show="sidebarOpen"
                @click="sidebarOpen = false"
                class="fixed inset-0 z-40 bg-charcoal/60 lg:hidden"
            ></div>

            <!-- Sidebar (mobile) -->
            <aside
                x-cloak
                x-show="sidebarOpen"
                class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-e border-wood/40 bg-wood lg:hidden"
            >
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Content -->
            <div class="lg:ps-64">
                <header class="flex h-16 items-center justify-between border-b border-sand bg-parchment px-4 sm:px-6">
                    <button
                        type="button"
                        @click="sidebarOpen = ! sidebarOpen"
                        class="-ms-2 inline-flex items-center justify-center rounded-md p-2 text-clay transition duration-150 ease-in-out hover:bg-cream hover:text-brand-green focus:outline-hidden focus:bg-cream focus:text-brand-green lg:hidden"
                    >
                        <span class="sr-only">Abrir menú lateral</span>
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div class="font-display text-base font-medium text-brand-green">
                        {{ Auth::user()->name }}
                    </div>
                </header>

                <main class="p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-toaster />
        <x-confirm-dialog />

        @livewireScripts
    </body>
</html>