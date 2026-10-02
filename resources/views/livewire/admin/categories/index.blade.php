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
                Organiza el catálogo en categorías y subcategorías.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nueva categoría
        </x-primary-button>
    </div>

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
            @forelse ($nodes as $node)
                @php($root = $node['category'])
                @php($hasChildren = $node['children']->isNotEmpty())

                <div
                    wire:key="root-{{ $root->id }}"
                    data-flip-row
                    class="px-6 py-5 transition-colors duration-150 ease-in-out hover:bg-hueso/50"
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
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde focus:outline-2 focus:outline-offset-2 focus:outline-verde"
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

                            <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $root->name }}">
                                {{ $root->name }}
                            </span>

@if ($hasChildren)
                                <span class="rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium text-gris-calido">
                                    {{ $node['children']->count() }}
                                </span>
                            @endif

                            @if ($root->sku_prefix)
                                <span class="rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium text-gris-calido">{{ $root->sku_prefix }}</span>
                            @else
                                <span class="rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium text-ladrillo">Sin prefijo</span>
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
                            {{-- El sangrado va en la celda del nombre y no en este envoltorio:
                                 indentar el envoltorio correría también Estado, Orden y Acciones. --}}
                            <div class="overflow-hidden rounded-lg border-s-2 border-laton/60">
                                <div class="divide-y divide-arena/70">
                                    @foreach ($node['children'] as $childNode)
                                        @php($child = $childNode['category'])

                                        <div
                                            wire:key="child-{{ $child->id }}"
                                            data-flip-row
                                            x-show="! searching || rootMatches || {{ $childNode['matches'] ? 'true' : 'false' }}"
                                            class="px-5 py-4 transition-colors duration-150 ease-in-out hover:bg-hueso/60"
                                        >
                                            <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                                                <div class="col-span-2 flex items-center gap-3 ps-6 md:col-span-1">
                                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center text-arena" aria-hidden="true">└</span>

                                                    <span class="min-w-0 truncate text-tinta" title="{{ $child->name }}">
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
                    wire:model.live="parentId"
                    class="mt-1 block w-full"
                >
                    <option value="">— Ninguna: será una categoría raíz —</option>
                    @foreach ($parentOptions as $parent)
                        <option value="{{ $parent->id }}" @selected($parentId === (int) $parent->id)>
                            {{ $parent->name }}
                        </option>
                    @endforeach
                </x-select-input>
                <p class="mt-2 text-xs text-gris-calido">
                    Solo puedes elegir categorías raíz: las subcategorías no pueden tener hijas.
                </p>
                <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
            @elseif ($parentId !== null)
                <x-input-label value="Categoría padre" />
                <p class="mt-2 rounded-lg border border-arena bg-hueso/60 px-3 py-2 text-sm text-verde">
                    Creando subcategoría dentro de: {{ $parentOptions->firstWhere('id', $parentId)?->name }}
                </p>
                <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
            @else
                <x-input-label value="Categoría padre" />
                <p class="mt-2 text-sm text-gris-calido">
                    Se creará como categoría raíz.
                </p>
            @endif
        </div>

        {{-- El prefijo solo tiene sentido en categorías raíz: es la raíz la que
             aporta el primer tramo del SKU de sus productos. Con
             `wire:model.live` en el selector de padre, el campo aparece y se
             oculta sin pulsar «Guardar». --}}
        @if ($parentId === null)
            <div>
                <x-input-label for="category-sku-prefix" value="Prefijo para SKU" />
                <x-text-input
                    id="category-sku-prefix"
                    wire:model="skuPrefix"
                    type="text"
                    maxlength="4"
                    class="mt-1 block w-full uppercase"
                    placeholder="Ej. PL"
                />
                <p class="mt-2 text-xs text-gris-calido">
                    Se usa al generar el SKU de los productos de esta categoría.
                </p>
                <x-input-error :messages="$errors->get('skuPrefix')" class="mt-2" />
            </div>
        @endif

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