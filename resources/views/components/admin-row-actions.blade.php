@props([
    'rowId',
    'rowName',
    'type',
    'isActive' => true,
    'isFirst' => false,
    'isLast' => false,
])

{{-- Celdas «Estado», «Orden» y «Acciones» de una fila reordenable, compartidas por
     las cuatro pestañas de «Detalles de productos».

     La parte importante de este parcial es que sus botones hablan con `$wire` del
     componente que incluye la fila, no con uno global: cada pestaña es su propio
     componente Livewire y sus métodos (`toggleActive`, `moveUp`, `moveDown`, `edit`,
     `delete`) viven en él. Por eso no recibe el componente como atributo. Que sea un
     componente Blade y no algo de Alpine es justo lo que lo permite: `$wire` se
     resuelve en el ámbito donde Alpine evalúa la expresión, y ese ámbito es el del
     padre aunque el marcado venga de un `.blade.php` de otro sitio.

     `contents` hace que las tres celdas participen directamente en el grid de la
     fila. La alineación replica la de la cabecera: Estado y Orden centrados desde
     `md` (cuando aparece la cabecera), Acciones al final. --}}
<div class="contents">
    <div class="flex items-center justify-start gap-2 text-sm md:justify-center">
        <button
            type="button"
            role="switch"
            aria-checked="{{ $isActive ? 'true' : 'false' }}"
            wire:click="toggleActive({{ $rowId }})"
            class="inline-flex items-center gap-2"
        >
            <span @class([
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ease-in-out',
                'bg-verde' => $isActive,
                'bg-arena' => ! $isActive,
            ])>
                <span @class([
                    'inline-block h-5 w-5 rounded-full bg-crema shadow-lift transition-transform duration-200 ease-in-out',
                    'translate-x-5' => $isActive,
                    'translate-x-0.5' => ! $isActive,
                ])></span>
            </span>
            <span class="text-tinta">
                {{ $isActive ? 'Activo' : 'Inactivo' }}
            </span>
        </button>
    </div>

    <div class="flex items-center justify-start gap-2 md:justify-center">
        <button
            type="button"
            wire:click="moveUp({{ $rowId }})"
            x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
            title="Mover hacia arriba"
            aria-label="Mover {{ $rowName }} hacia arriba"
            @disabled($isFirst)
            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
        >
            ↑
        </button>

        <button
            type="button"
            wire:click="moveDown({{ $rowId }})"
            x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
            title="Mover hacia abajo"
            aria-label="Mover {{ $rowName }} hacia abajo"
            @disabled($isLast)
            class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
        >
            ↓
        </button>
    </div>

    <div class="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm sm:gap-x-4 md:col-span-1 md:justify-end">
        <button
            type="button"
            wire:click="edit({{ $rowId }})"
            class="whitespace-nowrap border-b border-laton pb-px font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
        >
            Editar
        </button>

        <button
            type="button"
            x-on:click="$dispatch('ask-confirm', {
                title: @js('Eliminar ' . $type),
                message: @js('Se eliminará «' . $rowName . '» de forma permanente.'),
                confirmLabel: 'Eliminar',
                destructive: true,
                onConfirm: () => $wire.delete({{ $rowId }}),
            })"
            class="whitespace-nowrap font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
        >
            Eliminar
        </button>
    </div>
</div>