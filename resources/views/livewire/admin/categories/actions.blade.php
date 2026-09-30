@props([
    'category',
    'isFirst' => false,
    'isLast' => false,
])

{{-- Renders the "Estado", "Orden" and "Acciones" cells of a category row. `contents`
     makes those children participate directly in the row's grid. --}}
<div class="contents">
    <div class="flex items-center gap-2 text-sm font-medium">
        <button
            type="button"
            role="switch"
            aria-checked="{{ $category->is_active ? 'true' : 'false' }}"
            wire:click="toggleActive({{ $category->id }})"
            class="inline-flex items-center gap-2"
        >
            <span @class([
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                'bg-brand-green' => $category->is_active,
                'bg-sand' => ! $category->is_active,
            ])>
                <span @class([
                    'inline-block h-5 w-5 rounded-full bg-parchment shadow transition-transform',
                    'translate-x-5' => $category->is_active,
                    'translate-x-0.5' => ! $category->is_active,
                ])></span>
            </span>
            <span class="text-charcoal">
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
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-sand bg-parchment text-base font-semibold text-clay transition duration-150 ease-in-out hover:border-terracotta hover:bg-cream hover:text-terracotta disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-parchment disabled:hover:text-clay"
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
            class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-sand bg-parchment text-base font-semibold text-clay transition duration-150 ease-in-out hover:border-terracotta hover:bg-cream hover:text-terracotta disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-parchment disabled:hover:text-clay"
        >
            ↓
        </button>
    </div>

    <div class="col-span-2 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm md:col-span-1 md:justify-end">
        @if ($category->parent_id === null)
            <button
                type="button"
                wire:click="createSubcategory({{ $category->id }})"
                class="font-medium text-clay transition hover:text-brand-green"
            >
                Agregar subcategoría
            </button>
        @endif

        <button
            type="button"
            wire:click="edit({{ $category->id }})"
            class="font-medium text-brand-green transition hover:text-terracotta"
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
            class="font-medium text-terracotta transition hover:text-wood"
        >
            Eliminar
        </button>
    </div>
</div>