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

            <p class="text-xs text-gris-calido">Los campos marcados con <span class="text-ladrillo" aria-hidden="true">*</span> son obligatorios.</p>

            <x-store.field label="Nombre de quien recibe" name="recipient_name" :messages="$errors->address->get('recipient_name')" x-model="form.recipient_name" required maxlength="100" autocomplete="name" />

            <x-store.field label="Teléfono" name="phone" type="tel" inputmode="tel" :messages="$errors->address->get('phone')" x-model="form.phone" required maxlength="10" autocomplete="tel" />

            <div class="grid gap-5 sm:grid-cols-2">
                {{-- Departamento: listbox propio (mismo estilo de opciones que el selector de
                     categorías del panel), sin `x-if`; el <select> nativo se sustituye por un
                     campo oculto para no cambiar el cuerpo del POST. --}}
                <div class="relative" @keydown.escape.stop="openSelect = null" @click.outside="closeSelector('department')">
                    <label for="address-departamento" class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido">
                        Departamento
                        <span class="text-ladrillo" aria-hidden="true">*</span>
                    </label>

                    <button type="button" id="address-departamento" aria-haspopup="listbox"
                            :aria-expanded="(openSelect === 'department').toString()"
                            @click="openDropdown('department')"
                            class="mt-1.5 flex w-full items-center justify-between rounded-xl border border-arena bg-crema px-4 py-2.5 text-sm shadow-none transition focus:border-verde focus:ring-1 focus:ring-verde/20 {{ $errors->address->has('department_code') ? 'border-ladrillo focus:border-ladrillo focus:ring-ladrillo' : '' }}">
                        <span class="truncate" x-text="departmentLabel" :class="form.department_code ? 'text-tinta' : 'text-gris-calido/70'"></span>
                        <svg class="pointer-events-none h-5 w-5 shrink-0 text-gris-calido" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <input type="hidden" name="department_code" :value="form.department_code">

                    <div x-show="openSelect === 'department'" x-cloak role="listbox" aria-label="Departamento"
                         class="absolute z-20 mt-1.5 max-h-64 w-full overflow-y-auto rounded-xl border border-arena bg-crema shadow-suave"
                         style="display: none">
                        <button type="button" role="option" :aria-selected="(form.department_code === '').toString()"
                                @click="chooseDepartment('')"
                                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gris-calido hover:bg-hueso focus:bg-hueso focus:outline-none">
                            Elige un departamento
                        </button>
                        <template x-for="dept in departments" :key="dept.code">
                            <button type="button" role="option" :aria-selected="(form.department_code === dept.code).toString()"
                                    @click="chooseDepartment(dept.code)"
                                    :class="form.department_code === dept.code ? 'bg-hueso' : ''"
                                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-tinta hover:bg-hueso focus:bg-hueso focus:outline-none">
                                <span class="truncate" x-text="dept.name"></span>
                                <svg x-show="form.department_code === dept.code" class="h-4 w-4 shrink-0 text-verde" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 0 1.42l-7.5 7.5a1 1 0 0 1-1.42 0l-3.5-3.5a1 1 0 1 1 1.42-1.42L8.5 12.08l6.79-6.79a1 1 0 0 1 1.42 0Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </template>
                    </div>

                    @if ($errors->address->get('department_code'))
                        <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
                            @foreach ($errors->address->get('department_code') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Ciudad: depende del departamento; se deshabilita mientras no haya uno elegido. --}}
                <div class="relative" @keydown.escape.stop="openSelect = null" @click.outside="closeSelector('city')">
                    <label for="address-ciudad" class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido">
                        Ciudad
                        <span class="text-ladrillo" aria-hidden="true">*</span>
                    </label>

                    <button type="button" id="address-ciudad" aria-haspopup="listbox"
                            :aria-expanded="(openSelect === 'city').toString()"
                            :disabled="! form.department_code"
                            @click="openDropdown('city')"
                            class="mt-1.5 flex w-full items-center justify-between rounded-xl border border-arena bg-crema px-4 py-2.5 text-sm shadow-none transition focus:border-verde focus:ring-1 focus:ring-verde/20 disabled:cursor-not-allowed disabled:opacity-60 {{ $errors->address->has('city_code') ? 'border-ladrillo focus:border-ladrillo focus:ring-ladrillo' : '' }}">
                        <span class="truncate" x-text="cityLabel" :class="form.city_code ? 'text-tinta' : 'text-gris-calido/70'"></span>
                        <svg class="pointer-events-none h-5 w-5 shrink-0 text-gris-calido" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <input type="hidden" name="city_code" :value="form.city_code">

                    <div x-show="openSelect === 'city'" x-cloak role="listbox" aria-label="Ciudad"
                         class="absolute z-20 mt-1.5 max-h-64 w-full overflow-y-auto rounded-xl border border-arena bg-crema shadow-suave"
                         style="display: none">
                        <button type="button" role="option" :aria-selected="(form.city_code === '').toString()"
                                @click="chooseCity('')"
                                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gris-calido hover:bg-hueso focus:bg-hueso focus:outline-none">
                            Elige una ciudad
                        </button>
                        <template x-for="city in cityOptions" :key="city.code">
                            <button type="button" role="option" :aria-selected="(form.city_code === city.code).toString()"
                                    @click="chooseCity(city.code)"
                                    :class="form.city_code === city.code ? 'bg-hueso' : ''"
                                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-tinta hover:bg-hueso focus:bg-hueso focus:outline-none">
                                <span class="truncate" x-text="city.name"></span>
                                <svg x-show="form.city_code === city.code" class="h-4 w-4 shrink-0 text-verde" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 0 1.42l-7.5 7.5a1 1 0 0 1-1.42 0l-3.5-3.5a1 1 0 1 1 1.42-1.42L8.5 12.08l6.79-6.79a1 1 0 0 1 1.42 0Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </template>
                    </div>

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