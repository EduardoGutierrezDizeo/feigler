<x-guest-layout>
    <h1 class="font-display text-3xl font-semibold text-tinta">
        Crea tu cuenta de Feigler
    </h1>

    <form method="POST" action="{{ route('register') }}" class="mt-8">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Last name -->
        <div class="mt-4">
            <x-input-label for="last_name" value="Apellido" />
            <x-text-input id="last_name" class="block mt-1 w-full" type="text" name="last_name" :value="old('last_name')" required autocomplete="family-name" />
            <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
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

        <!-- Phone -->
        <div class="mt-4">
            <x-input-label for="phone" value="Teléfono" />
            <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" required autocomplete="tel" />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <!-- Personal data authorization -->
        <div class="mt-4">
            <label for="terms" class="flex items-start gap-3 text-sm text-gris-calido">
                <input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms')) class="mt-0.5 size-4 rounded border-arena text-verde focus:ring-verde/30" />
                <span>{{ config('tienda.datos_personales_texto') }}</span>
            </label>
            <x-input-error :messages="$errors->get('terms')" class="mt-2" />
        </div>

        <div class="mt-6 flex flex-col-reverse items-center gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a class="w-full rounded-md py-2 text-center text-sm text-gris-calido underline underline-offset-4 transition-colors duration-150 ease-in-out hover:text-tinta focus:outline-2 focus:outline-offset-2 focus:outline-brand-green sm:w-auto sm:px-0 sm:py-0 sm:text-start" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="w-full sm:ms-auto sm:w-auto">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
