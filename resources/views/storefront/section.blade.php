{{--
    Vista de sección (Hombre / Mujer / Niños) y de las páginas de varias
    secciones (Novedades / Tienda): la misma para todas.
    Los filtros son un formulario GET: funciona sin JavaScript y se envía solo al cambiar un campo.
    Campos: seccion[], categoria[], talla[], color[], material[], precio_min, precio_max, stock, orden.
    Contrato de datos:
      $section:  { key, label, url }   (siempre; las páginas de varias secciones
                                        mandan aquí su identidad: clave de menú,
                                        título y su propia URL)
      $subtitle: string   (antetítulo sobre el título; por defecto «Sección»)
      $crumbs:   [{ label, url|null }]  (migas de pan; por defecto Inicio / la página)
      $emptyMessage: string|null  (título del estado vacío; por defecto el de siempre,
                                   y entonces se ven también la pista y el botón de limpiar)
      $emptyLink: { url, label }|null  (enlace propio del estado vacío, cuando lo hay;
                                   sustituye a la pista y al botón de limpiar)
      $searchQuery: string|null  (texto buscado; viaja oculto para no perderse al
                                   cambiar un filtro)
      $searchPrompt: bool  (cuando falta el texto buscado: no se pintan filtros)
      $total:    int (prendas que cumplen los filtros)
      $shown:    int (prendas visibles en esta página)
      $products: lista de tarjetas
      $nextUrl:  string|null (enlace de «Mostrar más»)
      $clearUrl: string (la página sin filtros)
      $sort:     { value, options: [{ value, label }] }
      $active:   [{ label, removeUrl }]
      $filters:  { sections: [{ value, label, count, checked }]|null  (null en una
                                    sección: ahí la familia no se pinta ni se lee),
                   categories: [{ value, label, count, checked }],
                   sizes:      [{ value, label, checked }],
                   colors:     [{ value, label, hex, checked }],
                   materials:  [{ value, label, count, checked }],
                   price:      { min, max, from|null, to|null, step },
                   stock:      { count, checked } }
--}}
@php
    $range = 'pointer-events-none absolute inset-0 h-5 w-full appearance-none bg-transparent '
        . '[&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:size-4 [&::-webkit-slider-thumb]:appearance-none '
        . '[&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-laton [&::-webkit-slider-thumb]:bg-crema '
        . '[&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:size-4 [&::-moz-range-thumb]:appearance-none '
        . '[&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-laton [&::-moz-range-thumb]:bg-crema [&::-moz-range-track]:bg-transparent';
    $check = 'size-4 rounded border-arena text-verde focus:ring-verde/30';
    $selectedColors = collect($filters['colors'])->where('checked', true)->pluck('label')->implode(', ');
    $price = $filters['price'];
    $subtitle = $subtitle ?? 'Sección';
    $crumbs = $crumbs ?? [
        ['label' => 'Inicio', 'url' => url('/')],
        ['label' => $section['label'], 'url' => null],
    ];
    $sectionsFamily = $filters['sections'] ?? null;
    $emptyMessage = $emptyMessage ?? null;
    $emptyLink = $emptyLink ?? null;
    $searchQuery = $searchQuery ?? null;
    $searchPrompt = $searchPrompt ?? false;
@endphp

