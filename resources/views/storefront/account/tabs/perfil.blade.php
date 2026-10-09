@php
    $whatsappUrl = \App\Support\WhatsAppLink::for(config('tienda.whatsapp'));
@endphp

@if (session('status') === 'profile-updated')
    <x-store.status status="Tus datos se actualizaron." />
@endif

<div class="tarjeta p-6 sm:p-8">
    <div class="border-b border-arena pb-5">
        <p class="etiqueta">Correo</p>
        <p class="mt-2 text-sm text-tinta">{{ $user->email }}</p>
        <p class="mt-3 text-sm text-gris-calido">
            Para cambiar tu correo,
            @if ($whatsappUrl)
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-verde underline underline-offset-4 transition hover:text-verde-hondo">escríbenos por WhatsApp</a>.
            @else
                escríbenos.
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('account.profile.update') }}" class="mt-6 space-y-5">
        @method('PATCH')
        @csrf

        <x-store.field label="Nombre" name="name" :value="old('name', $user->name)" :messages="$errors->get('name')" required autocomplete="given-name" />

        <x-store.field label="Apellido" name="last_name" :value="old('last_name', $user->last_name)" :messages="$errors->get('last_name')" required autocomplete="family-name" />

        <x-store.field label="Teléfono" name="phone" type="tel" :value="old('phone', $user->phone)" :messages="$errors->get('phone')" required autocomplete="tel" inputmode="tel" />

        <x-store.button>Guardar cambios</x-store.button>
    </form>
</div>
