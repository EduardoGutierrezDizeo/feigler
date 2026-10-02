@props([
    'category',
    'isFirst' => false,
    'isLast' => false,
])

{{-- Celdas «Estado», «Orden» y «Acciones» de una fila de categoría. `contents` hace que
     participen directamente en el grid de la fila. La alineación replica la de la cabecera:
     Estado y Orden centrados desde `md` (cuando aparece la cabecera), Acciones al final. --}}
<div class="contents">
    <div class="flex items-center justify-start gap-2 text-sm md:justify-center">
        <button
            type="button"
            role="switch"
            aria-checked="{{ $category->is_active ? 'true' : 'false' }}"
            wire:click="toggleActive({{ $category->id }})"
            class="inline-flex items-center gap-2"
        >
            <span @class([
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ease-in-out',
                'bg-verde' => $category->is_active,
                'bg-arena' => ! $category->is_active,
            ])>
                <span @class([
                    'inline-block h-5 w-5 rounded-full bg-crema shadow-lift transition-transform duration-200 ease-in-out',
                    'translate-x-5' => $category->is_active,
                    'translate-x-0.5' => ! $category->is_active,
                ])></span>
            </span>
            <span class="text-tinta">
                {{ $category->is_active ? 'Activo' : 'Inactivo' }}
            </span>
        </button>
    </div>

    <div class="flex items-center justify-start gap-2 md:justify-center">
        <button
            type="button"
            wire:click="moveUp({{ $category->id }})"
            x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
            title="Mover hacia arriba"
            aria-label="Mover {{ $category->name }} hacia arriba"
            @disabled($isFirst)
            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
        >
            ↑
        </button>

        <button
            type="button"
            wire:click="moveDown({{ $category->id }})"
            x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
            title="Mover hacia abajo"
            aria-label="Mover {{ $category->name }} hacia abajo"
            @disabled($isLast)
            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
        >
            ↓
        </button>
    </div>

    <div class="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm sm:gap-x-4 md:col-span-1 md:justify-end">
        @if ($category->parent_id === null)
            <button
                type="button"
                wire:click="createSubcategory({{ $category->id }})"
                class="whitespace-nowrap font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-verde active:opacity-80"
            >
                <span class="sm:hidden">+ Subcategoría</span>
                <span class="hidden sm:inline">Agregar subcategoría</span>
            </button>
        @endif

        <button
            type="button"
            wire:click="edit({{ $category->id }})"
            class="whitespace-nowrap border-b border-laton pb-px font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
        >
            Editar
        </button>

        <button
            type="button"
            x-on:click="$dispatch('ask-confirm', {
                title: 'Eliminar categoría',
                message: @js('Se eliminará «' . $category->name . '» de forma permanente.'),
                confirmLabel: 'Eliminar',
                destructive: true,
                onConfirm: () => $wire.delete({{ $category->id }}),
            })"
            class="whitespace-nowrap font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
        >
            Eliminar
        </button>
    </div>
</div>