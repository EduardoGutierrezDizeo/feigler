{{--
    Pestaña «Eliminar cuenta» de Mi cuenta. Explica lo que pasa con la baja con
    un texto de config/tienda.php y abre el modal de confirmación, que solo vive
    en esta pestaña: el servidor decide si arranca abierto cuando el último
    envío falló (bolsa «deleteAccount»).
--}}
<div class="tarjeta p-6 sm:p-8">
    <p class="text-sm leading-relaxed text-gris-calido">{{ config('tienda.eliminar_cuenta_texto') }}</p>

    <div class="mt-6 flex justify-start">
        <x-store.button type="button" class="!w-auto bg-none !bg-ladrillo" @click="$dispatch('open-delete-account')">
            Eliminar mi cuenta
        </x-store.button>
    </div>
</div>

<x-store.delete-account-modal :abierto="$deleteAccountModalOpen ?? false" />