<x-guest-layout>
    <h1 class="font-display text-2xl font-semibold text-brand-green">
        {{ __('Log In') }}
    </h1>

    <p class="mt-1 text-sm text-clay">
        Acceso al panel de Feigler
    </p>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-6">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-sand bg-parchment text-brand-green shadow-xs focus:ring-brand-green" name="remember">
                <span class="ms-2 text-sm text-charcoal">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-6 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
            @if (Route::has('password.request'))
                <a class="rounded-md text-center text-sm text-clay underline underline-offset-4 hover:text-terracotta focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream sm:text-start" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="sm:ms-auto">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>