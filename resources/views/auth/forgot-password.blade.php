<x-store.layout title="Recuperar contraseña · Feigler" :access-modal="false">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Forgot your password?') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
            </p>

            @include('auth.partials.forgot-form', ['prefix' => null, 'bag' => null, 'inModal' => false])
        </div>
    </div>
</x-store.layout>
