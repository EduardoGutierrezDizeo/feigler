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
                                class="rounded-full px-5 py-2 text-sm transition"
                                :class="section === s.key ? 'bg-linear-to-b from-verde to-verde-hondo text-crema shadow-md' : 'text-gris-calido hover:text-verde'"></button>
                    </template>
                </div>
            </div>

            <div class="relative mt-8">
                <div x-ref="track" @scroll.passive.throttle.80ms="update()"
                     class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto scroll-smooth px-4 pb-2 sm:-mx-8 sm:scroll-px-8 sm:gap-6 sm:px-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <template x-for="cat in current.categories" :key="cat.url">
                        <a :href="cat.url"
                           class="group w-[68%] shrink-0 snap-start sm:w-[calc((100%-1.5rem)/2)] md:w-[calc((100%-3rem)/3)] lg:w-[calc((100%-4.5rem)/4)]">
                            <div class="relative aspect-4/5 overflow-hidden rounded-t-full bg-linear-to-b from-[#F3ECDD] to-arena">
                                <img x-show="cat.image" :src="cat.image" :alt="cat.name" loading="lazy"
                                     class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                            </div>
                            <h3 class="mt-4 font-display text-2xl" x-text="cat.name"></h3>
                            <p class="text-xs text-gris-calido" x-text="cat.count + (cat.count === 1 ? ' prenda' : ' prendas')"></p>
                        </a>
                    </template>
                </div>

                <button type="button" x-show="!atStart" @click="scroll(-1)" aria-label="Categorías anteriores" style="display: none"
                        class="absolute left-0 top-1/3 hidden size-11 -translate-x-1/3 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white md:grid">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
                </button>
                <button type="button" x-show="!atEnd" @click="scroll(1)" aria-label="Más categorías" style="display: none"
                        class="absolute right-0 top-1/3 hidden size-11 translate-x-1/3 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white md:grid">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                </button>
            </div>

            <div class="mt-6 flex justify-center gap-1.5" x-show="pages > 1" style="display: none">
                <template x-for="i in pages" :key="i">
                    <button type="button" @click="goTo(i - 1)" :aria-label="'Ir al grupo ' + i" :aria-current="page === i - 1"
                            class="h-1 rounded-full transition-all" :class="page === i - 1 ? 'w-8 bg-verde' : 'w-4 bg-arena'"></button>
                </template>
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
            <div class="grid overflow-hidden rounded-3xl bg-linear-to-br from-verde to-verde-hondo md:grid-cols-2">
                <div class="flex flex-col items-start justify-center gap-5 px-6 py-12 sm:px-12 sm:py-16">
                    <p class="text-sm text-laton">Nuestra tienda</p>
                    <h2 id="titulo-tienda" class="font-display text-4xl leading-tight text-crema sm:text-5xl">
                        Ven a probarte la <em class="italic text-laton">tradición</em>
                    </h2>
                    <p class="text-sm text-crema/90">{{ $store['hours'] ?? 'Madera, ladrillo y cuero. Atendemos de lunes a sábado.' }}</p>
                    <a href="{{ $store['mapUrl'] ?? '#' }}" class="rounded-full bg-crema px-6 py-2.5 text-sm text-verde transition hover:bg-white">Cómo llegar</a>
                </div>
                <div class="flex items-end justify-center px-6 pt-2 md:justify-end md:px-12">
                    <div class="relative h-64 w-56 overflow-hidden rounded-t-full bg-linear-to-b from-madera/80 to-madera sm:h-80 sm:w-64">
                        @if (! empty($store['image']))
                            <img src="{{ $store['image'] }}" alt="Fachada de la tienda Feigler" loading="lazy" class="h-full w-full object-cover">
                        @else
                            <span class="absolute bottom-3 left-4 text-xs text-crema/80">Foto: fachada</span>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-store.layout>
