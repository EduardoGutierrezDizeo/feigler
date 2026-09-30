<x-guest-layout>
    <h1 class="font-display text-2xl font-semibold text-brand-green">
        {{ __('Verify Email') }}
    </h1>

    <p class="mt-1 text-sm text-clay">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-4 text-sm font-medium text-brand-green">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-6 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('Resend Verification Email') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="rounded-md text-center text-sm text-clay underline underline-offset-4 hover:text-terracotta focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream sm:text-start">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>