<div>
    {{-- Una sola plantilla de columnas para la cabecera y para todas las filas, con las pistas
         de ancho fijo: si fueran `auto`, cada grid la mediría con su propio contenido y las
         cabeceras se desplazarían respecto a sus celdas. Cabecera y filas deben declarar el
         mismo `gap-x-*`, porque el hueco forma parte del ancho de las columnas. --}}
    @php($columns = 'gap-x-4 md:grid-cols-[minmax(0,1fr)_9rem_9rem_5.5rem_18rem]')

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-display text-3xl font-medium text-tinta sm:text-4xl">
                Materiales
            </h2>

            <p class="mt-2 text-sm text-gris-calido">
                Las prendas que puede usar el catálogo y sus composiciones.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nuevo material
        </x-primary-button>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar material por nombre…"
            aria-label="Buscar material"
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
        <div class="etiqueta hidden border-b border-arena px-6 py-4 md:grid {{ $columns }}">
            <span>Material</span>
            <span class="text-center">Productos</span>
            <span class="text-center">Estado</span>
            <span class="text-center">Orden</span>
            <span class="text-end">Acciones</span>
        </div>

        {{-- Filas reordenables. `data-flip-scope` marca las que participan en la animación FLIP. --}}
        <div class="divide-y divide-arena" data-flip-scope>
            @forelse ($rows as $row)
                @php($material = $row['material'])

                <div
                    wire:key="material-{{ $material->id }}"
                    data-flip-row
                    class="px-6 py-5 transition-colors duration-150 ease-in-out hover:bg-hueso/50"
                >
                    <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $material->name }}">
                                {{ $material->name }}
                            </span>
                        </div>

                        <div class="flex items-center justify-start text-sm text-gris-calido md:justify-center">
                            @if ($material->products_count === 0)
                                <span class="text-gris-calido/60">Ninguno</span>
                            @else
                                <span>{{ $material->products_count }}</span>
                            @endif
                        </div>

                        <x-admin-row-actions
                            :row-id="$material->id"
                            :row-name="$material->name"
                            type="material"
                            :is-active="$material->is_active"
                            :is-first="$row['isFirst']"
                            :is-last="$row['isLast']"
                        />
                    </div>
                </div>
            @empty
                <div class="px-6 py-14 text-center text-sm text-gris-calido">
                    {{-- El arco de las puertas coloniales, como marca del estado vacío. --}}
                    <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                    @if ($searching)
                        <p>No se encontraron materiales que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                        >
                            Limpiar búsqueda
                        </button>
                    @else
                        Aún no hay materiales en la tienda. Crea el primero con el botón «Nuevo material».
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar material' : 'Nuevo material'"
        title-id="material-form-title"
    >
        <div>
            <x-input-label for="material-name" value="Nombre" />
            <x-text-input
                id="material-name"
                wire:model="name"
                type="text"
                maxlength="100"
                class="mt-1 block w-full"
                placeholder="Ej. Algodón"
                data-modal-autofocus
            />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <p class="text-sm text-gris-calido">
            @if ($editingId === null)
                El material se añade al final de la lista y queda activo.
            @else
                El nombre es único en toda la tienda, con o sin tildes y en mayúsculas o minúsculas.
            @endif
        </p>

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear material' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>