@php
    use App\Services\Storefront\CustomerAddresses;
@endphp

@if (session('status') === 'address-stored')
    <x-store.status status="Dirección guardada." />
@elseif (session('status') === 'address-updated')
    <x-store.status status="Dirección actualizada." />
@elseif (session('status') === 'address-default')
    <x-store.status status="Dirección predeterminada actualizada." />
@elseif (session('status') === 'address-deleted')
    <x-store.status status="Dirección eliminada." />
@endif

@if ($errors->address->has('direcciones'))
    <p class="mt-4 rounded-xl border border-ladrillo/40 bg-ladrillo/10 p-3 text-sm text-ladrillo">
        {{ $errors->address->first('direcciones') }}
    </p>
@endif

<div class="mt-6 flex items-baseline justify-between gap-4">
    <h2 class="font-display text-3xl font-semibold text-verde">Direcciones</h2>

    @if ($addresses->count() < CustomerAddresses::LIMITE)
        <x-store.button type="button" class="!w-auto" @click="$dispatch('open-address', { mode: 'create' })">
            Agregar dirección
        </x-store.button>
    @else
        <p class="text-sm text-gris-calido">Ya guardaste el máximo de {{ CustomerAddresses::LIMITE }} direcciones.</p>
    @endif
</div>

<div class="mt-6 space-y-4">
    @forelse ($addresses as $address)
        <article class="tarjeta p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-medium text-tinta">{{ $address->label ?: 'Dirección' }}</h3>
                    @if ($address->is_default)
                        <span class="mt-1 inline-block rounded-full border border-verde/30 bg-verde/10 px-2.5 py-0.5 text-xs font-medium text-verde">Predeterminada</span>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-3 text-sm">
                    <button type="button" @click="$dispatch('open-address', { mode: 'edit', id: @js($address->id) })"
                            class="text-verde underline underline-offset-4 transition hover:text-verde-hondo">Editar</button>

                    @unless ($address->is_default)
                        <form method="POST" action="{{ route('account.addresses.default', $address) }}">
                            @method('PATCH')
                            @csrf
                            <x-store.link>Hacer predeterminada</x-store.link>
                        </form>
                    @endunless

                    <button type="button" @click="$dispatch('open-address', { mode: 'delete', id: @js($address->id) })"
                            class="text-gris-calido underline underline-offset-4 transition hover:text-ladrillo">Eliminar</button>
                </div>
            </div>

            <dl class="mt-4 space-y-1.5 text-sm">
                <div class="flex gap-3">
                    <dt class="w-36 shrink-0 font-medium text-tinta">Quien recibe</dt>
                    <dd class="text-gris-calido">{{ $address->recipient_name }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-36 shrink-0 font-medium text-tinta">Teléfono</dt>
                    <dd class="text-gris-calido">{{ $address->phone }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-36 shrink-0 font-medium text-tinta">Dirección</dt>
                    <dd class="text-gris-calido">{{ $address->line1 }}{{ $address->line2 ? ', '.$address->line2 : '' }}</dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-36 shrink-0 font-medium text-tinta">Ciudad</dt>
                    <dd class="text-gris-calido">{{ $address->city }} · {{ $address->department }}</dd>
                </div>
                @if ($address->instructions)
                    <div class="flex gap-3">
                        <dt class="w-36 shrink-0 font-medium text-tinta">Indicaciones</dt>
                        <dd class="text-gris-calido">{{ $address->instructions }}</dd>
                    </div>
                @endif
            </dl>
        </article>
    @empty
        <p class="rounded-xl border border-dashed border-arena p-6 text-center text-gris-calido">
            Aún no tienes direcciones guardadas.
        </p>
    @endforelse
</div>

<x-store.address-modal
    :direcciones="$addresses"
    :departamentos="$departments"
    :ciudades="$cities"
    :user="$user"
    :abierto="$addressModalOpen"
    :modo-inicial="$addressModalMode"
    :id-inicial="$addressModalId"
    :viejo="$addressModalOld"
/>