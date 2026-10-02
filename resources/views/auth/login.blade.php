<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold text-tinta">
        {{ __('Log In') }}
    </h1>

    <p class="mt-2 text-sm text-gris-calido">
        Acceso al panel de Feigler
    </p>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8">
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
                <input id="remember_me" type="checkbox" class="rounded border-arena bg-crema text-verde transition-[border-color,box-shadow] duration-150 ease-in-out focus:ring-2 focus:ring-verde/25" name="remember">
                <span class="ms-2 text-sm text-tinta">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-6 flex flex-col-reverse items-center gap-3 sm:flex-row sm:items-center sm:justify-between">
            @if (Route::has('password.request'))
                <a class="w-full rounded-md py-2 text-center text-sm text-gris-calido underline underline-offset-4 transition-colors duration-150 ease-in-out hover:text-brand-green focus:outline-2 focus:outline-offset-2 focus:outline-brand-green sm:w-auto sm:px-0 sm:py-0 sm:text-start" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="w-full sm:ms-auto sm:w-auto">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
