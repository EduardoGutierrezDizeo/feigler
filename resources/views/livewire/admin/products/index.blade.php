<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
                Productos
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Gestiona el catálogo, sus datos generales y su estado en el escaparate.
            </p>
        </div>

        {{-- El alta y la edición siguen siendo un botón que abre el modal, no un formulario en la vista. --}}
        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nuevo producto
        </x-primary-button>
    </div>

    <x-admin-section-tabs :current="$activeSection->value" />

    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar por nombre o referencia…"
            aria-label="Buscar producto"
        />

        <x-select-input
            variant="pill"
            wire:model.live="categoryFilter"
            class="block w-auto"
            aria-label="Filtrar por categoría"
        >
            <option value="">Todas las categorías</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected($categoryFilter === (string) $category->id)>
                    {{ $category->name }}
                </option>
            @endforeach
        </x-select-input>

        <x-select-input
            variant="pill"
            wire:model.live="statusFilter"
            class="block w-auto"
            aria-label="Filtrar por estado"
        >
            <option value="">Todos los estados</option>
            <option value="active" @selected($statusFilter === 'active')>Activo</option>
            <option value="inactive" @selected($statusFilter === 'inactive')>Inactivo</option>
            <option value="out_of_stock" @selected($statusFilter === 'out_of_stock')>Agotado</option>
            <option value="no_variants" @selected($statusFilter === 'no_variants')>Sin variantes</option>
        </x-select-input>

        @if ($search !== '' || $categoryFilter !== '' || $statusFilter !== '')
            <button
                type="button"
                wire:click="clearFilters"
                class="shrink-0 text-sm font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
            >
                Limpiar filtros
            </button>
        @endif
    </div>

    <div class="tarjeta mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            {{-- `divide-y` va en el `tbody` (en la tabla no llega a las filas). `w-full min-w-full`
                 reparte el sobrante entre columnas: cabecera y celdas comparten ancho. --}}
            <table class="w-full min-w-full text-sm">
                <thead>
                    <tr class="etiqueta border-b border-arena">
                        <th scope="col" class="px-6 py-4 text-start">Producto</th>
                        <th scope="col" class="px-6 py-4 text-end">Precio base</th>
                        <th scope="col" class="px-6 py-4 text-end">Stock</th>
                        <th scope="col" class="px-6 py-4 text-start">Estado</th>
                        <th scope="col" class="px-6 py-4 text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-arena">
                    @php
                        $statusBadges = [
                            'active' => ['Activo', 'bg-verde/10 text-verde'],
                            'inactive' => ['Inactivo', 'bg-hueso text-gris-calido'],
                            'out_of_stock' => ['Agotado', 'bg-ladrillo/10 text-ladrillo'],
                            'no_variants' => ['Sin variantes', 'bg-hueso text-gris-calido border border-arena'],
                        ];
                    @endphp

                    @forelse ($products as $product)
                        @php
                            [$statusLabel, $statusClasses] = $statusBadges[$product->display_status];
                            $price = '$'.number_format((float) $product->base_price, 0, ',', '.');
                        @endphp

                        <tr wire:key="product-{{ $product->id }}" class="transition-colors duration-150 ease-in-out hover:bg-hueso/50">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    {{-- Miniatura de sustitución: las imágenes llegan con el módulo de
                                         imágenes por color, así que por ahora solo marca el hueco. --}}
                                    <span
                                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-linear-to-br from-hueso to-arena"
                                        aria-hidden="true"
                                    ></span>
                                    <span class="min-w-0">
                                        <span class="block text-xs text-gris-calido">{{ $product->reference }}</span>
                                        <span class="block font-medium text-tinta">{{ $product->name }}</span>
                                        <span class="block text-xs text-gris-calido">{{ $product->category->name }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-end text-tinta">{{ $price }}</td>
                            <td class="px-6 py-4 text-end text-tinta">{{ $product->stock_total }}</td>

                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>

                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-4">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $product->id }})"
                                        class="border-b border-laton pb-px text-sm font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                                    >
                                        Editar
                                    </button>

                                    {{-- Solo alterna `active` e `inactive`: «Agotado» y «Sin variantes» los
                                         calcula el modelo a partir de las variantes, no se escriben. --}}
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked="{{ $product->status === 'inactive' ? 'false' : 'true' }}"
                                        aria-label="Cambiar el estado de {{ $product->name }}"
                                        wire:click="toggleActive({{ $product->id }})"
                                        class="inline-flex items-center gap-2 text-sm"
                                    >
                                        <span @class([
                                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ease-in-out',
                                            'bg-arena' => $product->status === 'inactive',
                                            'bg-verde' => $product->status !== 'inactive',
                                        ])>
                                            <span @class([
                                                'inline-block h-5 w-5 rounded-full bg-crema shadow-lift transition-transform duration-200 ease-in-out',
                                                'translate-x-0.5' => $product->status === 'inactive',
                                                'translate-x-5' => $product->status !== 'inactive',
                                            ])></span>
                                        </span>
                                        <span class="text-tinta">
                                            {{ $product->status === 'inactive' ? 'Inactivo' : 'Activo' }}
                                        </span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-gris-calido">
                                <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                                @if ($search !== '' || $categoryFilter !== '' || $statusFilter !== '')
                                    <p>No se encontraron productos que coincidan con los filtros.</p>

                                    <button
                                        type="button"
                                        wire:click="clearFilters"
                                        class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                                    >
                                        Limpiar filtros
                                    </button>
                                @else
                                    Aún no hay productos en {{ $activeSection->label() }}. Crea el primero con el botón «Nuevo producto».
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Paginación a mano: `->paginate()` trae la vista del framework, que usa colores
         fuera de la paleta. Cada tramo suma PAGE_SIZE productos. --}}
    @if ($remaining > 0)
        <div class="mt-6 flex justify-center">
            <x-secondary-button type="button" wire:click="loadMore" wire:loading.attr="disabled">
                Mostrar más — quedan {{ $remaining }}
            </x-secondary-button>
        </div>
    @endif

    <x-admin-modal
        :title="$editingId !== null ? 'Editar producto' : 'Nuevo producto'"
        title-id="product-form-title"
        max-width="sm:max-w-2xl"
    >
        {{-- La referencia es de solo lectura: se asignó al crear el producto y ya forma
             parte de los SKU construidos a partir de ella. La sección tampoco se
             elige: un producto no cambia de sección. --}}
        @if ($editingId !== null)
            <p class="text-xs text-gris-calido">
                Referencia <span class="font-medium">{{ $editingReference }}</span>
            </p>
        @endif

        <p class="text-xs text-gris-calido">
            Sección: <span class="font-medium">{{ $formSection->label() }}</span>
        </p>

        <div x-data="{ tab: 'datos' }">
            <div role="tablist" aria-label="Secciones del producto" class="flex gap-6 border-b border-arena">
                <button
                    type="button"
                    role="tab"
                    id="product-tab-datos"
                    aria-controls="product-panel-datos"
                    x-on:click="tab = 'datos'"
                    x-bind:aria-selected="(tab === 'datos').toString()"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors duration-150 ease-in-out"
                    x-bind:class="tab === 'datos' ? 'border-laton text-verde' : 'border-transparent text-gris-calido'"
                >
                    Datos
                </button>

                <button
                    type="button"
                    role="tab"
                    disabled
                    aria-disabled="true"
                    title="Próximamente"
                    class="cursor-not-allowed border-b-2 border-transparent px-1 pb-3 text-sm font-medium text-gris-calido opacity-50"
                >
                    Variantes
                </button>

                <button
                    type="button"
                    role="tab"
                    disabled
                    aria-disabled="true"
                    title="Próximamente"
                    class="cursor-not-allowed border-b-2 border-transparent px-1 pb-3 text-sm font-medium text-gris-calido opacity-50"
                >
                    Imágenes
                </button>
            </div>

            <div
                role="tabpanel"
                id="product-panel-datos"
                aria-labelledby="product-tab-datos"
                x-show="tab === 'datos'"
                class="space-y-6 pt-6"
            >
                <div>
                    <x-input-label for="product-name" value="Nombre" />
                    <x-text-input
                        id="product-name"
                        wire:model="name"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej. Polo clásico piqué"
                        data-modal-autofocus
                    />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="product-category" value="Categoría" />
                    <x-select-input
                        id="product-category"
                        wire:model="categoryId"
                        class="mt-1 block w-full"
                    >
                        <option value="">— Selecciona una categoría —</option>
                        @foreach ($formCategories as $category)
                            <option
                                value="{{ $category->id }}"
                                @selected($categoryId === $category->id)
                            >
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="product-description" value="Descripción" />
                    <textarea
                        id="product-description"
                        wire:model="description"
                        rows="4"
                        placeholder="Detalles de la prenda, tejidos, cuidados…"
                        class="mt-1 block w-full resize-y rounded-none border-0 border-b border-arena bg-transparent px-0 py-2 text-sm text-tinta placeholder:text-gris-calido/70 shadow-none focus:border-verde focus:outline-none focus:ring-0"
                    ></textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-2" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="product-brand" value="Marca" />
                        <x-text-input
                            id="product-brand"
                            wire:model="brand"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Ej. Feigler"
                        />
                        <x-input-error :messages="$errors->get('brand')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="product-material" value="Material" />
                        <x-text-input
                            id="product-material"
                            wire:model="material"
                            type="text"
                            class="mt-1 block w-full"
                            placeholder="Ej. Algodón piqué"
                        />
                        <x-input-error :messages="$errors->get('material')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <x-input-label for="product-price" value="Precio base" />
                        <x-text-input
                            id="product-price"
                            wire:model="basePrice"
                            type="text"
                            inputmode="decimal"
                            class="mt-1 block w-full"
                            placeholder="89900"
                        />
                        <x-input-error :messages="$errors->get('basePrice')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="product-status" value="Estado" />
                        <x-select-input
                            id="product-status"
                            wire:model="status"
                            class="mt-1 block w-full"
                        >
                            <option value="active" @selected($status === 'active')>Activo</option>
                            <option value="inactive" @selected($status === 'inactive')>Inactivo</option>
                        </x-select-input>
                        <x-input-error :messages="$errors->get('status')" class="mt-2" />
                    </div>
                </div>

                @if ($editingId === null)
                    <p class="text-xs text-gris-calido">
                        Al crear, se asigna la referencia y las variantes se añaden después, en su propia pestaña.
                    </p>
                @endif
            </div>
        </div>

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear producto' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>