{{--
    Formulario de registro compartido por la página /register y el modal de
    acceso. Ver login-form.blade.php para el contrato de $prefix, $bag y $inModal.
--}}
@php
    $bagName = $bag ?? 'default';
    $fieldErrors = isset($errors) ? $errors->getBag($bagName) : new \Illuminate\Support\MessageBag;
    $fieldId = fn (string $field): string => $prefix ? $prefix.'_'.$field : $field;
@endphp

<form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
    @csrf

    @if ($inModal)
        <input type="hidden" name="access_modal" value="register">
    @endif

    <x-store.field label="{{ __('Name') }}" :id="$fieldId('name')" name="name" :value="old('name')" :messages="$fieldErrors->get('name')" required autofocus autocomplete="given-name" />

    <x-store.field label="Apellido" :id="$fieldId('last_name')" name="last_name" :value="old('last_name')" :messages="$fieldErrors->get('last_name')" required autocomplete="family-name" />

    <x-store.field label="{{ __('Email') }}" :id="$fieldId('email')" name="email" type="email" :value="old('email')" :messages="$fieldErrors->get('email')" required autocomplete="email" />

    <x-store.field label="{{ __('Password') }}" :id="$fieldId('password')" name="password" type="password" :messages="$fieldErrors->get('password')" required autocomplete="new-password" />

    <x-store.field label="{{ __('Confirm Password') }}" :id="$fieldId('password_confirmation')" name="password_confirmation" type="password" :messages="$fieldErrors->get('password_confirmation')" required autocomplete="new-password" />

    <x-store.field label="Teléfono" :id="$fieldId('phone')" name="phone" type="tel" :value="old('phone')" :messages="$fieldErrors->get('phone')" required autocomplete="tel" inputmode="tel" />

    <div>
        <label for="{{ $fieldId('terms') }}" class="flex items-start gap-3 text-sm text-gris-calido">
            <input id="{{ $fieldId('terms') }}" type="checkbox" name="terms" value="1" @checked(old('terms')) class="mt-0.5 size-4 rounded border-arena text-verde focus:ring-verde/30">
            <span>{{ config('tienda.datos_personales_texto') }}</span>
        </label>

        @if ($fieldErrors->get('terms'))
            <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
                @foreach ($fieldErrors->get('terms') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-store.button>{{ __('Register') }}</x-store.button>
</form>

@if ($inModal)
    <p class="mt-6 text-center text-sm text-gris-calido">
        <x-store.link href="{{ route('login') }}" @click.prevent="openView('login')">{{ __('Already registered?') }}</x-store.link>
    </p>
@else
    <p class="mt-6 text-center text-sm text-gris-calido">
        <x-store.link :href="route('login')">{{ __('Already registered?') }}</x-store.link>
    </p>
@endif
