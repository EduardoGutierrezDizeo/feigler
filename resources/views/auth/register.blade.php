<x-store.layout title="Crear cuenta · Feigler">
    <div class="mx-auto w-full max-w-lg px-4 py-12 sm:px-8">
        <div class="tarjeta p-6 sm:p-8">
            <h1 class="font-display text-3xl font-semibold text-verde">
                Crea tu cuenta de Feigler
            </h1>

            <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
                @csrf

                <x-store.field label="{{ __('Name') }}" name="name" :value="old('name')" :messages="$errors->get('name')" required autofocus autocomplete="given-name" />

                <x-store.field label="Apellido" name="last_name" :value="old('last_name')" :messages="$errors->get('last_name')" required autocomplete="family-name" />

                <x-store.field label="{{ __('Email') }}" name="email" type="email" :value="old('email')" :messages="$errors->get('email')" required autocomplete="email" />

                <x-store.field label="{{ __('Password') }}" name="password" type="password" :messages="$errors->get('password')" required autocomplete="new-password" />

                <x-store.field label="{{ __('Confirm Password') }}" name="password_confirmation" type="password" :messages="$errors->get('password_confirmation')" required autocomplete="new-password" />

                <x-store.field label="Teléfono" name="phone" type="tel" :value="old('phone')" :messages="$errors->get('phone')" required autocomplete="tel" inputmode="tel" />

                <div>
                    <label for="terms" class="flex items-start gap-3 text-sm text-gris-calido">
                        <input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms')) class="mt-0.5 size-4 rounded border-arena text-verde focus:ring-verde/30">
                        <span>{{ config('tienda.datos_personales_texto') }}</span>
                    </label>

                    @if ($errors->get('terms'))
                        <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
                            @foreach ($errors->get('terms') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <x-store.button>{{ __('Register') }}</x-store.button>
            </form>

            <p class="mt-6 text-center text-sm text-gris-calido">
                <x-store.link :href="route('login')">{{ __('Already registered?') }}</x-store.link>
            </p>
        </div>
    </div>
</x-store.layout>
