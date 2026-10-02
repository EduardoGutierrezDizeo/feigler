<div>
    {{-- Una sola plantilla de columnas para la cabecera y para todas las filas, con la pista de
         acciones de ancho fijo: si fuera `auto`, cada grid la mediría con su propio contenido y
         las cabeceras se desplazarían respecto a sus celdas. Cabecera y filas deben declarar
         el mismo `gap-x-*`, porque el hueco forma parte del ancho de las columnas. --}}
    @php($columns = 'gap-x-4 md:grid-cols-[minmax(0,1fr)_9rem_5.5rem_18rem]')

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
                Categorías
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Organiza el catálogo por secciones y define el prefijo de cada categoría.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nueva categoría
        </x-primary-button>
    </div>

    <x-admin-section-tabs :current="$activeSection->value" />

    <div class="mt-8 flex items-center gap-3">
        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar categoría por nombre…"
            aria-label="Buscar categoría"
        />

        @if ($search !== '')
            <button
                type="button"
                wire:click="$set('search', '')"
                class="shrink-0 text-sm font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
            >
                Limpiar
            </button>
        @endif
    </div>

    <div class="tarjeta mt-6 overflow-hidden">
        {{-- Cabecera de columnas (oculta en móvil, donde cada fila apila sus etiquetas).
             Comparte `$columns` con las filas: Estado y Orden centrados, Acciones al final. --}}
        <div class="etiqueta hidden border-b border-arena px-6 py-4 md:grid {{ $columns }}">
            <span>Categoría</span>
            <span class="text-center">Estado</span>
            <span class="text-center">Orden</span>
            <span class="text-end">Acciones</span>
        </div>

        {{-- Filas reordenables. `data-flip-scope` marca las que participan en la animación FLIP. --}}
        <div class="divide-y divide-arena" data-flip-scope>
            @forelse ($rows as $row)
                @php($category = $row['category'])

                <div
                    wire:key="category-{{ $category->id }}"
                    data-flip-row
                    class="px-6 py-5 transition-colors duration-150 ease-in-out hover:bg-hueso/50"
                >
                    <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $category->name }}">
                                {{ $category->name }}
                            </span>

                            <span class="rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium text-gris-calido">{{ $category->sku_prefix }}</span>
                        </div>

                        @include('livewire.admin.categories.actions', [
                            'category' => $category,
                            'isFirst' => $row['isFirst'],
                            'isLast' => $row['isLast'],
                        ])
                    </div>
                </div>
            @empty
                <div class="px-6 py-14 text-center text-sm text-gris-calido">
                    {{-- El arco de las puertas coloniales, como marca del estado vacío. --}}
                    <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                    @if ($search !== '')
                        <p>No se encontraron categorías que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                        >
                            Limpiar búsqueda
                        </button>
                    @else
                        Aún no hay categorías en {{ $activeSection->label() }}. Crea la primera con el botón «Nueva categoría».
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar categoría' : 'Nueva categoría'"
        title-id="category-form-title"
    >
        {{-- La sección no se elige: una categoría no cambia de sección una vez creada. --}}
        <div>
            <x-input-label value="Sección" />
            <p class="mt-2 rounded-lg border border-arena bg-hueso/60 px-3 py-2 text-sm text-verde">
                {{ $activeSection->label() }}
            </p>
        </div>

        <div>
            <x-input-label for="category-name" value="Nombre" />
            <x-text-input
                id="category-name"
                wire:model="name"
                type="text"
                class="mt-1 block w-full"
                placeholder="Ej. Camisetas"
                data-modal-autofocus
            />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="category-sku-prefix" value="Prefijo SKU" />
            <x-text-input
                id="category-sku-prefix"
                wire:model="skuPrefix"
                type="text"
                maxlength="4"
                class="mt-1 block w-full uppercase"
                placeholder="Ej. PLH"
            />
            <p class="mt-2 text-xs text-gris-calido">
                @if ($activeSection === \App\Enums\StoreSection::Hombre)
                    Ej. PLH para polos de hombre. Será el inicio de la referencia: PLH-001.
                @elseif ($activeSection === \App\Enums\StoreSection::Mujer)
                    Ej. PLM para polos de mujer. Será el inicio de la referencia: PLM-001.
                @else
                    Ej. PLN para polos de niño. Será el inicio de la referencia: PLN-001.
                @endif
            </p>
            <x-input-error :messages="$errors->get('skuPrefix')" class="mt-2" />
        </div>

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear categoría' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>