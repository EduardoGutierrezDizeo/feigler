<x-store.layout title="Mi cuenta" active="cuenta">
    <div class="mx-auto max-w-2xl px-4 py-12 sm:px-8">
        <h1 class="font-display text-4xl text-verde">Mi cuenta</h1>
        <p class="mt-2 text-gris-calido">Hola, {{ $user->name }}.</p>

        @if (session('status') === 'registered')
            <p class="mt-6 rounded-xl border border-arena bg-hueso/40 p-4 text-sm text-verde">
                Tu cuenta de Feigler quedó creada.
            </p>
        @endif

        @if (session('status') === 'verified')
            <p class="mt-6 rounded-xl border border-arena bg-hueso/40 p-4 text-sm text-verde">
                ¡Tu correo quedó verificado!
            </p>
        @endif

        @unless ($user->hasVerifiedEmail())
            <div class="mt-6 rounded-xl border border-arena bg-hueso/40 p-5">
                <h2 class="font-display text-2xl text-tinta">Verifica tu correo</h2>
                <p class="mt-2 text-sm text-gris-calido">
                    Te enviamos un enlace a {{ $user->email }}. Puedes seguir navegando la tienda;
                    solo necesitarás verificar tu correo para pagar tus pedidos.
                </p>

                @if (session('status') === 'verification-link-sent')
                    <p class="mt-3 text-sm text-verde">Te reenviamos el correo de verificación.</p>
                @endif

                <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                    @csrf

                    <button type="submit" class="rounded-full border border-laton px-4 py-1.5 text-sm text-verde transition hover:bg-verde hover:text-crema">
                        Reenviar correo de verificación
                    </button>
                </form>
            </div>
        @endunless
    </div>
</x-store.layout>
