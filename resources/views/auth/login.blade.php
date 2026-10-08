<x-store.layout title="Iniciar sesión · Feigler">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                {{ __('Log In') }}
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Acceso a tu cuenta de Feigler
            </p>

            <x-store.status class="mt-6" :status="session('status')" />

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
                @csrf

                <x-store.field label="{{ __('Email') }}" name="email" type="email" :value="old('email')" :messages="$errors->get('email')" required autofocus autocomplete="email" />

                <x-store.field label="{{ __('Password') }}" name="password" type="password" :messages="$errors->get('password')" required autocomplete="current-password" />

                <div>
                    <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-tinta">
                        <input id="remember_me" type="checkbox" name="remember" class="size-4 rounded border-arena bg-crema text-verde transition-[border-color,box-shadow] duration-150 ease-in-out focus:ring-2 focus:ring-verde/25">
                        <span>{{ __('Remember me') }}</span>
                    </label>
                </div>

                <x-store.button>{{ __('Log in') }}</x-store.button>
            </form>

            @if (Route::has('password.request'))
                <p class="mt-6 text-center text-sm text-gris-calido">
                    <x-store.link :href="route('password.request')">{{ __('Forgot your password?') }}</x-store.link>
                </p>
            @endif

            <p class="mt-3 text-center text-sm text-gris-calido">
                ¿No tienes cuenta?
                <x-store.link :href="route('register')">Crea tu cuenta</x-store.link>
            </p>
        </div>
    </div>
</x-store.layout>
