<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-3xl font-semibold text-brand-green">
                Categorías
            </h1>

            <p class="mt-1 text-sm text-clay">
                Organiza el catálogo en categorías y subcategorías.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            Nueva categoría
        </x-primary-button>
    </div>

    <div class="mt-6 flex items-center gap-3">
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
                class="shrink-0 text-sm font-medium text-clay hover:text-terracotta"
            >
                Limpiar
            </button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-sand bg-parchment shadow-xs">
        {{-- Cabecera de columnas. Se oculta en móvil, donde cada fila
             apila sus propias etiquetas. --}}
        <div class="hidden gap-x-6 border-b border-gold/40 bg-cream px-5 py-3 text-xs font-semibold uppercase tracking-widest text-clay md:grid md:grid-cols-[minmax(0,1fr)_9rem_5.5rem_auto]">
            <span>Categoría</span>
            <span>Estado</span>
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
                    class="px-5 py-5 transition duration-150 ease-in-out even:bg-cream/40 hover:bg-cream/60"
                    x-data="{
                        expanded: @js($node['expanded']),
                        searching: @js($node['searching']),
                        rootMatches: @js($node['rootMatches']),
                        hasMatch: @js($node['hasMatch']),
                    }"
                    x-effect="if (searching && hasMatch) expanded = true"
                    data-expanded="{{ $node['expanded'] ? 'true' : 'false' }}"
                >
                    <div class="grid grid-cols-2 items-center gap-x-4 gap-y-3 md:grid-cols-[minmax(0,1fr)_9rem_5.5rem_auto] md:gap-x-6">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            @if ($hasChildren)
                                <button
                                    type="button"
                                    @click="expanded = ! expanded; $wire.toggleExpanded({{ $root->id }})"
                                    :aria-expanded="expanded ? 'true' : 'false'"
                                    :aria-label="expanded ? @js("Contraer {$root->name}") : @js("Expandir {$root->name}")"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-sand bg-parchment text-clay transition duration-150 ease-in-out hover:border-terracotta hover:bg-cream hover:text-terracotta focus:outline-hidden"
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

                            <span class="font-display text-lg font-semibold text-brand-green">
                                {{ $root->name }}
                            </span>

                            @if ($hasChildren)
                                <span class="rounded-full bg-cream px-2.5 py-0.5 text-xs font-medium text-clay ring-1 ring-gold/40">
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
                            <div class="overflow-hidden rounded-lg border border-sand bg-cream/50">
                                <div class="divide-y divide-sand">
                                    @foreach ($node['children'] as $childNode)
                                        @php($child = $childNode['category'])

                                        <div
                                            wire:key="child-{{ $child->id }}"
                                            data-flip-row
                                            x-show="! searching || rootMatches || {{ $childNode['matches'] ? 'true' : 'false' }}"
                                            class="px-5 py-4 transition duration-150 ease-in-out even:bg-parchment/70 hover:bg-parchment"
                                        >
                                            <div class="grid grid-cols-2 items-center gap-x-4 gap-y-3 md:grid-cols-[minmax(0,1fr)_9rem_5.5rem_auto] md:gap-x-6">
                                                <div class="col-span-2 flex items-center gap-3 ps-2 md:col-span-1">
                                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center text-clay" aria-hidden="true">└</span>

                                                    <span class="text-charcoal">
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
                            class="mt-2 font-medium text-terracotta hover:text-wood"
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

    @if ($showForm)
        <div class="fixed inset-0 z-40 bg-charcoal/60" wire:click="closeForm" aria-hidden="true"></div>

        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="category-form-title"
            class="fixed inset-y-0 end-0 z-50 flex w-full max-w-md flex-col border-s border-wood/40 bg-parchment shadow-xl"
        >
            <div class="flex items-center justify-between border-b border-sand px-6 py-4">
                <h2 id="category-form-title" class="font-display text-xl font-semibold text-brand-green">
                    {{ $editingId !== null ? 'Editar categoría' : ($parentId !== null ? 'Nueva subcategoría' : 'Nueva categoría') }}
                </h2>

                <button
                    type="button"
                    wire:click="closeForm"
                    class="rounded-md p-1 text-clay hover:bg-cream hover:text-terracotta"
                    aria-label="Cerrar formulario"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div>
                    <x-input-label for="category-name" value="Nombre" />
                    <x-text-input
                        id="category-name"
                        wire:model="name"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej. Camisetas"
                    />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    @if ($editingId !== null)
                        <x-input-label for="category-parent" value="Categoría padre (opcional)" />
                        <select
                            id="category-parent"
                            wire:model="parentId"
                            class="mt-1 block w-full rounded-md border-sand bg-parchment text-sm text-charcoal shadow-xs focus:border-brand-green focus:ring-brand-green"
                        >
                            <option value="">— Ninguna: será una categoría raíz —</option>
                            @foreach ($parentOptions as $parent)
                                <option value="{{ $parent->id }}" @selected($parentId === (int) $parent->id)>
                                    {{ $parent->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-clay">
                            Solo puedes elegir categorías raíz: las subcategorías no pueden tener hijas.
                        </p>
                        <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
                    @elseif ($parentId !== null)
                        <x-input-label value="Categoría padre" />
                        <p class="mt-1 rounded-md border border-gold/40 bg-cream px-3 py-2 text-sm text-brand-green">
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
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-sand px-6 py-4 sm:flex-row sm:justify-end">
                <x-secondary-button type="button" wire:click="closeForm">
                    Cancelar
                </x-secondary-button>

                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                    {{ $editingId !== null ? 'Guardar cambios' : ($parentId !== null ? 'Crear subcategoría' : 'Crear categoría') }}
                </x-primary-button>
            </div>
        </aside>
    @endif
</div>