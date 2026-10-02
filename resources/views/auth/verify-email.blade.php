<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold text-tinta">
        {{ __('Verify Email') }}
    </h1>

    <p class="mt-1 text-sm text-gris-calido">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-4 text-sm font-medium text-tinta">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-6 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form method="POST" action="{{ route('verification.send') }}" class="w-full sm:w-auto">
            @csrf

            <x-primary-button class="w-full justify-center">
                {{ __('Resend Verification Email') }}
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="w-full rounded-md py-2 text-center text-sm text-gris-calido underline underline-offset-4 transition-colors duration-150 ease-in-out hover:text-tinta focus:outline-2 focus:outline-offset-2 focus:outline-brand-green sm:w-auto sm:px-0 sm:py-0 sm:text-start">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
