{{--
    Pestaña «Variantes» del modal de producto.

    Es un componente hijo: recibe el producto que se está editando y no toca la
    lista de productos de abajo. Cada cambio despacha `product-variants-changed`
    para que el padre relea el stock y el estado que deriva de él.

    Sin tabla y sin scroll horizontal: cada variante es una tarjeta que en
    pantallas estrechas se apila y en anchas reparte sus datos en columnas.
--}}
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="font-display text-base font-semibold text-verde">Variantes</h3>

            <p class="mt-1 text-xs text-gris-calido">
                @if ($variants->isEmpty())
                    Este producto todavía no tiene variantes, así que su estado es «Sin variantes».
                @else
                    {{ $variants->count() }}
                    {{ $variants->count() === 1 ? 'variante' : 'variantes' }}.
                    El stock del producto es el total de las variantes activas.
                @endif
            </p>
        </div>

        @unless ($showForm)
            <x-secondary-button type="button" wire:click="startCreating" wire:loading.attr="disabled">
                Nueva variante
            </x-secondary-button>
        @endunless
    </div>

    {{-- Alta y edición --}}
    @if ($showForm)
        <form wire:submit="save" class="tarjeta space-y-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h4 class="text-sm font-semibold text-verde">
                    {{ $editingId !== null ? 'Editar variante' : 'Nueva variante' }}
                </h4>

                <button
                    type="button"
                    wire:click="cancelForm"
                    class="text-xs font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
                >
                    Descartar
                </button>
            </div>

            @if ($editingId !== null)
                <p class="text-xs text-gris-calido">
                    SKU <span class="font-medium">{{ $editingSku }}</span>
                    <span class="mt-1 block">
                        El SKU no se edita: la talla y el color ya quedaron escritos en él.
                    </span>
                </p>
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="variant-size" value="Talla" />
                    <x-select-input id="variant-size" wire:model="sizeId" class="mt-1 block w-full" required>
                        <option value="">— Selecciona una talla —</option>
                        @foreach ($sizes as $size)
                            {{-- La talla que la variante ya tiene se ofrece aunque esté
                                 desactivada: quitarla del select obligaría a mover la
                                 variante a otra talla para poder guardarla. --}}
                            <option value="{{ $size->id }}">
                                {{ $size->name }}{{ $size->is_active ? '' : ' (inactiva)' }}
                            </option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('sizeId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="variant-color" value="Color" />
                    <x-select-input id="variant-color" wire:model="colorId" class="mt-1 block w-full" required>
                        <option value="">— Selecciona un color —</option>
                        @foreach ($colors as $color)
                            {{-- El color que la variante ya tiene se ofrece aunque esté
                                 desactivado: quitarla del select obligaría a mover la
                                 variante a otro color para poder guardarla. --}}
                            <option value="{{ $color->id }}">
                                {{ $color->name }}{{ $color->is_active ? '' : ' (inactivo)' }}
                            </option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('colorId')" class="mt-2" />
                </div>

                @if ($editingId === null)
                    <div class="sm:col-span-2">
                        <x-input-label for="variant-stock" value="Stock inicial" />
                        <x-text-input
                            id="variant-stock"
                            wire:model="initialStock"
                            type="text"
                            inputmode="numeric"
                            class="mt-1 block w-full"
                        />
                        <x-input-error :messages="$errors->get('initialStock')" class="mt-2" />
                    </div>
                @endif
            </div>

            <p class="text-xs text-gris-calido">
                El precio lo hereda la variante del producto: {{ '$'.number_format((float) $product->base_price, 0, ',', '.') }}.
            </p>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <x-secondary-button type="button" wire:click="cancelForm">Cancelar</x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    {{ $editingId !== null ? 'Guardar cambios' : 'Crear variante' }}
                </x-primary-button>
            </div>
        </form>
    @endif

    {{-- Ajuste de stock --}}
    @if ($adjustingId !== null)
        @php
            $variant = $variants->firstWhere('id', $adjustingId);
        @endphp

        <form wire:submit="adjustStock" class="tarjeta space-y-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h4 class="text-sm font-semibold text-verde">Ajustar stock de {{ $variant?->sku }}</h4>

                <button
                    type="button"
                    wire:click="cancelAdjusting"
                    class="text-xs font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
                >
                    Descartar
                </button>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="variant-stock-delta" value="Unidades" />
                    <x-text-input
                        id="variant-stock-delta"
                        wire:model="stockDelta"
                        type="text"
                        inputmode="numeric"
                        class="mt-1 block w-full"
                        placeholder="Ej. 10 o -3"
                    />
                    <p class="mt-2 text-xs text-gris-calido">Un número positivo suma unidades, uno negativo las retira.</p>
                    <x-input-error :messages="$errors->get('stockDelta')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="variant-stock-reason" value="Motivo" />
                    <x-text-input
                        id="variant-stock-reason"
                        wire:model="stockReason"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej. Reposición de almacén"
                    />
                    <x-input-error :messages="$errors->get('stockReason')" class="mt-2" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <x-secondary-button type="button" wire:click="cancelAdjusting">Cancelar</x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">Aplicar ajuste</x-primary-button>
            </div>
        </form>
    @endif

    {{-- Listado --}}
    @if ($variants->isEmpty())
        <div class="tarjeta px-6 py-10 text-center text-sm text-gris-calido">
            <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

            @if ($showForm)
                Guarda la variante para verla aquí.
            @else
                Aún no hay variantes. Crea la primera con el botón «Nueva variante».
            @endif
        </div>
    @else
        <ul class="space-y-4">
            @foreach ($variants as $variant)
                @php
                    $heredaPrecio = $variant->price_override === null;
                    $precio = '$'.number_format((float) ($variant->price_override ?? $product->base_price), 0, ',', '.');
                @endphp

                <li wire:key="variant-{{ $variant->id }}" class="tarjeta p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="etiqueta">{{ $variant->size->name }}</span>

                                <span class="text-sm font-semibold text-tinta">
                                    {{ $variant->color->name ?? '—' }}
                                </span>
                            </div>

                            <p class="mt-1 text-xs text-gris-calido">{{ $variant->sku }}</p>
                        </div>

                        {{-- El switch marca la visibilidad en el escaparate; «Agotado» y «Sin
                             variantes» los calcula el modelo a partir del stock y no se escriben. --}}
                        <button
                            type="button"
                            role="switch"
                            aria-checked="{{ $variant->is_active ? 'true' : 'false' }}"
                            aria-label="Cambiar el estado de la variante {{ $variant->sku }}"
                            wire:click="toggle({{ $variant->id }})"
                            class="inline-flex items-center gap-2 text-sm"
                        >
                            <span @class([
                                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ease-in-out',
                                'bg-arena' => ! $variant->is_active,
                                'bg-verde' => $variant->is_active,
                            ])>
                                <span @class([
                                    'inline-block h-5 w-5 rounded-full bg-crema shadow-lift transition-transform duration-200 ease-in-out',
                                    'translate-x-0.5' => ! $variant->is_active,
                                    'translate-x-5' => $variant->is_active,
                                ])></span>
                            </span>
                            <span class="text-tinta">{{ $variant->is_active ? 'Activa' : 'Inactiva' }}</span>
                        </button>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gris-calido">Precio</dt>
                            <dd @class([
                                'mt-1 text-sm font-semibold',
                                'text-gris-calido' => $heredaPrecio,
                                'text-tinta' => ! $heredaPrecio,
                            ])>
                                {{ $heredaPrecio ? "Hereda {$precio}" : $precio }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gris-calido">Stock</dt>
                            <dd class="mt-1 text-sm font-semibold text-tinta">
                                {{ $variant->stock }}
                                @if ($variant->stock === 0)
                                    <span class="block text-xs font-normal text-ladrillo">Agotada</span>
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gris-calido">Alta</dt>
                            <dd class="mt-1 text-sm text-tinta">{{ $variant->created_at?->format('d/m/Y') ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gris-calido">Ajuste</dt>
                            <dd class="mt-1 text-sm text-tinta">
                                @if ($adjustingId === $variant->id)
                                    <span class="text-verde">En curso…</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="startAdjusting({{ $variant->id }})"
                                        class="border-b border-laton pb-px text-sm font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                                    >
                                        Ajustar stock
                                    </button>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-5 flex flex-wrap items-center gap-5 border-t border-arena pt-4">
                        <button
                            type="button"
                            wire:click="startEditing({{ $variant->id }})"
                            class="border-b border-laton pb-px text-sm font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                        >
                            Editar
                        </button>

                        {{-- Solo aparece en el alta: la combinación que ya existe es la que
                             se quiere repetir con otra talla u otro color. --}}
                        <button
                            type="button"
                            wire:click="startCreatingFrom({{ $variant->id }})"
                            class="text-sm text-gris-calido transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                        >
                            Crear parecida
                        </button>

                        {{-- Siempre visible, como en categorías: si la variante tiene
                             movimientos o ventas, la acción dice por qué no se borra. --}}
                        <button
                            type="button"
                            x-on:click="$dispatch('ask-confirm', {
                                title: 'Eliminar variante',
                                message: 'Se eliminará «{{ $variant->sku }}». Si tiene movimientos de stock, la operación se cancelará y se explicará por qué.',
                                confirmLabel: 'Eliminar',
                                destructive: true,
                                onConfirm: () => $wire.delete({{ $variant->id }})
                            })"
                            class="text-sm font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                        >
                            Eliminar
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
