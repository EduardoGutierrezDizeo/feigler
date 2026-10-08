<x-store.layout title="Verifica tu correo · Feigler" :access-modal="false">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Verify Email') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
            </p>

            @if (session('status') == 'verification-link-sent')
                <div class="mt-4 rounded-xl border border-arena bg-hueso/40 p-3 text-sm text-verde">
                    {{ __('A new verification link has been sent to the email address you provided during registration.') }}
                </div>
            @endif

            <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf

                    <x-store.button>{{ __('Resend Verification Email') }}</x-store.button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-store.link>{{ __('Log Out') }}</x-store.link>
                </form>
            </div>
        </div>
    </div>
</x-store.layout>