<x-store.layout :title="$section['label'] . ' · Feigler'" :active="$section['key']" :cart-count="$cartCount ?? 0" :search-query="$searchQuery">
    <form method="GET" action="{{ $section['url'] }}" x-data="{ filtersOpen: false }"
          @change.debounce.400ms="$el.requestSubmit()" @keydown.escape.window="filtersOpen = false"
          x-effect="document.body.classList.toggle('overflow-hidden', filtersOpen)"
          class="mx-auto max-w-7xl px-4 pt-6 sm:px-8">

        @if ($searchQuery !== null)
            {{-- El texto buscado viaja con cada envío del formulario: cambiar un
                 filtro no debe perder la búsqueda. --}}
            <input type="hidden" name="q" value="{{ $searchQuery }}">
        @endif

        <nav aria-label="Ruta" class="text-xs text-gris-calido">
            @foreach ($crumbs as $crumb)
                @unless ($loop->first) / @endunless
                @if ($crumb['url'] !== null)
                    <a href="{{ $crumb['url'] }}" class="hover:text-verde">{{ $crumb['label'] }}</a>
                @else
                    <span class="text-tinta" aria-current="page">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs text-laton">{{ $subtitle }}</p>
                <h1 class="mt-1 font-display text-5xl leading-none sm:text-7xl">{{ $section['label'] }}</h1>
                <p class="mt-2 text-sm text-gris-calido">{{ $total }} {{ $total === 1 ? 'prenda' : 'prendas' }}</p>
            </div>
            @unless ($searchPrompt)
                <div class="flex items-center gap-3">
                    <button type="button" @click="filtersOpen = true" aria-controls="filtros"
                            class="rounded-full border border-laton px-5 py-2 text-sm text-verde lg:hidden">Filtros</button>
                    <x-select-input variant="pill" name="orden" aria-label="Ordenar productos">
                        @foreach ($sort['options'] as $option)
                            <option value="{{ $option['value'] }}" @selected($sort['value'] === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            @endunless
        </div>

        <div @class([
            'mt-8 lg:grid lg:gap-10',
            'lg:grid-cols-[16rem_minmax(0,1fr)]' => ! $searchPrompt,
        ])>

            @unless ($searchPrompt)
                <div x-show="filtersOpen" x-transition.opacity @click="filtersOpen = false" style="display: none"
                     class="fixed inset-0 z-40 bg-tinta/40 lg:hidden" aria-hidden="true"></div>

            {{-- Filtros: panel lateral en móvil, columna fija en escritorio --}}
            <aside id="filtros" aria-label="Filtros"
                   :class="filtersOpen ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-50 w-[88%] max-w-sm overflow-y-auto bg-crema p-6 shadow-2xl transition-transform duration-300 lg:static lg:z-auto lg:w-auto lg:max-w-none lg:translate-x-0 lg:overflow-visible lg:bg-transparent lg:p-0 lg:shadow-none">
                <div class="flex items-baseline justify-between border-b border-arena pb-3">
                    <h2 class="font-display text-3xl">Filtros</h2>
                    <div class="flex items-center gap-4">
                        <a href="{{ $clearUrl }}" class="text-sm text-verde underline decoration-laton underline-offset-4">Limpiar</a>
                        <button type="button" @click="filtersOpen = false" class="text-sm text-gris-calido lg:hidden">Cerrar</button>
                    </div>
                </div>

                {{-- Sección (solo en páginas de varias secciones) --}}
                @if ($sectionsFamily !== null)
                    <div x-data="{ open: true }" class="border-b border-arena py-5">
                        <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                            Sección <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                        </button>
                        <ul x-show="open" class="mt-4 space-y-2.5">
                            @foreach ($sectionsFamily as $item)
                                <li><label class="flex cursor-pointer items-center gap-3 text-sm">
                                    <input type="checkbox" name="seccion[]" value="{{ $item['value'] }}" @checked($item['checked']) class="{{ $check }}">
                                    <span class="flex-1">{{ $item['label'] }}</span><span class="text-xs text-gris-calido">{{ $item['count'] }}</span>
                                </label></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Categoría --}}
                <div x-data="{ open: true }" class="border-b border-arena py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Categoría <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <ul x-show="open" class="mt-4 space-y-2.5">
                        @foreach ($filters['categories'] as $item)
                            <li><label class="flex cursor-pointer items-center gap-3 text-sm">
                                <input type="checkbox" name="categoria[]" value="{{ $item['value'] }}" @checked($item['checked']) class="{{ $check }}">
                                <span class="flex-1">{{ $item['label'] }}</span><span class="text-xs text-gris-calido">{{ $item['count'] }}</span>
                            </label></li>
                        @endforeach
                    </ul>
                </div>

                {{-- Precio --}}
                <div x-data="{ open: true }" class="border-b border-arena py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Precio <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <div x-show="open" x-data="priceRange(@js($price))" class="mt-5 space-y-4">
                        <div class="relative h-5">
                            <div class="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-arena"></div>
                            <div class="absolute top-1/2 h-1 -translate-y-1/2 rounded-full bg-linear-to-r from-verde to-laton" :style="`left:${fromPct}%;right:${100 - toPct}%`"></div>
                            <input type="range" :min="min" :max="max" :step="step" x-model.number="from" @input="clampFrom()" aria-label="Precio mínimo" class="{{ $range }}">
                            <input type="range" :min="min" :max="max" :step="step" x-model.number="to" @input="clampTo()" aria-label="Precio máximo" class="{{ $range }}">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="relative block">
                                <span class="sr-only">Precio mínimo</span>
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gris-calido" aria-hidden="true">$</span>
                                <input type="number" inputmode="numeric" :name="from > min ? 'precio_min' : null" :min="min" :max="max" :step="step" x-model.number="from" @change="clampFrom()"
                                       class="w-full rounded-full border border-arena bg-crema py-2 pl-8 pr-3 text-sm focus:border-verde focus:ring-verde/20">
                            </label>
                            <label class="relative block">
                                <span class="sr-only">Precio máximo</span>
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gris-calido" aria-hidden="true">$</span>
                                <input type="number" inputmode="numeric" :name="to < max ? 'precio_max' : null" :min="min" :max="max" :step="step" x-model.number="to" @change="clampTo()"
                                       class="w-full rounded-full border border-arena bg-crema py-2 pl-8 pr-3 text-sm focus:border-verde focus:ring-verde/20">
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Talla --}}
                <div x-data="{ open: true }" class="border-b border-arena py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Talla <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <div x-show="open" class="mt-4 grid grid-cols-4 gap-2">
                        @foreach ($filters['sizes'] as $item)
                            <label class="cursor-pointer">
                                <input type="checkbox" name="talla[]" value="{{ $item['value'] }}" @checked($item['checked']) class="peer sr-only">
                                <span class="grid h-10 place-items-center rounded-xl border border-arena text-sm transition hover:border-verde peer-checked:border-verde-hondo peer-checked:bg-linear-to-b peer-checked:from-verde peer-checked:to-verde-hondo peer-checked:text-crema peer-focus-visible:ring-2 peer-focus-visible:ring-laton">{{ $item['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Color --}}
                <div x-data="{ open: true }" class="border-b border-arena py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Color <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <div x-show="open" class="mt-4">
                        <div class="flex flex-wrap gap-3">
                            @foreach ($filters['colors'] as $item)
                                <label class="cursor-pointer" title="{{ $item['label'] }}">
                                    <input type="checkbox" name="color[]" value="{{ $item['value'] }}" @checked($item['checked']) class="peer sr-only">
                                    <span class="block size-8 rounded-full border border-black/10 transition peer-checked:ring-2 peer-checked:ring-verde peer-checked:ring-offset-2 peer-checked:ring-offset-crema peer-focus-visible:ring-2 peer-focus-visible:ring-laton" style="background-color: {{ $item['hex'] }}"></span>
                                    <span class="sr-only">{{ $item['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if ($selectedColors !== '')
                            <p class="mt-3 text-xs text-gris-calido">Seleccionado: {{ $selectedColors }}</p>
                        @endif
                    </div>
                </div>

                {{-- Material --}}
                <div x-data="{ open: true }" class="border-b border-arena py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Material <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <ul x-show="open" class="mt-4 space-y-2.5">
                        @foreach ($filters['materials'] as $item)
                            <li><label class="flex cursor-pointer items-center gap-3 text-sm">
                                <input type="checkbox" name="material[]" value="{{ $item['value'] }}" @checked($item['checked']) class="{{ $check }}">
                                <span class="flex-1">{{ $item['label'] }}</span><span class="text-xs text-gris-calido">{{ $item['count'] }}</span>
                            </label></li>
                        @endforeach
                    </ul>
                </div>

                {{-- Disponibilidad --}}
                <div x-data="{ open: true }" class="py-5">
                    <button type="button" @click="open = !open" :aria-expanded="open" class="flex w-full items-center justify-between text-left font-display text-2xl">
                        Disponibilidad <span class="text-laton" x-text="open ? '−' : '+'" aria-hidden="true"></span>
                    </button>
                    <div x-show="open" class="mt-4">
                        <label class="flex cursor-pointer items-center gap-3 text-sm">
                            <input type="checkbox" name="stock" value="1" @checked($filters['stock']['checked']) class="{{ $check }}">
                            <span class="flex-1">Solo en stock</span><span class="text-xs text-gris-calido">{{ $filters['stock']['count'] }}</span>
                        </label>
                    </div>
                </div>

                <button type="submit" class="mt-2 w-full rounded-full bg-linear-to-b from-verde to-verde-hondo py-3 text-sm text-crema lg:hidden">Ver {{ $total }} {{ $total === 1 ? 'prenda' : 'prendas' }}</button>
            </aside>
            @endunless

            {{-- Resultados --}}
            <div class="min-w-0" x-data="loadMore({ url: @js($nextUrl), shown: @js($shown), total: @js($total) })">
                @if (count($active))
                    <div class="mb-6 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-gris-calido">Filtros activos</span>
                        @foreach ($active as $chip)
                            <a href="{{ $chip['removeUrl'] }}" aria-label="Quitar filtro {{ $chip['label'] }}"
                               class="rounded-full border border-laton px-3 py-1 text-xs text-verde transition hover:bg-verde hover:text-crema">{{ $chip['label'] }} ×</a>
                        @endforeach
                    </div>
                @endif

                @if (count($products))
                    <div x-ref="grid" class="grid grid-cols-2 gap-4 sm:gap-6 xl:grid-cols-3">
                        @foreach ($products as $product)
                            <x-store.product-card :product="$product" data-product-id="{{ $product['id'] }}" />
                        @endforeach
                    </div>

                    <div class="mt-12 text-center">
                        <p class="text-sm text-gris-calido">Mostrando <span x-text="shown">{{ $shown }}</span> de <span x-text="total">{{ $total }}</span> <span x-text="total === 1 ? 'prenda' : 'prendas'">{{ $total === 1 ? 'prenda' : 'prendas' }}</span></p>
                        @if ($nextUrl)
                            <a href="{{ $nextUrl }}" :href="url" data-load-more="{{ $nextUrl }}"
                               @click.prevent="load()" x-show="url"
                               x-text="loading ? 'Cargando…' : 'Mostrar más'"
                               class="mt-4 inline-block rounded-full border border-laton px-8 py-2.5 text-sm text-verde transition hover:bg-verde hover:text-crema">Mostrar más</a>
                        @endif
                    </div>
                @else
                    <div class="rounded-3xl border border-arena bg-crema/70 px-6 py-16 text-center">
                        <p class="font-display text-3xl">{{ $emptyMessage ?? 'No hay prendas con estos filtros' }}</p>
                        @if ($emptyLink !== null)
                            <a href="{{ $emptyLink['url'] }}" class="mt-6 inline-block rounded-full border border-laton px-6 py-2.5 text-sm text-verde">{{ $emptyLink['label'] }}</a>
                        @elseif ($emptyMessage === null)
                            <p class="mt-2 text-sm text-gris-calido">Quita alguno o empieza de nuevo.</p>
                            <a href="{{ $clearUrl }}" class="mt-6 inline-block rounded-full border border-laton px-6 py-2.5 text-sm text-verde">Limpiar filtros</a>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </form>
</x-store.layout>
