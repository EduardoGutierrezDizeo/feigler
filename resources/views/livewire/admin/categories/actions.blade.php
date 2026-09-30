@props([
    'category',
    'isFirst' => false,
    'isLast' => false,
])

<div class="ms-auto flex items-center gap-3">
    <button
        type="button"
        role="switch"
        aria-checked="{{ $category->is_active ? 'true' : 'false' }}"
        wire:click="toggleActive({{ $category->id }})"
        class="inline-flex items-center gap-2 text-sm font-medium"
    >
        <span @class([
            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
            'bg-emerald-500' => $category->is_active,
            'bg-gray-300 dark:bg-gray-600' => ! $category->is_active,
        ])>
            <span @class([
                'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                'translate-x-5' => $category->is_active,
                'translate-x-0.5' => ! $category->is_active,
            ])></span>
        </span>
        <span class="text-gray-700 dark:text-gray-300">
            {{ $category->is_active ? 'Activo' : 'Inactivo' }}
        </span>
    </button>

    <span class="inline-flex items-center gap-1">
        <button
            type="button"
            wire:click="moveUp({{ $category->id }})"
            title="Mover hacia arriba"
            aria-label="Mover {{ $category->name }} hacia arriba"
            @disabled($isFirst)
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 text-base font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white dark:disabled:hover:bg-transparent"
        >
            ↑
        </button>

        <button
            type="button"
            wire:click="moveDown({{ $category->id }})"
            title="Mover hacia abajo"
            aria-label="Mover {{ $category->name }} hacia abajo"
            @disabled($isLast)
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 text-base font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white dark:disabled:hover:bg-transparent"
        >
            ↓
        </button>
    </span>

    <span class="inline-flex items-center gap-3">
        @if ($category->parent_id === null)
            <button
                type="button"
                wire:click="createSubcategory({{ $category->id }})"
                class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
            >
                Agregar subcategoría
            </button>
        @endif

        <button
            type="button"
            wire:click="edit({{ $category->id }})"
            class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
        >
            Editar
        </button>

        <button
            type="button"
            x-on:click="confirm(@js('¿Eliminar la categoría «' . $category->name . '»? Esta acción no se puede deshacer.')) && $wire.delete({{ $category->id }})"
            class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
        >
            Eliminar
        </button>
    </span>
</div>