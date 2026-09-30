<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- CSS only: Alpine.js is bundled with Livewire and must not be loaded twice. --}}
        @vite(['resources/css/app.css'])

        @livewireStyles

        {{-- Hides Alpine-powered elements until Livewire's Alpine initializes them. --}}
        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900" x-data="{ sidebarOpen: false }">
            <!-- Sidebar (desktop) -->
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-e border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 lg:flex">
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Sidebar backdrop (mobile) -->
            <div
                x-cloak
                x-show="sidebarOpen"
                @click="sidebarOpen = false"
                class="fixed inset-0 z-40 bg-gray-900/50 lg:hidden"
            ></div>

            <!-- Sidebar (mobile) -->
            <aside
                x-cloak
                x-show="sidebarOpen"
                class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-e border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 lg:hidden"
            >
                @include('layouts.admin.sidebar')
            </aside>

            <!-- Content -->
            <div class="lg:ps-64">
                <header class="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800 sm:px-6">
                    <button
                        type="button"
                        @click="sidebarOpen = ! sidebarOpen"
                        class="-ms-2 inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:outline-hidden focus:bg-gray-100 focus:text-gray-500 dark:text-gray-500 dark:hover:bg-gray-900 dark:hover:text-gray-400 dark:focus:bg-gray-900 dark:focus:text-gray-400 lg:hidden"
                    >
                        <span class="sr-only">Abrir menú lateral</span>
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <div class="text-sm font-medium text-gray-600 dark:text-gray-300">
                        {{ Auth::user()->name }}
                    </div>
                </header>

                <main class="p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
