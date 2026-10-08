{{--
    Formulario de recuperación de contraseña compartido por la página
    /forgot-password y el modal de acceso. Ver login-form.blade.php para el
    contrato de $prefix, $bag y $inModal.
--}}
@php
    $bagName = $bag ?? 'default';
    $fieldErrors = isset($errors) ? $errors->getBag($bagName) : new \Illuminate\Support\MessageBag;
    $fieldId = fn (string $field): string => $prefix ? $prefix.'_'.$field : $field;
@endphp

<x-store.status class="mt-6" :status="session('status')" />

<form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
    @csrf

    @if ($inModal)
        <input type="hidden" name="access_modal" value="forgot">
    @endif

    <x-store.field label="{{ __('Email') }}" :id="$fieldId('email')" name="email" type="email" :value="old('email')" :messages="$fieldErrors->get('email')" required autofocus autocomplete="email" />

    <x-store.button>{{ __('Email Password Reset Link') }}</x-store.button>
</form>

@if ($inModal)
    <p class="mt-6 text-center text-sm text-gris-calido">
        <x-store.link href="{{ route('login') }}" @click.prevent="openView('login')">Volver a iniciar sesión</x-store.link>
    </p>
@endif
