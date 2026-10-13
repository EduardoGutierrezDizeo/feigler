<div>
    @php
        $isUpload = $step === \App\Livewire\Admin\BulkUpload::STEP_UPLOAD;
        $isPreview = $step === \App\Livewire\Admin\BulkUpload::STEP_PREVIEW;
        $isResult = $step === \App\Livewire\Admin\BulkUpload::STEP_RESULT;

        // El nombre de cada columna del archivo, como se escribe en pantalla. Las
        // claves son las del archivo (y `creacion`, la columna con la que el
        // importador marca un artículo que no se pudo crear), y solo se usan para
        // situar cada motivo en el encabezado de su fila de errores.
        $columnLabels = [
            'genero' => 'género',
            'categoria' => 'categoría',
            'nombre' => 'nombre',
            'marca' => 'marca',
            'precio' => 'precio',
            'materiales' => 'materiales',
            'talla' => 'talla',
            'color' => 'color',
            'stock' => 'stock',
            'creacion' => 'creación',
        ];

        $steps = [
            ['Subir archivo', $isUpload],
            ['Revisar', $isPreview],
            ['Confirmar', $isResult],
        ];
    @endphp

    <div class="flex items-center gap-3">
        <x-admin-module-icon name="bulk-upload" class="h-8 w-8 shrink-0 text-verde" />
        <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
            Carga masiva
        </h1>
    </div>

    <p class="mt-2 max-w-2xl text-sm text-gris-calido">
        Crea varios artículos de una vez desde un archivo CSV o Excel. Primero se lee y se muestra lo que
        se crearía y lo que no; nada se guarda hasta que lo confirmes.
    </p>

    {{-- Los tres pasos, con el actual en verde. Son un recorrido y no una navegación:
         desde aquí no se salta hacia atrás ni hacia adelante. --}}
    <ol class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-2">
        @foreach ($steps as $index => [$label, $current])
            <li class="flex items-center gap-3">
                <span class="flex items-center gap-2">
                    <span @class([
                        'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-verde text-crema' => $current,
                        'border border-arena bg-hueso text-gris-calido' => ! $current,
                    ])>{{ $index + 1 }}</span>
                    <span @class([
                        'text-sm',
                        'font-medium text-tinta' => $current,
                        'text-gris-calido' => ! $current,
                    ])>{{ $label }}</span>
                </span>

                @if (! $loop->last)
                    <span class="hidden h-px w-8 bg-arena sm:block" aria-hidden="true"></span>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($isUpload)
        {{-- Paso 1: elegir el archivo. --}}
        <div class="tarjeta mt-6 p-6 sm:p-8">
            <label for="bulk-upload-file" class="block">
                <x-input-label value="Archivo CSV o Excel" />

                <input
                    id="bulk-upload-file"
                    type="file"
                    accept=".csv,.xlsx"
                    wire:model="file"
                    wire:loading.attr="disabled"
                    aria-describedby="bulk-upload-help"
                    class="mt-2 block w-full cursor-pointer rounded-2xl border border-arena bg-hueso/60 px-4 py-3 text-sm text-tinta file:me-4 file:cursor-pointer file:rounded-full file:border-0 file:bg-verde-degradado file:px-4 file:py-2 file:text-sm file:font-medium file:text-crema hover:file:brightness-110 focus:outline-2 focus:outline-offset-2 focus:outline-verde disabled:cursor-not-allowed disabled:opacity-60"
                />
            </label>

            <x-input-error :messages="$errors->get('file')" class="mt-2" />

            <p id="bulk-upload-help" class="mt-3 text-xs text-gris-calido">
                Formatos admitidos: CSV y Excel (.xlsx). Hasta {{ $maxSizeLabel }} y {{ $maxRowsLabel }} filas por archivo.
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-secondary-button type="button" wire:click="downloadTemplate" wire:loading.attr="disabled" wire:target="downloadTemplate">
                    Descargar plantilla
                </x-secondary-button>

                <span wire:loading wire:target="downloadTemplate" class="text-sm text-gris-calido">Preparando la plantilla…</span>
            </div>

            <p class="mt-2 text-xs text-gris-calido">
                En la columna «materiales», separa cada material con | o / y escribe primero el nombre y después
                el porcentaje (ejemplo: Algodón 80% | Poliéster 20%); no uses comas para separarlos.
            </p>

            @if ($file)
                <p class="mt-3 text-sm text-tinta">
                    Archivo seleccionado:
                    <span class="font-medium">{{ $file->getClientOriginalName() }}</span>
                </p>
            @endif

            <div class="mt-6 flex items-center gap-3">
                <x-primary-button type="button" wire:click="review" wire:loading.attr="disabled" wire:target="review,file">
                    Revisar archivo
                </x-primary-button>

                <span wire:loading wire:target="review,file" class="text-sm text-gris-calido">Leyendo el archivo…</span>
            </div>
        </div>
    @elseif ($isPreview)
        {{-- Paso 2: lo que el archivo describe, antes de escribir nada. --}}
        @php
            $totals = $preview['totals'];
            $ready = $preview['ready'];
            $rejected = $preview['rejected'];
            $warnings = $preview['warnings'] ?? [];
        @endphp

        <div class="mt-6 rounded-2xl border border-laton/40 bg-hueso/60 px-5 py-4 text-sm text-tinta">
            <p>
                Los artículos se crearán <strong>inactivos</strong> y <strong>sin fotos</strong>. Cuando estén
                creados, súbeles las imágenes y actívalos desde
                <a href="{{ route('admin.products.index') }}" class="font-medium text-verde underline decoration-laton/60 underline-offset-2 hover:text-verde-hondo">Productos</a>.
            </p>

            <p class="mt-1 text-gris-calido">
                Archivo: <span class="font-medium text-tinta">{{ $originalName }}</span>
            </p>
        </div>

        {{-- Aviso, no un rechazo: el artículo se crea igual, pero mientras su
             categoría siga desactivada no será visible en la tienda, ni en su
             ficha ni en los listados ni en los relacionados. Se agrupa por
             categoría para no listar cada artículo. --}}
        @if (! empty($warnings))
            <div class="mt-6 rounded-2xl border border-laton/50 bg-laton/10 px-5 py-4 text-sm text-tinta">
                <p class="font-medium">Algunos artículos son de categorías desactivadas.</p>
                <p class="mt-1">
                    Se crearán igual, pero no serán visibles en la tienda mientras su categoría esté desactivada.
                    Reactívalas cuando quieras que se vean desde
                    <a href="{{ route('admin.product-details.index', ['tab' => 'categorias']) }}" class="font-medium text-verde underline decoration-laton/60 underline-offset-2 hover:text-verde-hondo">Categorías</a>:
                </p>

                <ul class="mt-2 space-y-1">
                    @foreach ($warnings as $warning)
                        <li>
                            <span class="font-medium">{{ $warning['category'] }}</span>
                            <span class="text-gris-calido">({{ $warning['section_label'] }})</span>
                            · {{ $warning['articles'] }} {{ $warning['articles'] === 1 ? 'artículo' : 'artículos' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-5">
                <p class="etiqueta">Artículos listos</p>
                <p class="mt-2 font-display text-3xl font-medium text-tinta">{{ $totals['ready_articles'] }}</p>
            </div>

            <div class="tarjeta p-5">
                <p class="etiqueta">Variantes</p>
                <p class="mt-2 font-display text-3xl font-medium text-tinta">{{ $totals['ready_variants'] }}</p>
            </div>

            <div class="tarjeta p-5">
                <p class="etiqueta">Artículos con errores</p>
                <p class="mt-2 font-display text-3xl font-medium {{ $totals['rejected_articles'] > 0 ? 'text-ladrillo' : 'text-tinta' }}">{{ $totals['rejected_articles'] }}</p>
                @if ($totals['rejected_rows'] > 0)
                    <p class="mt-1 text-xs text-gris-calido">{{ $totals['rejected_rows'] }} {{ $totals['rejected_rows'] === 1 ? 'fila' : 'filas' }} afectadas</p>
                @endif
            </div>
        </div>

        @if ($totals['ready_articles'] === 0)
            <div class="mt-6 rounded-2xl border border-ladrillo/40 bg-ladrillo/5 px-5 py-4 text-sm text-tinta">
                Ningún artículo del archivo se puede crear. Corrige los motivos de abajo y vuelve a subirlo.
            </div>
        @endif

        @if ($totals['rejected_articles'] > 0)
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-secondary-button type="button" wire:click="downloadErrorReport" wire:loading.attr="disabled" wire:target="downloadErrorReport">
                    Descargar reporte de errores (CSV)
                </x-secondary-button>

                <span wire:loading wire:target="downloadErrorReport" class="text-sm text-gris-calido">Preparando el reporte…</span>
            </div>
        @endif

        @if (! empty($rejected))
            <section class="mt-10">
                <h2 class="font-display text-2xl font-medium text-tinta">Artículos rechazados</h2>
                <p class="mt-1 text-sm text-gris-calido">
                    Una prenda con una sola fila equivocada no se crea entera. Estos motivos los leerías también al
                    corregir y volver a subir el archivo.
                </p>

                @if ($totals['rejected_articles'] > count($rejected))
                    <p class="mt-2 text-xs text-gris-calido">
                        Se muestran los primeros {{ count($rejected) }} de {{ $totals['rejected_articles'] }} artículos con errores.
                    </p>
                @endif

                <div class="tarjeta mt-4 divide-y divide-arena">
                    @foreach ($rejected as $article)
                        <div wire:key="rejected-{{ $loop->index }}" class="px-6 py-5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-medium text-tinta">{{ $article['name'] }}</p>
                                <p class="text-xs text-gris-calido">
                                    {{ count($article['rows']) === 1 ? 'Fila' : 'Filas' }} {{ implode(', ', $article['rows']) }}
                                </p>
                            </div>

                            <ul class="mt-3 space-y-1.5">
                                @foreach ($article['errors'] as $error)
                                    <li class="flex gap-2 text-sm text-tinta">
                                        <span class="shrink-0 text-gris-calido">Fila {{ $error['row'] }}</span>
                                        <span>
                                            <span class="text-gris-calido">{{ $columnLabels[$error['column']] ?? $error['column'] }}:</span>
                                            {{ $error['message'] }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (! empty($ready))
            <section class="mt-10">
                <h2 class="font-display text-2xl font-medium text-tinta">Artículos listos para crear</h2>

                @if ($totals['ready_articles'] > count($ready))
                    <p class="mt-1 text-xs text-gris-calido">
                        Se muestran los primeros {{ count($ready) }} de {{ $totals['ready_articles'] }} artículos.
                    </p>
                @endif

                <div class="tarjeta mt-4 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full text-sm">
                            <thead>
                                <tr class="etiqueta border-b border-arena">
                                    <th scope="col" class="px-6 py-4 text-start">Artículo</th>
                                    <th scope="col" class="px-6 py-4 text-start">Categoría</th>
                                    <th scope="col" class="px-6 py-4 text-end">Precio</th>
                                    <th scope="col" class="px-6 py-4 text-end">Variantes</th>
                                    <th scope="col" class="px-6 py-4 text-end">Stock</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arena">
                                @foreach ($ready as $article)
                                    <tr wire:key="ready-{{ $loop->index }}" class="transition-colors duration-150 ease-in-out hover:bg-hueso/50">
                                        <td class="px-6 py-4">
                                            <p class="font-medium text-tinta">{{ $article['name'] }}</p>
                                            <p class="mt-0.5 text-xs text-gris-calido">{{ $article['section_label'] }} · {{ $article['brand'] }}</p>
                                        </td>
                                        <td class="px-6 py-4 text-gris-calido">{{ $article['category'] }}</td>
                                        <td class="px-6 py-4 text-end text-tinta">${{ number_format((float) $article['price'], 0, ',', '.') }}</td>
                                        <td class="px-6 py-4 text-end text-tinta">{{ count($article['variants']) }}</td>
                                        <td class="px-6 py-4 text-end text-tinta">{{ array_sum(array_column($article['variants'], 'stock')) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        <div class="mt-10 flex flex-wrap items-center gap-3">
            <x-secondary-button type="button" wire:click="cancel" wire:loading.attr="disabled" wire:target="confirm">
                Cancelar
            </x-secondary-button>

            @if ($canConfirm)
                <x-primary-button type="button" wire:click="confirm" wire:target="confirm" wire:loading.attr="disabled">
                    Confirmar carga
                </x-primary-button>
            @else
                <x-primary-button type="button" disabled>
                    Confirmar carga
                </x-primary-button>
            @endif

            <span wire:loading wire:target="confirm" class="text-sm text-gris-calido">Creando los artículos…</span>
        </div>
    @else
        {{-- Paso 3: lo que quedó escrito. --}}
        @php
            $totals = $result['totals'];
            $created = $result['created'];
            $rejected = $result['rejected'];
        @endphp

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="tarjeta p-5">
                <p class="etiqueta">Artículos creados</p>
                <p class="mt-2 font-display text-3xl font-medium text-tinta">{{ $totals['created_articles'] }}</p>
            </div>

            <div class="tarjeta p-5">
                <p class="etiqueta">Variantes creadas</p>
                <p class="mt-2 font-display text-3xl font-medium text-tinta">{{ $totals['created_variants'] }}</p>
            </div>

            <div class="tarjeta p-5">
                <p class="etiqueta">Artículos no creados</p>
                <p class="mt-2 font-display text-3xl font-medium {{ $totals['rejected_articles'] > 0 ? 'text-ladrillo' : 'text-tinta' }}">{{ $totals['rejected_articles'] }}</p>
            </div>
        </div>

        @if ($totals['created_articles'] > 0)
            <div class="mt-6 rounded-2xl border border-verde/30 bg-verde/5 px-5 py-4 text-sm text-tinta">
                Los artículos quedaron <strong>inactivos</strong> y <strong>sin fotos</strong>. Súbeles las imágenes y
                actívalos desde
                <a href="{{ route('admin.products.index') }}" class="font-medium text-verde underline decoration-laton/60 underline-offset-2 hover:text-verde-hondo">Productos</a>
                cuando estén listos para el escaparate.
            </div>
        @endif

        @if ($reportPath !== null)
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-secondary-button type="button" wire:click="downloadErrorReport" wire:loading.attr="disabled" wire:target="downloadErrorReport">
                    Descargar reporte de errores (CSV)
                </x-secondary-button>

                <span wire:loading wire:target="downloadErrorReport" class="text-sm text-gris-calido">Preparando el reporte…</span>
            </div>
        @endif

        @if (! empty($created))
            <section class="mt-10">
                <h2 class="font-display text-2xl font-medium text-tinta">Artículos creados</h2>

                @if ($totals['created_articles'] > count($created))
                    <p class="mt-1 text-xs text-gris-calido">
                        Se muestran los primeros {{ count($created) }} de {{ $totals['created_articles'] }} artículos creados.
                    </p>
                @endif

                <div class="tarjeta mt-4 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full text-sm">
                            <thead>
                                <tr class="etiqueta border-b border-arena">
                                    <th scope="col" class="px-6 py-4 text-start">Artículo</th>
                                    <th scope="col" class="px-6 py-4 text-start">Referencia</th>
                                    <th scope="col" class="px-6 py-4 text-start">Categoría</th>
                                    <th scope="col" class="px-6 py-4 text-end">Variantes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arena">
                                @foreach ($created as $article)
                                    <tr wire:key="created-{{ $loop->index }}" class="transition-colors duration-150 ease-in-out hover:bg-hueso/50">
                                        <td class="px-6 py-4 font-medium text-tinta">{{ $article['name'] }}</td>
                                        <td class="px-6 py-4 text-gris-calido">{{ $article['reference'] }}</td>
                                        <td class="px-6 py-4 text-gris-calido">{{ $article['category'] }}</td>
                                        <td class="px-6 py-4 text-end text-tinta">{{ $article['variants'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        @if (! empty($rejected))
            <section class="mt-10">
                <h2 class="font-display text-2xl font-medium text-tinta">Artículos no creados</h2>
                <p class="mt-1 text-sm text-gris-calido">
                    El artículo se comprueba otra vez al confirmar. Estos son los que en ese momento ya no se podían
                    crear.
                </p>

                @if ($totals['rejected_articles'] > count($rejected))
                    <p class="mt-2 text-xs text-gris-calido">
                        Se muestran los primeros {{ count($rejected) }} de {{ $totals['rejected_articles'] }} artículos no creados.
                    </p>
                @endif

                <div class="tarjeta mt-4 divide-y divide-arena">
                    @foreach ($rejected as $article)
                        <div wire:key="result-rejected-{{ $loop->index }}" class="px-6 py-5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-medium text-tinta">{{ $article['name'] }}</p>
                                <p class="text-xs text-gris-calido">
                                    {{ count($article['rows']) === 1 ? 'Fila' : 'Filas' }} {{ implode(', ', $article['rows']) }}
                                </p>
                            </div>

                            <ul class="mt-3 space-y-1.5">
                                @foreach ($article['errors'] as $error)
                                    <li class="flex gap-2 text-sm text-tinta">
                                        <span class="shrink-0 text-gris-calido">Fila {{ $error['row'] }}</span>
                                        <span>
                                            <span class="text-gris-calido">{{ $columnLabels[$error['column']] ?? $error['column'] }}:</span>
                                            {{ $error['message'] }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="mt-10">
            <x-primary-button type="button" wire:click="cancel">
                Cargar otro archivo
            </x-primary-button>
        </div>
    @endif
</div>
