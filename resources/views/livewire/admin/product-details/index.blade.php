<div>
    {{-- Página contenedora. Solo es dueña del título, de la barra de pestañas y de cuál
         de las cuatro está montada; el trabajo lo hace el hijo de la pestaña activa.

         Cada hijo se monta con una `wire:key` propia y distinta de la de los demás. Es
         lo que hace que Livewire empareje el DOM con el hijo correcto y no con otro: al
         cambiar de «Tallas» a «Colores» el componente de colores entra nuevo y limpio en
         lugar de ser el de tallas morphizado, así que un formulario abierto en la
         pestaña anterior no aparece con lo que se escribió en ella.

         Solo se monta la pestaña activa: las otras tres no llegan al DOM ni a las
         peticiones, y sus consultas no se ejecutan. --}}
    <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
        Detalles de productos
    </h1>

    <p class="mt-2 text-sm text-gris-calido">
        {{ $activeTab->hint() }}
    </p>

    <x-admin-details-tabs :current="$activeTab->value" />

    <div
        class="mt-8"
        id="details-panel-{{ $activeTab->value }}"
        role="tabpanel"
        aria-labelledby="details-tab-{{ $activeTab->value }}"
    >
        @if ($activeTab === \App\Enums\ProductDetailsTab::Categorias)
            <livewire:admin.categories.index wire:key="details-panel-categorias" />
        @elseif ($activeTab === \App\Enums\ProductDetailsTab::Tallas)
            <livewire:admin.product-details.sizes wire:key="details-panel-tallas" />
        @elseif ($activeTab === \App\Enums\ProductDetailsTab::Colores)
            <livewire:admin.product-details.colors wire:key="details-panel-colores" />
        @else
            <livewire:admin.product-details.materials wire:key="details-panel-materiales" />
        @endif
    </div>
</div>