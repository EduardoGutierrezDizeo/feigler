{{--
    Pestaña «Imágenes» del modal de producto.

    Es un componente hijo: recibe el producto que se está editando y no toca la
    lista de productos de abajo. Cada cambio despacha `product-images-changed`
    para que el padre vuelva a leer lo que muestre de las pictures.

    Las pictures van por color y en el orden en que se subieron, que es el único
    orden que existe: no hay reordenamiento a mano. La portada del producto es
    otra cosa, y por eso tiene su propio selector: es el color cuya imagen
    principal es la foto del producto en el catálogo.
--}}
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h3 class="font-display text-base font-semibold text-verde">Imágenes</h3>

            <p class="mt-1 text-xs text-gris-calido">
                @if ($colors->isEmpty())
                    Las imágenes se asignan por color, y un color nace de una variante.
                @else
                    {{ $product->images->count() }}
                    {{ $product->images->count() === 1 ? 'imagen' : 'imágenes' }}
                    en {{ $colors->count() }}
                    {{ $colors->count() === 1 ? 'color' : 'colores' }}.
                    Se muestran en el orden en que las subiste.
                @endif
            </p>
        </div>

        @if ($colors->isNotEmpty())
            <x-secondary-button type="button" wire:click="openVariantsTab">
                Ver variantes
            </x-secondary-button>
        @endif
    </div>

    {{-- Un producto sin variantes no tiene colores, y una imagen sin color no tiene
         sitio: la pestaña dice por qué está vacía y lleva a donde se resuelve. --}}
    @if ($colors->isEmpty())
        <div class="tarjeta px-6 py-10 text-center text-sm text-gris-calido">
            <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

            <p class="mx-auto max-w-md">
                Este producto todavía no tiene variantes, así que no tiene colores donde colocar imágenes.
                Crea la primera variante y el color aparecerá aquí para subir sus fotos.
            </p>

            <div class="mt-6">
                <x-secondary-button type="button" wire:click="openVariantsTab">
                    Ir a la pestaña Variantes
                </x-secondary-button>
            </div>
        </div>
    @else
        @php
            // Solo un color con fotos puede ser la portada: el resto no tendría una
            // imagen principal que mostrarse.
            $coloresConImagenes = $colors->filter(
                fn ($color): bool => ($galleries->get($color->getKey()) ?? collect())->isNotEmpty(),
            );
        @endphp

        {{-- Portada --}}
        @if ($coloresConImagenes->isNotEmpty())
            <div class="tarjeta space-y-3 p-5">
                <div>
                    <h4 class="text-sm font-semibold text-verde">Color de portada</h4>

                    <p class="mt-1 text-xs text-gris-calido">
                        Es el color cuya imagen principal es la foto del producto en el catálogo.
                        @if ($product->cover_color_id !== null)
                            Ahora mismo es «{{ $product->coverColor?->name }}».
                        @else
                            Todavía no hay ninguno elegido.
                        @endif
                    </p>
                </div>

                <div class="max-w-sm">
                    <x-select-input id="portada-producto" wire:model.live="coverColorId" class="block w-full">
                        <option value="">— Sin portada —</option>
                        @foreach ($coloresConImagenes as $color)
                            <option value="{{ $color->id }}" @selected($coverColorId === $color->id)>
                                {{ $color->name }}
                            </option>
                        @endforeach
                    </x-select-input>

                    <x-input-error :messages="$errors->get('coverColorId')" class="mt-2" />
                </div>
            </div>
        @endif

        {{-- Una tarjeta por color: su selector de archivos y su galería --}}
        <ul class="space-y-6">
            @foreach ($colors as $color)
                @php
                    $galeria = $galleries->get($color->getKey()) ?? collect();
                    $errores = $errors->get('uploads.'.$color->getKey());
                @endphp

                <li wire:key="color-imagenes-{{ $color->id }}" class="tarjeta space-y-5 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="text-sm font-semibold text-verde">{{ $color->name }}</h4>

                            @if ($product->cover_color_id === $color->id)
                                <span class="etiqueta">Portada</span>
                            @endif
                        </div>

                        <p class="text-xs text-gris-calido">
                            {{ $galeria->count() }}
                            {{ $galeria->count() === 1 ? 'imagen' : 'imágenes' }}
                        </p>
                    </div>

                    {{-- Subida: un selector de archivos por color, para que lo que se
                         elige en el azul nunca acabe en el rojo. --}}
                    <div class="flex flex-wrap items-start gap-3 border-b border-arena pb-5">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="imagenes-{{ $color->id }}" value="Imágenes de {{ $color->name }}" />

                            <input
                                id="imagenes-{{ $color->id }}"
                                type="file"
                                multiple
                                accept=".jpg,.jpeg,.png,.webp"
                                wire:model="uploads.{{ $color->id }}"
                                class="mt-1 block w-full cursor-pointer rounded-xl border border-arena bg-hueso/40 text-sm text-tinta file:mr-4 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-hueso file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-verde hover:file:bg-arena focus:outline-2 focus:outline-offset-2 focus:outline-verde"
                            />

                            <p class="mt-2 text-xs text-gris-calido">
                                JPG, PNG o WEBP de hasta 4 MB. Puedes elegir varias a la vez.
                            </p>

                            <x-input-error :messages="$errores" class="mt-2" />
                        </div>

                        <div class="flex shrink-0 items-center gap-3 pt-6">
                            <x-primary-button
                                type="button"
                                wire:click="uploadImages({{ $color->id }})"
                                wire:loading.attr="disabled"
                            >
                                Subir
                            </x-primary-button>

                            <span
                                wire:loading
                                wire:target="uploadImages({{ $color->id }})"
                                class="text-xs text-gris-calido"
                            >
                                Subiendo…
                            </span>
                        </div>
                    </div>

                    @if ($galeria->isEmpty())
                        <p class="text-xs text-gris-calido">
                            Este color todavía no tiene imágenes.
                        </p>
                    @else
                        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($galeria as $imagen)
                                <li wire:key="imagen-{{ $imagen->id }}" class="tarjeta overflow-hidden">
                                    <img
                                        src="{{ $imagen->url }}"
                                        alt="Imagen de {{ $color->name }}"
                                        class="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />

                                    <div class="flex items-center justify-between gap-2 p-2">
                                        {{-- La principal de su color es la primera que ve el
                                             catálogo; solo puede haber una, así que la que ya
                                             lo es se muestra marcada y no se puede volver a
                                             marcar. --}}
                                        <button
                                            type="button"
                                            wire:click="makePrimary({{ $imagen->id }})"
                                            @disabled($imagen->is_primary)
                                            aria-pressed="{{ $imagen->is_primary ? 'true' : 'false' }}"
                                            aria-label="Marcar esta imagen como principal de {{ $color->name }}"
                                            title="{{ $imagen->is_primary ? 'Ya es la principal' : 'Marcar como principal' }}"
                                            class="rounded-full p-1.5 transition-colors duration-150 ease-in-out focus:outline-2 focus:outline-offset-2 focus:outline-verde {{ $imagen->is_primary ? 'text-laton' : 'text-gris-calido hover:text-laton' }} disabled:cursor-default"
                                        >
                                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" @class(['fill-current' => $imagen->is_primary, 'fill-none' => ! $imagen->is_primary]) stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.13 5.11a.56.56 0 0 0 .47.35l5.31.53a.56.56 0 0 1 .33.95l-4 3.6a.56.56 0 0 0-.18.55l1.13 5.33a.56.56 0 0 1-.84.6L12 17.3a.56.56 0 0 0-.52 0l-4.84 2.74a.56.56 0 0 1-.84-.6l1.13-5.33a.56.56 0 0 0-.18-.55l-4-3.6a.56.56 0 0 1 .33-.95l5.31-.53a.56.56 0 0 0 .47-.35l2.13-5.11Z" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            x-on:click="$dispatch('ask-confirm', {
                                                title: 'Eliminar imagen',
                                                message: 'Se eliminará esta imagen de «{{ $color->name }}». Si es la principal, pasará a serlo la siguiente.',
                                                confirmLabel: 'Eliminar',
                                                destructive: true,
                                                onConfirm: () => $wire.delete({{ $imagen->id }})
                                            })"
                                            class="rounded-full p-1.5 text-gris-calido transition-colors duration-150 ease-in-out hover:bg-ladrillo/10 hover:text-ladrillo focus:outline-2 focus:outline-offset-2 focus:outline-ladrillo"
                                            title="Eliminar imagen"
                                            aria-label="Eliminar la imagen de {{ $color->name }}"
                                        >
                                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>