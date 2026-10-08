<x-store.layout title="Recuperar contraseña · Feigler">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Forgot your password?') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
            </p>

            <x-store.status class="mt-6" :status="session('status')" />

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
                @csrf

                <x-store.field label="{{ __('Email') }}" name="email" type="email" :value="old('email')" :messages="$errors->get('email')" required autofocus autocomplete="email" />

                <x-store.button>{{ __('Email Password Reset Link') }}</x-store.button>
            </form>
        </div>
    </div>
</x-store.layout>
