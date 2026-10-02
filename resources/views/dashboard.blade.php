<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-tinta">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <p class="border-s-2 border-madera/60 ps-6 text-lg text-tinta">
                {{ __("You're logged in!") }}
            </p>
        </div>
    </div>
</x-app-layout>
