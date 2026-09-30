<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                Categorías
            </h1>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Organiza el catálogo en categorías y subcategorías.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            Nueva categoría
        </x-primary-button>
    </div>

    @if ($notice)
        <div
            @class([
                'mt-6 flex items-start justify-between gap-4 rounded-lg border px-4 py-3 text-sm',
                'border-green-200 bg-green-50 text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-300' => $noticeType === 'success',
                'border-red-200 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-300' => $noticeType === 'error',
            ])
            role="status"
        >
            <p>{{ $notice }}</p>

            <button
                type="button"
                wire:click="$set('notice', null)"
                class="shrink-0 font-medium opacity-70 hover:opacity-100"
                aria-label="Cerrar aviso"
            >
                ✕
            </button>
        </div>
    @endif

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
                class="shrink-0 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
            >
                Limpiar
            </button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800">
        <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
            @forelse ($nodes as $node)
                @php($root = $node['category'])
                @php($hasChildren = $node['children']->isNotEmpty())

                <div
                    wire:key="root-{{ $root->id }}"
                    class="px-4 py-3"
                    x-data="{
                        expanded: @js($node['expanded']),
                        searching: @js($node['searching']),
                        rootMatches: @js($node['rootMatches']),
                        hasMatch: @js($node['hasMatch']),
                    }"
                    x-effect="if (searching && hasMatch) expanded = true"
                    data-expanded="{{ $node['expanded'] ? 'true' : 'false' }}"
                    x-on:category-moved.window="if ($event.detail.id === {{ $root->id }}) { $el.classList.add('flash-row'); setTimeout(() => $el.classList.remove('flash-row'), 700) }"
                >
                    <div class="flex items-center gap-3">
                        @if ($hasChildren)
                            <button
                                type="button"
                                @click="expanded = ! expanded; $wire.toggleExpanded({{ $root->id }})"
                                :aria-expanded="expanded ? 'true' : 'false'"
                                :aria-label="expanded ? @js("Contraer {$root->name}") : @js("Expandir {$root->name}")"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-hidden dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
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

                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $root->name }}
                        </span>

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
                            class="mt-3"
                        >
                            <div class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700/60 dark:border-gray-700">
                                @foreach ($node['children'] as $childNode)
                                    @php($child = $childNode['category'])

                                    <div
                                        wire:key="child-{{ $child->id }}"
                                        x-show="! searching || rootMatches || {{ $childNode['matches'] ? 'true' : 'false' }}"
                                        class="flex items-center gap-3 bg-gray-50/60 px-4 py-3 dark:bg-gray-900/40"
                                        x-on:category-moved.window="if ($event.detail.id === {{ $child->id }}) { $el.classList.add('flash-row'); setTimeout(() => $el.classList.remove('flash-row'), 700) }"
                                    >
                                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center text-gray-400" aria-hidden="true">└</span>

                                        <span class="text-gray-700 dark:text-gray-300">
                                            {{ $child->name }}
                                        </span>

                                        @include('livewire.admin.categories.actions', [
                                            'category' => $child,
                                            'isFirst' => $childNode['isFirst'],
                                            'isLast' => $childNode['isLast'],
                                        ])
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    @if ($search !== '')
                        <p>No se encontraron categorías que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
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
        <div class="fixed inset-0 z-40 bg-gray-900/50" wire:click="closeForm" aria-hidden="true"></div>

        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="category-form-title"
            class="fixed inset-y-0 end-0 z-50 flex w-full max-w-md flex-col bg-white shadow-xl dark:border-s dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h2 id="category-form-title" class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $editingId !== null ? 'Editar categoría' : ($parentId !== null ? 'Nueva subcategoría' : 'Nueva categoría') }}
                </h2>

                <button
                    type="button"
                    wire:click="closeForm"
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
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
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-indigo-600 dark:focus:ring-indigo-600"
                        >
                            <option value="">— Ninguna: será una categoría raíz —</option>
                            @foreach ($parentOptions as $parent)
                                <option value="{{ $parent->id }}" @selected($parentId === (int) $parent->id)>
                                    {{ $parent->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Solo puedes elegir categorías raíz: las subcategorías no pueden tener hijas.
                        </p>
                        <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
                    @elseif ($parentId !== null)
                        <x-input-label value="Categoría padre" />
                        <p class="mt-1 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                            Creando subcategoría dentro de: {{ $parentOptions->firstWhere('id', $parentId)?->name }}
                        </p>
                        <x-input-error :messages="$errors->get('parentId')" class="mt-2" />
                    @else
                        <x-input-label value="Categoría padre" />
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Se creará como categoría raíz.
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
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
