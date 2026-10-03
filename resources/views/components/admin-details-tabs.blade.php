@props(['current' => null])

@php($activeTab = $current ?? \App\Enums\ProductDetailsTab::Categorias->value)

{{-- Barra de pestañas de «Detalles de productos»: Categorías | Tallas | Colores | Materiales.

     El estado vive en el contenedor Livewire que incluye la barra (`tab`), así que la
     barra no guarda nada propio: cada botón le pide a ese componente que cambie de
     pestaña. Al ser `wire:click` sobre una propiedad con `#[Url]`, la pestaña activa
     queda también en la URL y sobrevive a recargar, compartir o usar el botón atrás.

     El estilo replica el de la barra de secciones (Hombre | Mujer | Niños) y el de las
     pestañas Datos/Variantes/Imágenes del modal de producto: la activa en verde con
     filete de latón, las demás en gris cálido. En móvil la barra se reparte en cuatro
     columnas, y por eso el nombre va en `text-xs`: cuatro nombres largos en una fila
     de móvil se recortan. --}}
<div class="mt-6 border-b border-arena">
    <div role="tablist" aria-label="Detalle del producto" class="grid grid-cols-4 gap-1 sm:flex sm:gap-6">
        @foreach (\App\Enums\ProductDetailsTab::cases() as $tab)
            <button
                type="button"
                role="tab"
                id="details-tab-{{ $tab->value }}"
                aria-controls="details-panel-{{ $tab->value }}"
                aria-selected="{{ $activeTab === $tab->value ? 'true' : 'false' }}"
                tabindex="{{ $activeTab === $tab->value ? '0' : '-1' }}"
                wire:key="details-tab-{{ $tab->value }}"
                wire:click="setTab('{{ $tab->value }}')"
                @class([
                    'border-b-2 px-1 pb-3 text-center text-xs font-medium transition-colors duration-150 ease-in-out sm:text-start sm:text-sm',
                    'border-laton text-verde' => $activeTab === $tab->value,
                    'border-transparent text-gris-calido hover:text-tinta' => $activeTab !== $tab->value,
                ])
            >
                {{ $tab->label() }}
            </button>
        @endforeach
    </div>
</div>