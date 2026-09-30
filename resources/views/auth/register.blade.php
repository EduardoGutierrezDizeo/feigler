<x-guest-layout>
    <h1 class="font-display text-2xl font-semibold text-brand-green">
        {{ __('Register') }}
    </h1>

    <p class="mt-1 text-sm text-clay">
        Crea una cuenta para entrar al panel de Feigler
    </p>

    <form method="POST" action="{{ route('register') }}" class="mt-6">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-6 flex flex-col-reverse items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a class="rounded-md text-center text-sm text-clay underline underline-offset-4 hover:text-terracotta focus:outline-hidden focus:ring-2 focus:ring-gold focus:ring-offset-2 focus:ring-offset-cream sm:text-start" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="sm:ms-auto">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>