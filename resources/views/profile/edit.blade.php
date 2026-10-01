<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display text-2xl font-semibold leading-tight text-brand-green">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    {{-- Cada sección se separa por una línea divisoria fina en lugar de una
         tarjeta con fondo propio: el crema sigue siendo la superficie única. --}}
    <div class="py-12">
        <div class="mx-auto max-w-3xl space-y-10 px-4 sm:px-6 lg:px-8">
            <section class="border-t border-sand pt-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </section>

            <section class="border-t border-sand pt-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </section>

            <section class="border-t border-sand pt-8">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </section>
        </div>
    </div>
</x-app-layout>