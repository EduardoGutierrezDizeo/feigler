{{--
    Modal de direcciones de Mi cuenta (solo en la pestaña Direcciones). Está en
    Alpine.data('addressModal'); abre con el evento de ventana `open-address`
    (detail.mode: create | edit | delete y detail.id), cierra con Escape, con el
    fondo o con el botón, y atrapa el Tab sin dependencias, igual que el modal de
    acceso de la tienda.

    El servidor decide el estado inicial (abierto y modo) cuando el último envío
    falló, leyendo la bolsa «address» y el campo oculto `address_mode`. Los datos
    de ubicación (departamentos y municipios) se pasan aquí UNA vez, solo porque
    esta pestaña es la activa; las demás pestañas y el resto de la tienda no los
    reciben.
--}}
@props([
    'direcciones' => collect(),
    'departamentos' => [],
    'ciudades' => [],
    'user' => null,
    'abierto' => false,
    'modoInicial' => 'create',
    'idInicial' => null,
    'viejo' => null,
])

@php
    use App\Services\Storefront\CustomerAddresses;

    $mapaDirecciones = $direcciones->mapWithKeys(function ($direccion) {
        return [$direccion->id => $direccion->only([
            'id',
            'recipient_name',
            'phone',
            'department_code',
            'department',
            'city_code',
            'city',
            'label',
            'line1',
            'line2',
            'instructions',
            'is_default',
        ])];
    })->all();

    $prefillCrear = [
        'nombre' => trim((string) $user?->name.' '.((string) $user?->last_name)),
        'telefono' => (string) $user?->phone,
    ];

    $limite = CustomerAddresses::LIMITE;
@endphp

<div
    x-data="addressModal({ open: @js((bool) $abierto), mode: @js($modoInicial), addressId: @js($idInicial), old: @js($viejo), create: @js($prefillCrear), addresses: @js($mapaDirecciones), ubicaciones: @js(['departments' => $departamentos, 'cities' => $ciudades]), storeUrl: @js(route('account.addresses.store')), updateUrl: @js(route('account.addresses.update', ['address' => '__ADDRESS__'])), deleteUrl: @js(route('account.addresses.destroy', ['address' => '__ADDRESS__'])) })"
    x-show="open"
    x-cloak
    @open-address.window="openModal($event.detail?.mode, $event.detail?.id)"
    @keydown.escape.window="close()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="address-modal-title"
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

        <h2 id="address-modal-title" class="font-display text-3xl font-semibold text-verde">Dirección</h2>

        {{-- Alta y edición: un solo formulario que cambia de action y de verbo. --}}
        <form method="POST"
              x-show="mode === 'create' || mode === 'edit'"
              x-cloak
              x-bind:action="mode === 'edit' ? updateUrl.replace('__ADDRESS__', addressId ?? 0) : storeUrl"
              class="mt-5 space-y-5">
            @csrf
            <input type="hidden" name="_method" value="PUT" x-bind:disabled="mode === 'create'">
            <input type="hidden" name="address_mode" x-bind:value="mode === 'edit' ? addressId : 'create'">

            <x-store.field label="Nombre de quien recibe" name="recipient_name" :messages="$errors->address->get('recipient_name')" x-model="form.recipient_name" required maxlength="100" autocomplete="name" />

            <x-store.field label="Teléfono" name="phone" type="tel" inputmode="tel" :messages="$errors->address->get('phone')" x-model="form.phone" required maxlength="10" autocomplete="tel" />

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="address-departamento" class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido">Departamento</label>
                    <select id="address-departamento" name="department_code" x-model="form.department_code" required
                            @change="changeDepartment()"
                            class="mt-1.5 block w-full rounded-xl border border-arena bg-crema px-4 py-2.5 text-sm text-tinta focus:border-verde focus:ring-1 focus:ring-verde/20">
                        <option value="">Elige un departamento</option>
                        <template x-for="dept in departments" :key="dept.code">
                            <option :value="dept.code" x-text="dept.name"></option>
                        </template>
                    </select>
                    @if ($errors->address->get('department_code'))
                        <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
                            @foreach ($errors->address->get('department_code') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div>
                    <label for="address-ciudad" class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido">Ciudad</label>
                    <select id="address-ciudad" name="city_code" x-model="form.city_code" required
                            class="mt-1.5 block w-full rounded-xl border border-arena bg-crema px-4 py-2.5 text-sm text-tinta focus:border-verde focus:ring-1 focus:ring-verde/20">
                        <option value="">Elige una ciudad</option>
                        <template x-for="city in cityOptions" :key="city.code">
                            <option :value="city.code" x-text="city.name"></option>
                        </template>
                    </select>
                    @if ($errors->address->get('city_code'))
                        <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
                            @foreach ($errors->address->get('city_code') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <x-store.field label="Dirección" name="line1" :messages="$errors->address->get('line1')" x-model="form.line1" required maxlength="150" autocomplete="street-address" />

            <x-store.field label="Complemento" name="line2" :messages="$errors->address->get('line2')" x-model="form.line2" maxlength="100" />

            <x-store.field label="Indicaciones para el repartidor" name="instructions" :messages="$errors->address->get('instructions')" x-model="form.instructions" maxlength="200" />

            <x-store.field label="Nombre de la dirección" name="label" :messages="$errors->address->get('label')" x-model="form.label" maxlength="30" placeholder="Casa, trabajo…" />

            <div class="flex gap-3">
                <x-store.button type="button" class="!w-auto" @click="close()">Cancelar</x-store.button>
                <x-store.button class="!w-auto" x-text="mode === 'edit' ? 'Guardar cambios' : 'Guardar dirección'"></x-store.button>
            </div>

            @if ($direcciones->count() >= $limite)
                <p class="text-sm text-gris-calido">Ya guardaste el máximo de {{ $limite }} direcciones.</p>
            @endif
        </form>

        {{-- Confirmación de eliminación: resumen de la dirección y formulario DELETE. --}}
        <div x-show="mode === 'delete'" x-cloak class="mt-5">
            <p class="text-sm text-gris-calido">Vas a eliminar esta dirección:</p>

            <p class="mt-3 rounded-xl border border-arena bg-hueso/40 p-4 text-sm text-tinta" x-text="deleteSummary"></p>

            <form method="POST" x-bind:action="deleteUrl.replace('__ADDRESS__', addressId ?? 0)" class="mt-5">
                @csrf
                @method('DELETE')

                <div class="flex gap-3">
                    <x-store.button type="button" class="!w-auto" @click="close()">Cancelar</x-store.button>
                    <x-store.button class="!w-auto">Eliminar dirección</x-store.button>
                </div>
            </form>
        </div>
    </div>
</div>