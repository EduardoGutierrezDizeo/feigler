<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-brand-green">
            Bienvenido, {{ Auth::user()->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl border-l-2 border-wood/70 pl-6">
                <p class="font-display text-2xl font-semibold text-brand-green">
                    Tu módulo de {{ $module }}
                </p>

                <p class="mt-3 text-base text-clay">
                    Estará disponible pronto.
                </p>

                <form method="POST" action="{{ route('logout') }}" class="mt-8">
                    @csrf

                    <x-secondary-button type="submit">
                        {{ __('Log Out') }}
                    </x-secondary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>