{{-- Navegación del panel admin. Va sobre el degradado verde Feigler: texto en crema,
     latón solo para la marca y el indicador del enlace activo. --}}
@php
    $groups = [
        ['label' => null, 'items' => [
            ['admin.dashboard', 'admin.dashboard', 'Dashboard', 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75'],
        ]],
        ['label' => 'Módulos', 'items' => [
            ['admin.product-details.index', 'admin.product-details.*', 'Detalles de productos', 'M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44Z'],
            ['admin.products.index', 'admin.products.*', 'Productos', 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12A1.125 1.125 0 0119.75 21.75H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Z'],
            ['admin.users.index', 'admin.users.*', 'Usuarios', 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0Zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0Z'],
        ]],
    ];

    $initials = \Illuminate\Support\Str::of(Auth::user()->name)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
        ->implode('');
@endphp

<a
    href="{{ route('admin.dashboard') }}"
    class="block shrink-0 px-6 pb-7 pt-8 transition-opacity duration-150 ease-in-out hover:opacity-85 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-laton"
>
    <span class="block font-display text-xl font-semibold uppercase tracking-[0.35em] text-crema">Feigler</span>
    <span class="mt-0.5 block font-display text-base italic text-laton">Administración</span>
</a>

<nav class="flex-1 overflow-y-auto py-2" aria-label="Navegación del panel">
    @foreach ($groups as $group)
        <div class="mb-6">
            @if ($group['label'])
                <p class="mb-2 px-6 text-[0.6875rem] font-medium uppercase tracking-[0.2em] text-crema/50">{{ $group['label'] }}</p>
            @endif

            <div class="space-y-0.5">
                @foreach ($group['items'] as [$route, $pattern, $label, $icon])
                    @php($active = request()->routeIs($pattern))

                    <a
                        href="{{ route($route) }}"
                        @if ($active) aria-current="page" @endif
                        @class([
                            'flex items-center gap-3 border-s-2 py-2.5 ps-[1.375rem] pe-4 text-sm transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-laton',
                            'border-laton bg-white/10 font-medium text-crema' => $active,
                            'border-transparent text-crema/70 hover:bg-white/5 hover:text-crema' => ! $active,
                        ])
                    >
                        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
                        </svg>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>

<div class="shrink-0 border-t border-white/10 p-4">
    <div class="flex items-center gap-3 px-2 pb-3">
        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-laton-degradado text-xs font-semibold text-verde-hondo" aria-hidden="true">{{ $initials }}</span>
        <span class="min-w-0 flex-1 truncate text-sm text-crema">{{ Auth::user()->name }}</span>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf

        <button type="submit" class="flex w-full items-center gap-3 px-2 py-2 text-sm text-crema/70 transition-colors duration-150 ease-in-out hover:text-crema focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-laton">
            <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M3 12h16.5m0 0-3-3m3 3-3 3" />
            </svg>
            Cerrar sesión
        </button>
    </form>
</div>