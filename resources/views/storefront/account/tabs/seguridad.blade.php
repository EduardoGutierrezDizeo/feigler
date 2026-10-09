@if (session('status') === 'password-updated')
    <x-store.status status="Tu contraseña se actualizó." />
@endif

<div class="tarjeta p-6 sm:p-8">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @method('PUT')
        @csrf

        <x-store.field label="Contraseña actual" name="current_password" type="password" :messages="$errors->updatePassword->get('current_password')" autocomplete="current-password" />

        <x-store.field label="Nueva contraseña" name="password" type="password" :messages="$errors->updatePassword->get('password')" autocomplete="new-password" />

        <x-store.field label="Confirmar nueva contraseña" name="password_confirmation" type="password" :messages="$errors->updatePassword->get('password_confirmation')" autocomplete="new-password" />

        <x-store.button>Cambiar contraseña</x-store.button>
    </form>
</div>
