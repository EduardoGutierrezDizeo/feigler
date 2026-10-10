{{--
    Modal de confirmación para eliminar la cuenta (solo en la pestaña Eliminar
    cuenta). Está en Alpine.data('deleteAccountModal'); abre con el evento de
    ventana `open-delete-account`, cierra con Escape, con el fondo o con el
    botón, y atrapa el Tab sin dependencias. El servidor decide si arranca
    abierto (cuando la bolsa «deleteAccount» trae errores).

    El formulario es un DELETE normal a account.destroy con la contraseña
    actual; sin AJAX y sin partes eliminadas del DOM (`x-show` + `x-cloak`,
    nunca `x-if`).
--}}
@props(['abierto' => false])

<div
    x-data="deleteAccountModal({ open: @js((bool) $abierto) })"
    x-show="open"
    x-cloak
    @open-delete-account.window="openModal()"
    @keydown.escape.window="close()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="delete-account-modal-title"
    class="fixed inset-0 z-50 flex items-end justify-center bg-tinta/50 sm:items-center"
    style="display: none"
>
    <div class="absolute inset-0" @click="close()" aria-hidden="true"></div>

    <div x-ref="panel" @click.stop @keydown="trap($event)"
         class="relative z-10 max-h-[92vh] w-full overflow-y-auto rounded-t-3xl bg-crema p-6 shadow-lift sm:max-w-lg sm:rounded-3xl sm:p-8">
        <button type="button" @click="close()" aria-label="Cerrar"
                class="absolute right-4 top-4 grid size-9 place-items-center rounded-full text-gris-calido transition hover:bg-hueso/60 hover:text-verde">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>

        <h2 id="delete-account-modal-title" class="font-display text-3xl font-semibold text-verde">¿Eliminar tu cuenta?</h2>

        <p class="mt-4 text-sm leading-relaxed text-gris-calido">{{ config('tienda.eliminar_cuenta_texto') }}</p>

        <form method="POST" action="{{ route('account.destroy') }}" class="mt-6 space-y-5">
            @csrf
            @method('DELETE')

            <x-store.field label="Contraseña actual" name="current_password" type="password" x-ref="deletePassword" :messages="$errors->deleteAccount->get('current_password')" autocomplete="current-password" />

            <div class="flex gap-3">
                <x-store.button type="button" class="!w-auto" @click="close()">Cancelar</x-store.button>
                <button type="submit"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-ladrillo px-6 py-2.5 text-sm font-medium text-crema shadow-boton transition duration-150 ease-in-out hover:brightness-110 focus:outline-2 focus:outline-offset-2 focus:outline-verde active:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                    Sí, eliminar mi cuenta
                </button>
            </div>
        </form>
    </div>
</div>