<x-store.layout title="Crear cuenta · Feigler" :access-modal="false">
    <div class="mx-auto w-full max-w-lg px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                Crea tu cuenta de Feigler
            </h1>

            @include('auth.partials.register-form', ['prefix' => null, 'bag' => null, 'inModal' => false])
        </div>
    </div>
</x-store.layout>
