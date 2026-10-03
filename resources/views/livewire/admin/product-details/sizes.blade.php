<div>
    {{-- Una sola plantilla de columnas para la cabecera y para todas las filas, con las pistas
         de ancho fijo: si fueran `auto`, cada grid la mediría con su propio contenido y las
         cabeceras se desplazarían respecto a sus celdas. Cabecera y filas deben declarar el
         mismo `gap-x-*`, porque el hueco forma parte del ancho de las columnas. --}}
    @php($columns = 'gap-x-4 md:grid-cols-[minmax(0,1fr)_9rem_9rem_5.5rem_18rem]')

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-display text-3xl font-medium text-tinta sm:text-4xl">
                Tallas
            </h2>

            <p class="mt-2 text-sm text-gris-calido">
                Define qué tallas se venden en cada categoría y en qué orden.
            </p>
        </div>

        <x-primary-button
            type="button"
            wire:click="create"
            :disabled="$category === null"
            class="shrink-0 self-start"
        >
            <span aria-hidden="true">+</span> Nueva talla
        </x-primary-button>
    </div>

    {{-- Las tallas pertenecen a una categoría, así que la categoría se elige aquí y no se
         adivina: los nombres se repiten entre categorías (`M` de shirts y `M` de
         pantalones son dos tallas) y escribir una en la categoría equivocada no se
         detecta hasta que aparece una variante con la talla que no existe en ella. --}}
    <div class="mt-6 flex items-center gap-3">
        <x-select-input
            id="sizes-category"
            wire:model.live="categoryId"
            class="block w-full max-w-sm"
            aria-label="Categoría de las tallas"
        >
            @foreach ($sections as $section => $categories)
                <optgroup label="{{ \App\Enums\StoreSection::from($section)->label() }}">
                    @foreach ($categories as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </x-select-input>

        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar talla por nombre…"
            aria-label="Buscar talla"
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
            <span>Talla</span>
            <span class="text-center">Variantes</span>
            <span class="text-center">Estado</span>
            <span class="text-center">Orden</span>
            <span class="text-end">Acciones</span>
        </div>

        {{-- Filas reordenables. `data-flip-scope` marca las que participan en la animación FLIP. --}}
        <div class="divide-y divide-arena" data-flip-scope>
            @forelse ($rows as $row)
                @php($size = $row['size'])

                <div
                    wire:key="size-{{ $size->id }}"
                    data-flip-row
                    class="px-6 py-5 transition-colors duration-150 ease-in-out hover:bg-hueso/50"
                >
                    <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $size->name }}">
                                {{ $size->name }}
                            </span>
                        </div>

                        <div class="flex items-center justify-start text-sm text-gris-calido md:justify-center">
                            @if ($size->variants_count === 0)
                                <span class="text-gris-calido/60">Ninguna</span>
                            @else
                                <span>{{ $size->variants_count }}</span>
                            @endif
                        </div>

                        <x-admin-row-actions
                            :row-id="$size->id"
                            :row-name="$size->name"
                            type="talla"
                            :is-active="$size->is_active"
                            :is-first="$row['isFirst']"
                            :is-last="$row['isLast']"
                        />
                    </div>
                </div>
            @empty
                <div class="px-6 py-14 text-center text-sm text-gris-calido">
                    {{-- El arco de las puertas coloniales, como marca del estado vacío. --}}
                    <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                    @if ($category === null)
                        Aún no hay categorías en la tienda. Crea una primero y sus tallas vendrán con ella.
                    @elseif ($searching)
                        <p>No se encontraron tallas que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                        >
                            Limpiar búsqueda
                        </button>
                    @else
                        {{ $category->name }} todavía no tiene tallas. Añade la primera con el botón «Nueva talla».
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar talla' : 'Nueva talla'"
        title-id="size-form-title"
    >
        <div>
            <x-input-label for="size-name" value="Nombre" />

            @if ($nameIsLocked)
                {{-- El nombre de una talla con variantes viaja dentro del SKU de esas
                     variantes. Se muestra, no se escribe: ofrecer un campo que el catálogo
                     va a rechazar es peor que decir por qué no se puede cambiar. --}}
                <p class="mt-2 rounded-lg border border-arena bg-hueso/60 px-3 py-2 font-mono text-sm uppercase tracking-wider text-gris-calido">
                    {{ $name }}
                </p>
            @else
                <x-text-input
                    id="size-name"
                    wire:model="name"
                    type="text"
                    maxlength="20"
                    class="mt-1 block w-full"
                    placeholder="Ej. XXL"
                    data-modal-autofocus
                />

                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            @endif
        </div>

        @if ($editingId === null)
            <p class="text-sm text-gris-calido">
                La talla se añade al final de
                <span class="font-medium text-verde">{{ $category->name ?? '—' }}</span>
                y queda activa.
            </p>
        @elseif ($nameIsLocked)
            <p class="text-sm text-gris-calido">
                El nombre de una talla que ya tiene variantes forma parte del SKU de
                esas variantes, así que el catálogo no deja cambiarlo: desactiva la talla
                y añade otra.
            </p>
        @endif

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear talla' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>