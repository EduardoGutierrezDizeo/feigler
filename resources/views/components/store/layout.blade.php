@props(['title' => 'Feigler', 'active' => null, 'cartCount' => 0, 'searchQuery' => null])

@php
    $nav = [
        'hombre' => ['Hombre', '/hombre'],
        'mujer' => ['Mujer', '/mujer'],
        'ninos' => ['Niños', '/ninos'],
        'novedades' => ['Novedades', '/novedades'],
        'tienda' => ['Tienda', '/tienda'],
        'cuenta' => ['Cuenta', '/cuenta'],
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- `admin.js` es la entrada que registra los componentes Alpine sin importar
         Alpine: en esta página lo arranca Livewire (`@livewireScripts`), igual
         que en el panel. `app.js` arrancaría una segunda instancia. --}}
    @vite(['resources/css/app.css', 'resources/js/admin.js'])
    @livewireStyles

    {{-- Oculta los elementos de Alpine hasta que arranque. --}}
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="flex min-h-screen flex-col bg-linear-to-br from-crema to-[#F3ECDD] font-sans text-tinta antialiased">
    <header x-data="{ open: false }" @keydown.escape.window="open = false"
            class="sticky top-0 z-40 border-b border-arena/70 bg-crema/90 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-8">
            <a href="{{ url('/') }}" class="font-display text-xl tracking-[0.45em] text-verde">FEIGLER</a>

            <form action="{{ route('storefront.search') }}" method="get" role="search" class="mx-auto hidden w-full max-w-xs md:block">
                <label class="relative block">
                    <span class="sr-only">Buscar productos</span>
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-gris-calido" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4" stroke-linecap="round"/></svg>
                    <input type="search" name="q" value="{{ $searchQuery }}" placeholder="Buscar productos"
                           class="w-full rounded-full border border-arena bg-crema/70 py-2 pl-10 pr-4 text-sm placeholder:text-gris-calido focus:border-verde focus:ring-verde/20">
                </label>
            </form>

            <nav aria-label="Principal" class="ml-auto hidden items-center gap-6 text-sm lg:flex">
                @foreach ($nav as $key => [$label, $href])
                    @if ($key === 'cuenta' && auth()->user()?->hasRole('cliente'))
                        <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                            <button type="button" @click="open = !open" :aria-expanded="open"
                                    class="flex items-center gap-1 border-b pb-0.5 transition {{ $active === $key ? 'border-laton text-verde' : 'border-transparent text-gris-calido hover:text-verde' }}">
                                {{ $label }}
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="open" x-transition.opacity style="display: none"
                                 class="absolute right-0 z-50 mt-2 w-44 rounded-xl border border-arena bg-crema py-1 shadow-lg">
                                <a href="{{ url($href) }}" class="block px-4 py-2 text-gris-calido transition hover:bg-hueso/50 hover:text-verde">Mi cuenta</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full px-4 py-2 text-left text-gris-calido transition hover:bg-hueso/50 hover:text-verde">Cerrar sesión</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ url($href) }}" @if ($active === $key) aria-current="page" @endif
                           class="border-b pb-0.5 transition {{ $active === $key ? 'border-laton text-verde' : 'border-transparent text-gris-calido hover:text-verde' }}">{{ $label }}</a>
                    @endif
                @endforeach
            </nav>

            <a href="{{ url('/carrito') }}"
               class="ml-auto whitespace-nowrap rounded-full border border-laton px-4 py-1.5 text-sm text-verde transition hover:bg-verde hover:text-crema lg:ml-0">
                Carrito · {{ $cartCount }}
            </a>

            <button type="button" class="grid size-10 place-items-center rounded-full border border-arena text-verde lg:hidden"
                    @click="open = !open" :aria-expanded="open" aria-controls="menu-movil" aria-label="Abrir menú">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>

        <div id="menu-movil" x-show="open" x-transition.opacity style="display: none"
             class="border-t border-arena/70 bg-crema px-4 pb-5 pt-4 lg:hidden">
            <form action="{{ route('storefront.search') }}" method="get" role="search" class="mb-4">
                <label class="sr-only" for="buscar-movil">Buscar productos</label>
                <input id="buscar-movil" type="search" name="q" value="{{ $searchQuery }}" placeholder="Buscar productos"
                       class="w-full rounded-full border border-arena bg-crema py-2 px-4 text-sm focus:border-verde focus:ring-verde/20">
            </form>
            <nav aria-label="Principal móvil" class="grid grid-cols-2 gap-1 text-base">
                @foreach ($nav as $key => [$label, $href])
                    <a href="{{ url($href) }}" class="rounded-xl px-3 py-2.5 {{ $active === $key ? 'bg-hueso/60 text-verde' : 'text-tinta hover:bg-hueso/40' }}">{{ $label }}</a>
                @endforeach
            </nav>

            @if (auth()->user()?->hasRole('cliente'))
                <form method="POST" action="{{ route('logout') }}" class="mt-1">
                    @csrf
                    <button type="submit" class="block w-full rounded-xl px-3 py-2.5 text-left text-tinta hover:bg-hueso/40">Cerrar sesión</button>
                </form>
            @endif
        </div>
    </header>

    <main class="flex-1">{{ $slot }}</main>

    <footer class="mt-20 border-t border-arena/70 bg-linear-to-br from-crema/40 to-arena/30">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-8 gap-y-3 px-4 py-8 text-sm text-gris-calido sm:px-8">
            <span class="font-display text-base tracking-[0.45em] text-verde">FEIGLER</span>
            <span>{{ config('tienda.direccion') }}</span>
            <a href="{{ config('tienda.instagram') }}" class="transition hover:text-verde">Instagram</a>
            <a href="{{ config('tienda.whatsapp') }}" class="transition hover:text-verde">WhatsApp</a>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
