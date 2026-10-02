@props(['current' => null])

@php($activeSection = $current ?? \App\Enums\StoreSection::Hombre->value)

{{-- Barra de secciones de la tienda: Hombre | Mujer | Niños.

     El estado vive en el componente Livewire que incluye la barra (`section`), así
     que la barra no guarda nada propio: cada botón le pide a ese componente que
     cambie de sección. Al ser `wire:click` sobre una propiedad con `#[Url]`, la
     sección activa queda también en la URL y sobrevive a recargar o compartir.

     El estilo replica el de las pestañas Datos/Variantes/Imágenes del modal de
     producto: la activa en verde con filete de latón, las demás en gris cálido.
     En móvil la barra deja de ser una fila para repartirse el ancho en tres
     columnas, que es donde tres nombres cortos caben sin recortarse. --}}
<div class="mt-6 border-b border-arena">
    <div role="tablist" aria-label="Sección de la tienda" class="grid grid-cols-3 gap-1 sm:flex sm:gap-6">
        @foreach (\App\Enums\StoreSection::cases() as $tab)
            <button
                type="button"
                role="tab"
                id="section-tab-{{ $tab->value }}"
                aria-controls="section-panel-{{ $tab->value }}"
                aria-selected="{{ $activeSection === $tab->value ? 'true' : 'false' }}"
                tabindex="{{ $activeSection === $tab->value ? '0' : '-1' }}"
                wire:key="section-tab-{{ $tab->value }}"
                wire:click="setSection('{{ $tab->value }}')"
                @class([
                    'border-b-2 px-1 pb-3 text-center text-sm font-medium transition-colors duration-150 ease-in-out sm:text-start',
                    'border-laton text-verde' => $activeSection === $tab->value,
                    'border-transparent text-gris-calido hover:text-tinta' => $activeSection !== $tab->value,
                ])
            >
                {{ $tab->label() }}
            </button>
        @endforeach
    </div>
</div>