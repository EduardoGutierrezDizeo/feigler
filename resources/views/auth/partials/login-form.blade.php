{{--
    Formulario de inicio de sesión compartido por la página /login y el modal de
    acceso. Lo único que cambia entre contextos llega por parámetro:
      $prefix  string|null  prefijo de los id, para no repetir identificadores
                            cuando varias vistas conviven en el modal (null = página).
      $bag     string|null  bolsa de errores con nombre (null = bolsa por defecto).
      $inModal bool         dentro del modal: añade los campos ocultos y los
                            enlaces cambian de vista sin recargar.
--}}
@php
    $bagName = $bag ?? 'default';
    $fieldErrors = isset($errors) ? $errors->getBag($bagName) : new \Illuminate\Support\MessageBag;
    $fieldId = fn (string $field): string => $prefix ? $prefix.'_'.$field : $field;
@endphp

<x-store.status class="mt-6" :status="session('status')" />

<form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
    @csrf

    @if ($inModal)
        <input type="hidden" name="access_modal" value="login">
        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
    @endif

    <x-store.field label="{{ __('Email') }}" :id="$fieldId('email')" name="email" type="email" :value="old('email')" :messages="$fieldErrors->get('email')" required autofocus autocomplete="email" />

    <x-store.field label="{{ __('Password') }}" :id="$fieldId('password')" name="password" type="password" :messages="$fieldErrors->get('password')" required autocomplete="current-password" />

    <div>
        <label for="{{ $fieldId('remember_me') }}" class="inline-flex items-center gap-2 text-sm text-tinta">
            <input id="{{ $fieldId('remember_me') }}" type="checkbox" name="remember" class="size-4 rounded border-arena bg-crema text-verde transition-[border-color,box-shadow] duration-150 ease-in-out focus:ring-2 focus:ring-verde/25">
            <span>{{ __('Remember me') }}</span>
        </label>
    </div>

    <x-store.button>{{ __('Log in') }}</x-store.button>
</form>

@if ($inModal)
    @if (Route::has('password.request'))
        <p class="mt-6 text-center text-sm text-gris-calido">
            <x-store.link href="{{ route('password.request') }}" @click.prevent="openView('forgot')">{{ __('Forgot your password?') }}</x-store.link>
        </p>
    @endif

    <p class="mt-3 text-center text-sm text-gris-calido">
        ¿No tienes cuenta?
        <x-store.link href="{{ route('register') }}" @click.prevent="openView('register')">Crea tu cuenta</x-store.link>
    </p>
@else
    @if (Route::has('password.request'))
        <p class="mt-6 text-center text-sm text-gris-calido">
            <x-store.link :href="route('password.request')">{{ __('Forgot your password?') }}</x-store.link>
        </p>
    @endif

    <p class="mt-3 text-center text-sm text-gris-calido">
        ¿No tienes cuenta?
        <x-store.link :href="route('register')">Crea tu cuenta</x-store.link>
    </p>
@endif
