<x-store.layout title="Confirma tu contraseña · Feigler">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Confirm') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
            </p>

            <form method="POST" action="{{ route('password.confirm') }}" class="mt-8 space-y-5">
                @csrf

                <x-store.field label="{{ __('Password') }}" name="password" type="password" :messages="$errors->get('password')" required autocomplete="current-password" />

                <x-store.button>{{ __('Confirm') }}</x-store.button>
            </form>
        </div>
    </div>
</x-store.layout>
