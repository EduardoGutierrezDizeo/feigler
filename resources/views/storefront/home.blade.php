{{--
    Vista principal.
    Contrato de datos (arreglos simples, sin modelos Eloquent):
      $sections:    [{ key: 'hombre'|'mujer'|'ninos', label, url,
                       categories: [{ name, count (int), image (url|null), url }] }]
      $newProducts: lista de tarjetas (ver components/store/product-card)
      $store:       { hours, mapUrl, image (url|null) }
      $cartCount:   int (opcional)
--}}
@php
    $carousel = ['sections' => $sections, 'initial' => $sections[0]['key'] ?? null];
@endphp

<x-store.layout title="Feigler · Ropa con tradición" :cart-count="$cartCount ?? 0">
    <div class="mx-auto max-w-7xl px-4 sm:px-8">

        @if (session('status') === 'account-deleted')
            <div class="pt-8">
                <x-store.status status="Tu cuenta fue eliminada." />
            </div>
        @endif

        {{-- Categorías por sección --}}
        <section class="pt-10 sm:pt-14" aria-labelledby="titulo-categorias"
                 x-data="categoryCarousel(@js($carousel))" @resize.window.debounce.150ms="measure()">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <h2 id="titulo-categorias" class="font-display text-4xl leading-tight sm:text-5xl">
                    Todo en un solo <em class="italic text-verde">vestier</em>
                </h2>
                <div role="tablist" aria-label="Sección de la tienda"
                     class="inline-flex self-start rounded-full border border-arena bg-crema p-1 sm:self-auto">
                    <template x-for="s in sections" :key="s.key">
                        <button type="button" role="tab" :aria-selected="section === s.key" @click="setSection(s.key)" x-text="s.label"
                                class="rounded-full px-5 py-2 text-sm transition duration-300"
                                :class="{ 'bg-linear-to-b from-verde to-verde-hondo text-crema shadow-md': section === s.key, 'text-gris-calido hover:text-verde': section !== s.key }"></button>
                    </template>
                </div>
            </div>

            <div class="mt-8 grid grid-cols-1">
                @foreach ($sections as $sectionSection)
                    @php
                        $sectionActiva = ($carousel['initial'] ?? null) === $sectionSection['key'];
                    @endphp
                    <div data-category-panel="{{ $sectionSection['key'] }}"
                         @if (! $sectionActiva) inert aria-hidden="true" @endif
                         :inert="!isActive($el)"
                         :aria-hidden="isActive($el) ? false : 'true'"
                         :class="{ 'visible opacity-100 translate-y-0': isActive($el), 'invisible opacity-0 translate-y-1 pointer-events-none': !isActive($el) }"
                         class="col-start-1 row-start-1 {{ $sectionActiva ? 'visible opacity-100 translate-y-0' : 'invisible opacity-0 translate-y-1 pointer-events-none' }} transition-[opacity,transform,visibility] duration-300 ease-out motion-reduce:transition-none motion-reduce:transform-none">
                        <div class="relative">
                            <div data-category-track @scroll.passive.throttle.80ms="update()"
                                 class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto scroll-smooth px-4 pb-2 sm:-mx-8 sm:scroll-px-8 sm:gap-6 sm:px-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                @foreach ($sectionSection['categories'] as $categoria)
                                    <a href="{{ $categoria['url'] }}"
                                       class="group w-[68%] shrink-0 snap-start sm:w-[calc((100%-1.5rem)/2)] md:w-[calc((100%-3rem)/3)] lg:w-[calc((100%-4.5rem)/4)]">
                                        <div class="relative aspect-4/5 overflow-hidden rounded-t-full bg-linear-to-b from-[#F3ECDD] to-arena">
                                            @if ($categoria['image'])
                                                <img src="{{ $categoria['image'] }}" alt="{{ $categoria['name'] }}" loading="lazy"
                                                     class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                                            @endif
                                        </div>
                                        <h3 class="mt-4 font-display text-2xl">{{ $categoria['name'] }}</h3>
                                        <p class="text-xs text-gris-calido">{{ $categoria['count'] }} {{ $categoria['count'] === 1 ? 'prenda' : 'prendas' }}</p>
                                    </a>
                                @endforeach
                            </div>

                            <button type="button" data-carousel-arrows @click="scroll(-1)" aria-label="Categorías anteriores"
                                    :class="{ invisible: atStart, 'opacity-0': atStart, 'pointer-events-none': atStart }"
                                    class="absolute left-0 top-1/3 -translate-x-1/3 size-11 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white md:grid max-md:invisible max-md:pointer-events-none invisible opacity-0 pointer-events-none">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
                            </button>
                            <button type="button" data-carousel-arrows @click="scroll(1)" aria-label="Más categorías"
                                    :class="{ invisible: atEnd, 'opacity-0': atEnd, 'pointer-events-none': atEnd }"
                                    class="absolute right-0 top-1/3 translate-x-1/3 size-11 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white md:grid max-md:invisible max-md:pointer-events-none invisible opacity-0 pointer-events-none">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                            </button>

                            <div class="mt-6 flex h-4 items-center justify-center gap-1.5" data-carousel-dots
                                 :class="{ invisible: pages <= 1, 'opacity-0': pages <= 1, 'pointer-events-none': pages <= 1 }">
                                <template x-for="i in pages" :key="i">
                                    <button type="button" @click="goTo(i - 1)" :aria-label="'Ir al grupo ' + i" :aria-current="page === i - 1"
                                            class="h-1 rounded-full transition-all" :class="page === i - 1 ? 'w-8 bg-verde' : 'w-4 bg-arena'"></button>
                                </template>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 flex justify-center">
                <a :href="current.url"
                   class="rounded-full border border-laton px-6 py-2.5 text-sm text-verde transition hover:bg-verde hover:text-crema">
                    Ver todo <span x-text="current.label"></span>
                </a>
            </div>
        </section>

        {{-- Novedades --}}
        <section class="pt-20" aria-labelledby="titulo-novedades">
            <div class="flex items-end justify-between gap-4">
                <h2 id="titulo-novedades" class="font-display text-4xl leading-tight sm:text-5xl">Lo más <em class="italic text-verde">nuevo</em></h2>
                <a href="{{ url('/novedades') }}" class="hidden text-sm text-verde underline decoration-laton underline-offset-4 sm:block">Ver todas las novedades</a>
            </div>
            <div class="mt-8 grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
                @foreach ($newProducts as $product)
                    <x-store.product-card :product="$product" />
                @endforeach
            </div>
        </section>

        {{-- Nuestra tienda --}}
        <section class="pt-20" aria-labelledby="titulo-tienda">
    <div class="relative isolate overflow-hidden rounded-3xl bg-linear-to-br from-verde to-verde-hondo">
        {{-- Brillo decorativo --}}
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 -top-24 -z-10 h-72 w-72 rounded-full bg-laton/20 blur-3xl"></div>

        <div class="grid md:grid-cols-2">
            {{-- Contenido --}}
            <div class="flex flex-col items-start justify-center gap-6 px-6 py-12 sm:px-10 sm:py-14 lg:px-14 lg:py-16">
                <p class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-laton">
                    <span aria-hidden="true" class="h-px w-8 bg-laton"></span>
                    Nuestra tienda
                </p>

                <h2 id="titulo-tienda" class="font-display text-4xl leading-tight text-crema sm:text-5xl lg:text-6xl">
                    Ven a probarte la <em class="italic text-laton">tradición</em>
                </h2>

                {{-- Horario --}}
                <div class="w-full max-w-md">
                    <h3 class="mb-2 text-xs font-medium uppercase tracking-[0.2em] text-laton">Horario de atención</h3>

                    @if (! empty($store['hours']))
                        <ul class="divide-y divide-crema/15 border-y border-crema/15">
                            @foreach (preg_split('/\R/u', trim($store['hours'])) as $line)
                                @php
                                    $parts = preg_split('/:\s+/u', trim($line), 2);
                                @endphp
                                <li class="flex items-baseline justify-between gap-4 py-3 text-sm">
                                    @if (count($parts) === 2)
                                        <span class="text-crema/70">{{ $parts[0] }}</span>
                                        <span class="text-right font-medium text-crema">{{ $parts[1] }}</span>
                                    @else
                                        <span class="text-crema">{{ trim($line) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="border-y border-crema/15 py-3 text-sm text-crema/90">
                            Madera, ladrillo y cuero. Atendemos de lunes a sábado.
                        </p>
                    @endif
                </div>

                {{-- Botón --}}
                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                    <a href="{{ $store['mapUrl'] ?? '#' }}"
                       class="inline-flex items-center justify-center gap-2 rounded-full bg-crema px-7 py-3 text-sm font-medium text-verde transition hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-laton">
                        Cómo llegar
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                        </svg>
                    </a>
                </div>
            </div>

            {{-- Imagen --}}
            <div class="relative order-first h-64 sm:h-80 md:order-last md:h-auto md:min-h-[26rem]">
                @if (! empty($store['image']))
                    <img src="{{ $store['image'] }}"
                         alt="Fachada de la tienda Feigler"
                         loading="lazy"
                         class="absolute inset-0 h-full w-full object-cover">
                @else
                    <div class="absolute inset-0 bg-linear-to-b from-madera/80 to-madera">
                        <span class="absolute bottom-4 left-5 text-xs text-crema/80">Foto: fachada</span>
                    </div>
                @endif

                {{-- Degradado para fundir la imagen con el contenido --}}
                <div aria-hidden="true" class="absolute inset-0 bg-linear-to-t from-verde-hondo/70 via-transparent to-transparent md:bg-linear-to-r md:from-verde-hondo/60 md:via-transparent md:to-transparent"></div>
            </div>
        </div>
    </div>
</section>
    </div>
</x-store.layout>
