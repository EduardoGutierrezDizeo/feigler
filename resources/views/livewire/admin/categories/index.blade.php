<div>
    {{-- Una sola plantilla de columnas para la cabecera y para todas las filas.

         Con una pista `auto` al final, cada grid mide esa pista con SU PROPIO
         contenido: en la cabecera «Acciones» y en cada fila los botones. Como el
         sobrante cae en la pista `1fr`, cada fila calculaba una columna de
         nombre distinta y las cabeceras acababan desplazadas respecto a sus
         celdas. Con la pista de acciones fija, todos los grids coinciden.

         Debe incluir `gap-x-*`: el hueco forma parte de la anchura de las
         columnas, así que cabecera y fila tienen que declarar el mismo. --}}
    @php($columns = 'gap-x-4 md:grid-cols-[minmax(0,1fr)_9rem_5.5rem_18rem]')

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-3xl font-semibold text-brand-green sm:text-4xl">
                Categorías
            </h1>

            <p class="mt-2 text-sm text-clay">
                Organiza el catálogo en categorías y subcategorías.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            Nueva categoría
        </x-primary-button>
    </div>

    <div class="mt-8 flex items-center gap-3">
        <x-text-input
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
                class="shrink-0 text-sm font-medium text-clay transition-colors duration-150 ease-in-out hover:text-terracotta active:opacity-80"
            >
                Limpiar
            </button>
        @endif
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-sand bg-parchment">
        {{-- Cabecera de columnas. Se oculta en móvil, donde cada fila
             apila sus propias etiquetas. Comparte plantilla y huecos con las
             filas (`$columns`), y por tanto también su alineación: `text-center`
             en Estado y Orden, `text-end` en Acciones. Sin fondo propio: el
             encabezado se separa del cuerpo solo con el borde inferior fino. --}}
        <div class="hidden border-b border-sand px-5 py-3 text-xs font-semibold uppercase tracking-widest text-clay md:grid {{ $columns }}">
            <span>Categoría</span>
            <span class="text-center">Estado</span>
            <span class="text-center">Orden</span>
            <span class="text-end">Acciones</span>
        </div>

        {{-- Filas reordenables. El scope marca qué filas participan en la
             animación FLIP: `moveReorder.capture` guarda su posición antes de
             que la petición salga hacia el servidor. --}}
        <div class="divide-y divide-sand" data-flip-scope>
            @forelse ($nodes as $node)
                @php($root = $node['category'])
                @php($hasChildren = $node['children']->isNotEmpty())

                <div
                    wire:key="root-{{ $root->id }}"
                    data-flip-row
                    class="px-5 py-5 transition-colors duration-150 ease-in-out even:bg-cream/40 hover:bg-cream/60"
                    x-data="{
                        expanded: @js($node['expanded']),
                        searching: @js($node['searching']),
                        rootMatches: @js($node['rootMatches']),
                        hasMatch: @js($node['hasMatch']),
                    }"
                    x-effect="if (searching && hasMatch) expanded = true"
                    data-expanded="{{ $node['expanded'] ? 'true' : 'false' }}"
                >
                    <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            @if ($hasChildren)
                                <button
                                    type="button"
                                    @click="expanded = ! expanded; $wire.toggleExpanded({{ $root->id }})"
                                    :aria-expanded="expanded ? 'true' : 'false'"
                                    :aria-label="expanded ? @js("Contraer {$root->name}") : @js("Expandir {$root->name}")"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-clay transition-colors duration-150 ease-in-out hover:bg-sand/50 hover:text-brand-green focus:outline-2 focus:outline-offset-2 focus:outline-brand-green"
                                >
                                    <svg
                                        class="h-5 w-5 transition-transform duration-200"
                                        :class="expanded ? 'rotate-90' : ''"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                </button>
                            @else
                                <span class="inline-flex h-8 w-8 shrink-0" aria-hidden="true"></span>
                            @endif

                            <span class="min-w-0 truncate font-display text-lg font-semibold text-brand-green" title="{{ $root->name }}">
                                {{ $root->name }}
                            </span>

                            @if ($hasChildren)
                                <span class="rounded-full bg-sand/60 px-2.5 py-0.5 text-xs font-medium text-clay">
                                    {{ $node['children']->count() }}
                                </span>
                            @endif
                        </div>

                        @include('livewire.admin.categories.actions', [
                            'category' => $root,
                            'isFirst' => $node['isFirst'],
                            'isLast' => $node['isLast'],
                        ])
                    </div>

                    @if ($hasChildren)
                        <div
                            x-cloak
                            x-show="expanded"
                            x-collapse.duration.250ms
                            class="mt-4"
                        >
                            {{-- El sangrado de las subcategorías va en la celda del nombre, no en este
                                 envoltorio: indentar el envoltorio correria también
                                 Estado, Orden y Acciones, y esas columnas dejarían de
                                 coincidir con la cabecera. --}}
                            <div class="overflow-hidden rounded-lg border-s border-sand">
                                <div class="divide-y divide-sand/70">
                                    @foreach ($node['children'] as $childNode)
                                        @php($child = $childNode['category'])

                                        <div
                                            wire:key="child-{{ $child->id }}"
                                            data-flip-row
                                            x-show="! searching || rootMatches || {{ $childNode['matches'] ? 'true' : 'false' }}"
                                            class="px-5 py-4 transition-colors duration-150 ease-in-out even:bg-cream/40 hover:bg-cream/60"
                                        >
                                            <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                                                <div class="col-span-2 flex items-center gap-3 ps-6 md:col-span-1">
                                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center text-sand" aria-hidden="true">└</span>

                                                    <span class="min-w-0 truncate text-charcoal" title="{{ $child->name }}">
                                                        {{ $child->name }}
                                                    </span>
                                                </div>

                                                @include('livewire.admin.categories.actions', [
                                                    'category' => $child,
                                                    'isFirst' => $childNode['isFirst'],
                                                    'isLast' => $childNode['isLast'],
                                                ])
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-14 text-center text-sm text-clay">
                    @if ($search !== '')
                        <p>No se encontraron categorías que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-terracotta transition-colors duration-150 ease-in-out hover:text-wood active:opacity-80"
                        >
                            Limpiar búsqueda
                        </button>
                    @else
                        Aún no hay categorías. Crea la primera con el botón «Nueva categoría».
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar categoría' : ($parentId !== null ? 'Nueva subcategoría' : 'Nueva categoría')"
        title-id="category-form-title"
    >
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
            @if ($editingId !== null)
                <x-input-label for="category-parent" value="Categoría padre (opcional)" />
                <x-select-input
                    id="category-parent"
                    wire:model="parentId"
                    class="mt-1 block w-full"
                >
                    <option value="">— Ninguna: será una categoría raíz —</option>
                    @foreach ($parentOptions as $parent)
                        <option value="{{ $parent->id }}" @selected($parentId === (int) $parent->id)>
                            {{ $parent->name }}
                        </option>
                    @endforeach
                </x-select-input>
                <p class="mt-1 text-xs text-clay">
                    Solo puedes elegir categorías raíz: las subcategorías no pueden tener hijas.
                </p>
                <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
            @elseif ($parentId !== null)
                <x-input-label value="Categoría padre" />
                <p class="mt-1 rounded-md border border-sand bg-cream px-3 py-2 text-sm text-brand-green">
                    Creando subcategoría dentro de: {{ $parentOptions->firstWhere('id', $parentId)?->name }}
                </p>
                <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
            @else
                <x-input-label value="Categoría padre" />
                <p class="mt-1 text-sm text-clay">
                    Se creará como categoría raíz.
                </p>
            @endif
        </div>

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : ($parentId !== null ? 'Crear subcategoría' : 'Crear categoría') }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>
