<div>
    {{-- Cabecera de la pestaña: quién es dueña del bloque y cuál es su límite. --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-display text-3xl font-medium text-tinta sm:text-4xl">
                Lo más nuevo
            </h2>

            <p class="mt-2 text-sm text-gris-calido">
                Elige hasta {{ \App\Models\HomeFeaturedProduct::MAX }} productos para la sección
                «Novedades» del inicio, en el orden en que se muestran.
            </p>
        </div>
    </div>

    {{-- Por qué el inicio muestra lo que muestra, hoy. La respuesta sale de la misma
         clase que la raíz, así que la pantalla y la portada no se contradicen nunca. --}}
    <div class="mt-6 rounded-xl border border-arena bg-hueso/50 p-4 sm:p-5">
        @if ($state === \App\Enums\HomeNewProductsState::Manual)
            <p class="text-sm font-medium text-tinta">
                {{ trim('Selección manual ('.$novelties->count().' '.($novelties->count() === 1 ? 'producto' : 'productos').')') }}
            </p>
            <p class="mt-1 text-sm text-gris-calido">
                Son los que elegiste y siguen siendo visibles, en tu orden: el inicio no agrega nada solo.
            </p>
        @elseif ($state === \App\Enums\HomeNewProductsState::SinElegidos)
            <p class="text-sm font-medium text-tinta">
                Automática: los {{ \App\Models\HomeFeaturedProduct::MAX }} productos más recientes
            </p>
            <p class="mt-1 text-sm text-gris-calido">
                No elegiste ninguno a mano, así que el inicio muestra las últimas novedades visibles.
            </p>
        @else
            <p class="text-sm font-medium text-tinta">
                Automática: ninguno de los elegidos está visible
            </p>
            <p class="mt-1 text-sm text-gris-calido">
                Elegiste a mano, pero hoy ninguno de esos productos se muestra en la tienda: el inicio vuelve a los más recientes.
            </p>
        @endif
    </div>

    {{-- La lista de lo elegido a mano, en orden. Una elección que dejó de ser visible
         sigue en su sitio (se puede quitar o mover), marcada con por qué no se ve. --}}
    <section aria-labelledby="novedades-elegidas-title" class="mt-8">
        <h3 id="novedades-elegidas-title" class="font-display text-2xl font-medium text-tinta">
            Tus elegidos
        </h3>

        <div class="tarjeta mt-4 overflow-hidden">
            <div class="divide-y divide-arena" data-flip-scope>
                @forelse ($rows as $row)
                    @php
                        $product = $products[$row->product_id] ?? null;
                        $isVisible = in_array($row->product_id, $visibleIds, true);
                        $portada = $product?->cover_image;
                    @endphp

                    <div
                        wire:key="featured-{{ $row->id }}"
                        data-flip-row
                        class="flex flex-col gap-4 px-5 py-4 transition-colors duration-150 ease-in-out hover:bg-hueso/50 sm:flex-row sm:items-center"
                    >
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            @if ($portada)
                                <img
                                    src="{{ $portada->thumbnailUrl() }}"
                                    alt="{{ $product->name }}"
                                    loading="lazy"
                                    class="h-16 w-24 shrink-0 rounded-lg border border-arena object-cover"
                                />
                            @else
                                <span
                                    class="inline-flex h-16 w-24 shrink-0 items-center justify-center rounded-lg border border-arena bg-linear-to-br from-hueso to-arena text-gris-calido"
                                    aria-hidden="true"
                                >
                                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" />
                                    </svg>
                                </span>
                            @endif

                            <div class="min-w-0">
                                <p class="truncate font-medium text-tinta">{{ $product->name }}</p>
                                <p class="truncate text-xs text-gris-calido">{{ $product->reference }}</p>
                                <p class="mt-1 truncate text-xs text-gris-calido">
                                    {{ $product->category->section->label() }} · {{ $product->category->name }}
                                </p>

                                @if (! $isVisible)
                                    <p class="mt-1 flex items-center gap-1.5 text-xs font-medium text-ladrillo">
                                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                        </svg>
                                        No es visible: no aparece en el inicio
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Subir, bajar y quitar; los extremos deshabilitan su botón. --}}
                        <div class="flex shrink-0 items-center gap-2 sm:pl-4">
                            <button
                                type="button"
                                wire:click="moveUp({{ $row->id }})"
                                x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
                                title="Mover hacia arriba"
                                aria-label="Mover {{ $product->name }} hacia arriba"
                                @disabled($loop->first)
                                class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
                            >
                                ↑
                            </button>

                            <button
                                type="button"
                                wire:click="moveDown({{ $row->id }})"
                                x-on:click="moveReorder.capture($el.closest('[data-flip-scope]'))"
                                title="Mover hacia abajo"
                                aria-label="Mover {{ $product->name }} hacia abajo"
                                @disabled($loop->last)
                                class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base font-semibold text-gris-calido transition-colors duration-150 ease-in-out hover:bg-hueso hover:text-verde disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gris-calido"
                            >
                                ↓
                            </button>

                            <button
                                type="button"
                                wire:click="remove({{ $row->id }})"
                                class="whitespace-nowrap font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-12 text-center text-sm text-gris-calido">
                        <div class="mx-auto mb-4 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>
                        <p>Aún no elegiste productos. Usa el buscador de abajo para destacar las primeras novedades.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    {{-- El bloque real del inicio, tal como lo pinta la raíz. --}}
    <section aria-labelledby="novedades-hoy-title" class="mt-8">
        <h3 id="novedades-hoy-title" class="font-display text-2xl font-medium text-tinta">
            Así se ve hoy en el inicio
        </h3>

        <div class="tarjeta mt-4 overflow-hidden">
            <div class="divide-y divide-arena">
                @forelse ($novelties as $product)
                    <div wire:key="hoy-{{ $product->id }}" class="flex items-center gap-3 px-4 py-3">
                        @php($portada = $product->cover_image)

                        @if ($portada)
                            <img
                                src="{{ $portada->thumbnailUrl() }}"
                                alt=""
                                loading="lazy"
                                class="h-12 w-20 shrink-0 rounded-lg border border-arena object-cover"
                            />
                        @else
                            <span
                                class="inline-flex h-12 w-20 shrink-0 items-center justify-center rounded-lg border border-arena bg-hueso text-gris-calido"
                                aria-hidden="true"
                            >
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" />
                                </svg>
                            </span>
                        @endif

                        <p class="min-w-0 truncate text-sm font-medium text-tinta">{{ $product->name }}</p>
                    </div>
                @empty
                    <p class="px-6 py-8 text-center text-sm text-gris-calido">El inicio no tiene novedades.</p>
                @endforelse
            </div>
        </div>
    </section>

    {{-- Agregar: buscador por nombre o referencia, solo mientras la lista no está llena. --}}
    <section aria-labelledby="novedades-agregar-title" class="mt-8">
        <h3 id="novedades-agregar-title" class="font-display text-2xl font-medium text-tinta">
            Agregar productos
        </h3>

        @if ($rows->count() >= \App\Models\HomeFeaturedProduct::MAX)
            <div class="mt-4 rounded-xl border border-arena bg-hueso/50 p-4 text-sm text-gris-calido">
                Llegaste al máximo de {{ \App\Models\HomeFeaturedProduct::MAX }}. Quita uno para agregar otro.
            </div>
        @else
            <div class="mt-4">
                <label for="novedades-buscador" class="etiqueta mb-2 block text-xs font-medium uppercase tracking-wider text-gris-calido">
                    Buscar producto
                </label>

                <x-text-input
                    id="novedades-buscador"
                    variant="pill"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    class="block w-full max-w-sm"
                    placeholder="Buscar por nombre o referencia…"
                />

                @if ($searching)
                    <div class="mt-4">
                        @if ($candidates->isEmpty())
                            <p class="text-sm text-gris-calido">No encontramos productos visibles con ese texto.</p>
                        @else
                            <p class="mb-3 border-b border-arena pb-2 text-xs text-gris-calido">
                                {{ $candidates->count() }} {{ $candidates->count() === 1 ? 'coincidencia' : 'coincidencias' }}
                            </p>

                            <div class="tarjeta overflow-hidden">
                                <div class="divide-y divide-arena">
                                    @foreach ($candidates as $product)
                                        @php($portada = $product->cover_image)

                                        <div wire:key="candidate-{{ $product->id }}" class="flex items-center gap-3 px-4 py-3">
                                            @if ($portada)
                                                <img
                                                    src="{{ $portada->thumbnailUrl() }}"
                                                    alt=""
                                                    loading="lazy"
                                                    class="h-12 w-20 shrink-0 rounded-lg border border-arena object-cover"
                                                />
                                            @else
                                                <span
                                                    class="inline-flex h-12 w-20 shrink-0 items-center justify-center rounded-lg border border-arena bg-hueso text-gris-calido"
                                                    aria-hidden="true"
                                                >
                                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" />
                                                    </svg>
                                                </span>
                                            @endif

                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-tinta">{{ $product->name }}</p>
                                                <p class="truncate text-xs text-gris-calido">{{ $product->reference }}</p>
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="add({{ $product->id }})"
                                                class="shrink-0 whitespace-nowrap border-b border-laton pb-px font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                                            >
                                                Agregar
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </section>
</div>