<x-store.layout title="Iniciar sesión · Feigler" :access-modal="false">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Log In') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Acceso a tu cuenta de Feigler
            </p>

            @include('auth.partials.login-form', ['prefix' => null, 'bag' => null, 'inModal' => false])
        </div>
    </div>
</x-store.layout>
