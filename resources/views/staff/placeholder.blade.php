<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-brand-green">
            Bienvenido, {{ Auth::user()->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="overflow-hidden border border-sand bg-parchment shadow-xs sm:rounded-lg">
                <div class="p-6 text-charcoal">
                    <p class="text-lg">
                        Tu módulo de <span class="font-semibold text-brand-green">{{ $module }}</span> estará disponible pronto.
                    </p>

                    <form method="POST" action="{{ route('logout') }}" class="mt-6">
                        @csrf

                        <x-secondary-button type="submit">
                            {{ __('Log Out') }}
                        </x-secondary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>