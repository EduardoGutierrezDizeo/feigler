<x-store.layout title="Restablecer contraseña · Feigler">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Reset Password') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Elige una contraseña nueva para tu cuenta
            </p>

            <form method="POST" action="{{ route('password.store') }}" class="mt-8 space-y-5">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <x-store.field label="{{ __('Email') }}" name="email" type="email" :value="old('email', $request->email)" :messages="$errors->get('email')" required autofocus autocomplete="email" />

                <x-store.field label="{{ __('Password') }}" name="password" type="password" :messages="$errors->get('password')" required autocomplete="new-password" />

                <x-store.field label="{{ __('Confirm Password') }}" name="password_confirmation" type="password" :messages="$errors->get('password_confirmation')" required autocomplete="new-password" />

                <x-store.button>{{ __('Reset Password') }}</x-store.button>
            </form>
        </div>
    </div>
</x-store.layout>
