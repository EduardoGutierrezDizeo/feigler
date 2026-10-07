<div>
{{-- Página contenedora. Solo es dueña del título y de cuál de las pestañas está
     montada; el trabajo lo hace el hijo de la pestaña activa.

     La barra de pestañas se dibuja cuando el enum declaró más de una: con una
     sola el selector sobraría. Cada hijo se monta con una `wire:key` propia, lo
     que hace que Livewire entre de lleno con un componente nuevo y limpio al
     cambiar de tab: un formulario abierto en otra pestaña no sigue esperando
     cuando se vuelve. --}}
    <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
        Vista principal
    </h1>

    <p class="mt-2 text-sm text-gris-calido">
        {{ $activeTab->hint() }}
    </p>

    @if (count(\App\Enums\HomePageTab::cases()) > 1)
        <div class="mt-6 border-b border-arena">
            <div role="tablist" aria-label="Partes de la portada" class="flex gap-6">
                @foreach (\App\Enums\HomePageTab::cases() as $tab)
                    <button
                        type="button"
                        role="tab"
                        id="home-tab-{{ $tab->value }}"
                        aria-controls="home-panel-{{ $tab->value }}"
                        aria-selected="{{ $activeTab->value === $tab->value ? 'true' : 'false' }}"
                        tabindex="{{ $activeTab->value === $tab->value ? '0' : '-1' }}"
                        wire:key="home-tab-{{ $tab->value }}"
                        wire:click="setTab('{{ $tab->value }}')"
                        @class([
                            'border-b-2 px-1 pb-3 text-sm font-medium transition-colors duration-150 ease-in-out sm:text-start',
                            'border-laton text-verde' => $activeTab->value === $tab->value,
                            'border-transparent text-gris-calido hover:text-tinta' => $activeTab->value !== $tab->value,
                        ])
                    >
                        {{ $tab->label() }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <div
        class="mt-8"
        id="home-panel-{{ $activeTab->value }}"
        role="tabpanel"
        @if (count(\App\Enums\HomePageTab::cases()) > 1) aria-labelledby="home-tab-{{ $activeTab->value }}" @endif
    >
        @switch($activeTab)
            @case(\App\Enums\HomePageTab::Categorias)
                <livewire:admin.home-page.categories wire:key="home-panel-categorias" />
                @break

            @case(\App\Enums\HomePageTab::MasNuevo)
                <livewire:admin.home-page.featured wire:key="home-panel-mas-nuevo" />
                @break
        @endswitch
    </div>
</div>