<div>
    {{-- Pestaña «Categorías» de la vista principal. Muestra, para cada categoría de la
         sección, la misma foto que pinta la portada y ofrece los tres orígenes de esa
         foto: la automática, una subida propia o una elegida de sus productos.

         El origen real de cada foto (qué se ha decidido, si la decisión sigue valiendo
         y qué URL se pinta) lo resuelve el servicio compartido con la portada, de modo
         que el panel nunca puede enseñar una foto distinta de la que la tienda muestra. --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-display text-3xl font-medium text-tinta sm:text-4xl">
                Categorías
            </h2>

            <p class="mt-2 text-sm text-gris-calido">
                Categorías de la sección {{ $activeSection->label() }}.
            </p>
        </div>
    </div>

    <x-admin-section-tabs :current="$activeSection->value" />

    <div class="tarjeta mt-8 overflow-hidden">
        <div class="divide-y divide-arena">
            @forelse ($categories as $category)
                @php($home = $homeImages[$category->id] ?? null)

                <div wire:key="category-home-{{ $category->id }}" class="px-6 py-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <div class="relative h-20 w-32 shrink-0 overflow-hidden rounded-lg border border-arena bg-hueso">
                            @if ($home !== null && $home['url'] !== null)
                                <img
                                    wire:key="category-img-{{ $category->id }}"
                                    src="{{ $home['url'] }}"
                                    alt=""
                                    class="h-full w-full object-cover"
                                >
                            @else
                                <span class="flex h-full w-full items-center justify-center px-2 text-center text-xs text-gris-calido">
                                    Sin foto
                                </span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $category->name }}">
                                    {{ $category->name }}
                                </span>

                                <span class="shrink-0 rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium text-gris-calido">{{ $category->sku_prefix }}</span>
                            </div>

                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                @if ($home !== null && $home['state'] === \App\Enums\HomeCategoryImageState::Chosen)
                                    <span class="font-medium text-verde">
                                        @if ($home['source'] === \App\Enums\HomeImageSource::Upload)
                                            Foto propia
                                        @else
                                            Foto de un producto
                                        @endif
                                    </span>
                                @endif

                                @if ($home !== null && $home['state'] === \App\Enums\HomeCategoryImageState::Broken)
                                    <span class="font-medium text-ladrillo">
                                        La foto elegida ya no está disponible; se usa la automática.
                                    </span>
                                @endif

                                @if ($category->products->isEmpty())
                                    <span class="font-medium text-ladrillo">Aún no aparece en el inicio</span>
                                @else
                                    <span class="text-gris-calido">
                                        {{ $category->products->count() }} {{ $category->products->count() === 1 ? 'producto visible' : 'productos visibles' }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <x-secondary-button type="button" wire:click="changePhoto({{ $category->id }})" class="shrink-0 self-start sm:self-center">
                            Cambiar foto
                        </x-secondary-button>
                    </div>
                </div>
            @empty
                <div class="px-6 py-14 text-center text-sm text-gris-calido">
                    <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                    <p>Aún no hay categorías en {{ $activeSection->label() }}.</p>
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="'Cambiar foto de «'.($editingCategory?->name ?? '').'»'"
        title-id="home-category-form-title"
    >
        @if ($showForm && $editingCategory)
            @php($options = [
                \App\Enums\HomeImageSource::Auto->value => [
                    'titulo' => 'Automática',
                    'descripcion' => 'La portada usa la miniatura del producto visible más reciente.',
                ],
                \App\Enums\HomeImageSource::Upload->value => [
                    'titulo' => 'Subir imagen propia',
                    'descripcion' => 'Una foto de la categoría, distinta de la de cualquier producto.',
                ],
                \App\Enums\HomeImageSource::Product->value => [
                    'titulo' => 'Elegir foto de producto',
                    'descripcion' => 'Una de las fotos de los productos visibles de la categoría.',
                ],
            ])

            <div class="space-y-3" role="radiogroup" aria-label="Origen de la foto de portada">
                @foreach ($options as $valor => $opcion)
                    <label
                        wire:key="photo-option-{{ $valor }}"
                        @class([
                            'flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3 transition-colors duration-150 ease-in-out',
                            'border-verde bg-hueso/50' => $photoOption === $valor,
                            'border-arena hover:border-gris-calido' => $photoOption !== $valor,
                        ])
                    >
                        <input
                            type="radio"
                            name="home-photo-option"
                            wire:model.live="photoOption"
                            value="{{ $valor }}"
                            class="mt-1 h-4 w-4 shrink-0 accent-verde"
                        >
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-tinta">{{ $opcion['titulo'] }}</span>
                            <span class="mt-0.5 block text-xs text-gris-calido">{{ $opcion['descripcion'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($photoOption === \App\Enums\HomeImageSource::Auto->value)
                <div class="flex justify-end">
                    <x-primary-button type="button" wire:click="useAutomatic" wire:loading.attr="disabled">
                        Usar automática
                    </x-primary-button>
                </div>
            @endif

            @if ($photoOption === \App\Enums\HomeImageSource::Upload->value)
                <div class="rounded-xl border border-dashed border-arena px-4 py-4">
                    <input
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp"
                        wire:model="imageUpload"
                        x-data="homeImageUploadPreview($wire, 'imageUpload')"
                        x-on:change="preview($el)"
                        class="block w-full text-sm text-gris-calido file:me-3 file:rounded-lg file:border-0 file:bg-hueso file:px-3 file:py-2 file:text-sm file:font-medium file:text-verde hover:file:bg-arena/60"
                        aria-label="Elegir una imagen para subir"
                    >

                    <template x-if="previewUrl">
                        <img :src="previewUrl" alt="Vista previa de la foto elegida" class="mt-4 h-40 w-full rounded-lg object-cover">
                    </template>

                    <template x-if="! previewUrl && @js($editingPhoto !== null ? $editingPhoto['url'] : null)">
                        <img src="{{ $editingPhoto !== null ? $editingPhoto['url'] : '' }}" alt="Foto que la categoría muestra hoy" class="mt-4 h-40 w-full rounded-lg object-cover">
                    </template>

                    <x-input-error :messages="$errors->get('imageUpload')" class="mt-2" />

                    <div class="mt-4 flex justify-end">
                        <x-primary-button type="button" wire:click="saveImage" wire:loading.attr="disabled">
                            Guardar imagen
                        </x-primary-button>
                    </div>
                </div>
            @endif

            @if ($photoOption === \App\Enums\HomeImageSource::Product->value)
                <div class="rounded-xl border border-arena px-4 py-4">
                    @if ($pickerImages->isEmpty())
                        <p class="text-sm text-gris-calido">
                            Aún no hay fotos de productos visibles en {{ $editingCategory->name }}.
                        </p>
                    @else
                        <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                            @foreach ($pickerImages as $imagen)
                                <button
                                    type="button"
                                    wire:key="picker-photo-{{ $imagen->id }}"
                                    wire:click="$set('selectedImageId', {{ $imagen->id }})"
                                    @class([
                                        'overflow-hidden rounded-lg border-2 transition-colors duration-150 ease-in-out',
                                        'border-verde' => $selectedImageId === $imagen->id,
                                        'border-transparent hover:border-arena' => $selectedImageId !== $imagen->id,
                                    ])
                                >
                                    <img
                                        src="{{ $imagen->thumbnailUrl() }}"
                                        alt=""
                                        class="aspect-square w-full object-cover"
                                    >
                                </button>
                            @endforeach
                        </div>

                        @if ($pickerTruncated)
                            <p class="mt-3 text-xs text-gris-calido">Se muestran las 48 más recientes.</p>
                        @endif

                        <x-input-error :messages="$errors->get('selectedImageId')" class="mt-2" />

                        @if ($selectedImageId !== null)
                            <div class="mt-4 flex justify-end">
                                <x-primary-button type="button" wire:click="useProductPhoto({{ $selectedImageId }})" wire:loading.attr="disabled">
                                    Usar esta foto
                                </x-primary-button>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
        @endif

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>