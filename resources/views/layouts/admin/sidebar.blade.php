{{-- Sidebar navigation for the admin panel.

     Design contract (see resources/css/app.css tokens): cream is the dominant
     surface everywhere, forest green is structural (text, icons, active accent)
     and wood is tertiary, used here only as the small logo badge. --}}
<div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-sand px-4">
    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 rounded-md transition-opacity duration-150 ease-in-out hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green">
        {{-- Tertiary wood tone: small badge, not a surface. --}}
        <span class="inline-flex shrink-0 items-center justify-center rounded-md bg-wood p-1">
            <x-application-logo class="h-6 w-6 fill-cream" />
        </span>
        <span class="font-display text-lg font-semibold tracking-wide text-brand-green">
            Feigler
        </span>
    </a>
</div>

{{-- Cada enlace comparte la misma lista de clases: la base aporta la
     transición (fondo, texto y el indicador de `border-s-2`, que se funde en
     lugar de aparecer de golpe) y el anillo de foco por teclado; encima solo se
     alternan los colores del estado activo y los del reposo. --}}
<nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-5">
    <a
        href="{{ route('admin.dashboard') }}"
        @class([
            'flex items-center gap-3 rounded-r-md border-s-2 py-2 ps-3 pe-3 text-sm font-medium transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green',
            'border-brand-green bg-sand/50 text-brand-green hover:bg-sand/70' => request()->routeIs('admin.dashboard'),
            'border-transparent text-clay hover:bg-sand/40 hover:text-brand-green' => ! request()->routeIs('admin.dashboard'),
        ])
    >
        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
        </svg>
        Dashboard
    </a>
    <a
        href="{{ route('admin.categories.index') }}"
        @class([
            'flex items-center gap-3 rounded-r-md border-s-2 py-2 ps-3 pe-3 text-sm font-medium transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green',
            'border-brand-green bg-sand/50 text-brand-green hover:bg-sand/70' => request()->routeIs('admin.categories.index'),
            'border-transparent text-clay hover:bg-sand/40 hover:text-brand-green' => ! request()->routeIs('admin.categories.index'),
        ])
    >
        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44Z" />
        </svg>
        Categorías
    </a>
    <a
        href="{{ route('admin.users.index') }}"
        @class([
            'flex items-center gap-3 rounded-r-md border-s-2 py-2 ps-3 pe-3 text-sm font-medium transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green',
            'border-brand-green bg-sand/50 text-brand-green hover:bg-sand/70' => request()->routeIs('admin.users.index'),
            'border-transparent text-clay hover:bg-sand/40 hover:text-brand-green' => ! request()->routeIs('admin.users.index'),
        ])
    >
        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0Zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0Z" />
        </svg>
        Usuarios
    </a>
</nav>

<div class="border-t border-sand p-3">
    <form method="POST" action="{{ route('logout') }}">
        @csrf

        <button type="submit" class="flex w-full items-center gap-3 rounded-r-md border-s-2 border-transparent py-2 ps-3 pe-3 text-sm font-medium text-clay transition-colors duration-150 ease-in-out hover:bg-sand/40 hover:text-brand-green focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green">
            <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M3 12h16.5m0 0-3-3m3 3-3 3" />
            </svg>
            Cerrar sesión
        </button>
    </form>
</div>