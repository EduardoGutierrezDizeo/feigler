{{--
    Vista de producto.
    Contrato de datos:
      $product: { name, reference, section_label, category_label, price (int),
                  summary (string|null), description (string|null),
                  materials: [{ name, percentage }],
                  colors:   [{ id, name, hex, images: [{ url, thumb|null }] }],
                  initialColor (int, índice del color de portada; opcional, 0 por defecto),
                  sizes:    [string]                      (talla de la categoría, en orden)
                  variants: [{ id, color (id del color), size (string), stock (int) }],
                  breadcrumb: [{ label, url }] }
      $related: { title, products: [tarjetas] }
      $cartCount: int (opcional)
--}}
@php
    $purchase = [
        'name' => $product['name'],
        'colors' => $product['colors'],
        'sizes' => $product['sizes'],
        'variants' => $product['variants'],
        'initialColor' => $product['initialColor'] ?? 0,
        'lowStock' => 3,
    ];
    $sections = [
        ['Descripción', $product['description'] ?? null],
        ['Material y cuidado', null],
        ['Envíos y cambios', null],
    ];
@endphp

<x-store.layout :title="$product['name'] . ' · Feigler'" :cart-count="$cartCount ?? 0">
    <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-8" x-data="productPurchase(@js($purchase))">

        <nav aria-label="Ruta" class="text-xs text-gris-calido">
            <ol class="flex flex-wrap items-center gap-x-1.5">
                @foreach ($product['breadcrumb'] as $crumb)
                    <li class="flex items-center gap-1.5">
                        @if ($loop->last)
                            <span class="text-tinta" aria-current="page">{{ $crumb['label'] }}</span>
                        @else
                            <a href="{{ $crumb['url'] }}" class="hover:text-verde">{{ $crumb['label'] }}</a><span aria-hidden="true">/</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="mt-6 grid gap-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-14">

            {{-- Galería (cambia con el color elegido) --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:gap-4">
                <ul class="order-2 flex gap-3 overflow-x-auto sm:order-1 sm:w-20 sm:shrink-0 sm:flex-col sm:overflow-visible" aria-label="Miniaturas">
                    <template x-for="(img, i) in images" :key="img.url">
                        <li class="w-16 shrink-0 sm:w-full">
                            <button type="button" @click="imageIndex = i" :aria-label="'Ver foto ' + (i + 1)" :aria-current="imageIndex === i"
                                    class="block aspect-3/4 w-full overflow-hidden rounded-xl border bg-linear-to-b from-[#F3ECDD] to-arena transition"
                                    :class="imageIndex === i ? 'border-laton ring-2 ring-laton/40' : 'border-arena hover:border-laton/60'">
                                <img :src="img.thumb || img.url" alt="" loading="lazy" class="h-full w-full object-cover">
                            </button>
                        </li>
                    </template>
                </ul>

                <div class="relative order-1 aspect-4/5 min-w-0 flex-1 overflow-hidden rounded-3xl border border-arena/80 bg-linear-to-b from-[#F3ECDD] to-arena sm:order-2">
                    <img x-show="image" :src="image?.url" :alt="name + ' en ' + (color?.name ?? '')" class="h-full w-full object-cover" style="display: none">
                    <p x-show="!image" class="grid h-full place-items-center px-6 text-center text-sm text-gris-calido">Este color todavía no tiene fotos.</p>

                    <div x-show="images.length > 1" style="display: none">
                            <button type="button" @click="prev()" aria-label="Foto anterior"
                                    class="absolute left-3 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
                            </button>
                            <button type="button" @click="next()" aria-label="Foto siguiente"
                                    class="absolute right-3 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-crema text-verde shadow-md transition hover:bg-white">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                            </button>
                    </div>

                    <p x-show="images.length" class="absolute inset-x-0 bottom-4 text-center text-xs text-gris-calido" style="display: none">
                        <span x-text="imageIndex + 1"></span> / <span x-text="images.length"></span> · <span x-text="color?.name"></span>
                    </p>
                </div>
            </div>

            {{-- Información y compra --}}
            <div class="min-w-0">
                <p class="text-xs text-laton">{{ $product['reference'] }} · {{ $product['section_label'] }} · {{ $product['category_label'] }}</p>
                <h1 class="mt-3 font-display text-5xl leading-none sm:text-6xl">{{ $product['name'] }}</h1>
                <p class="mt-4 font-display text-3xl text-verde">${{ number_format($product['price'], 0, ',', '.') }}</p>
                @if (! empty($product['summary']))
                    <p class="mt-5 max-w-prose leading-relaxed text-gris-calido">{{ $product['summary'] }}</p>
                @endif

                {{-- Color --}}
                <div class="mt-8">
                    <p class="text-sm">Color · <span class="text-gris-calido" x-text="color?.name"></span></p>
                    <div class="mt-3 flex flex-wrap gap-3" role="group" aria-label="Color">
                        <template x-for="(c, i) in colors" :key="c.id">
                            <button type="button" @click="selectColor(i)" :aria-pressed="colorIndex === i" :aria-label="c.name" :title="c.name"
                                    class="size-9 rounded-full border border-black/10 transition"
                                    :class="colorIndex === i ? 'ring-2 ring-verde ring-offset-2 ring-offset-crema' : 'hover:ring-2 hover:ring-laton/60 hover:ring-offset-2 hover:ring-offset-crema'"
                                    :style="'background-color:' + c.hex"></button>
                        </template>
                    </div>
                </div>

                {{-- Talla --}}
                <div class="mt-7">
                    <div class="flex items-center justify-between">
                        <p class="text-sm">Talla · <span class="text-gris-calido" x-text="size ?? 'elige una'"></span></p>
                        <button type="button" data-size-guide class="text-sm text-verde underline decoration-laton underline-offset-4">Guía de tallas</button>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2.5" role="group" aria-label="Talla">
                        <template x-for="s in sizes" :key="s">
                            <button type="button" @click="selectSize(s)" :disabled="stockFor(s) <= 0" :aria-pressed="size === s" x-text="s"
                                    class="min-w-12 rounded-xl border px-4 py-2.5 text-sm transition"
                                    :class="size === s
                                        ? 'border-verde-hondo bg-linear-to-b from-verde to-verde-hondo text-crema shadow-md'
                                        : (stockFor(s) <= 0 ? 'cursor-not-allowed border-arena/60 text-gris-calido/50 line-through' : 'border-arena hover:border-verde')"></button>
                        </template>
                    </div>
                    <p x-show="showLowStock" class="mt-3 text-sm text-ladrillo" style="display: none" role="status">Quedan pocas unidades en esta talla</p>
                </div>

                {{-- Cantidad y carrito --}}
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <div class="inline-flex items-center rounded-full border border-arena" role="group" aria-label="Cantidad">
                        <button type="button" @click="step(-1)" aria-label="Menos" class="grid size-11 place-items-center text-lg text-gris-calido hover:text-verde">−</button>
                        <span class="w-8 text-center text-sm" x-text="quantity" aria-live="polite"></span>
                        <button type="button" @click="step(1)" aria-label="Más" class="grid size-11 place-items-center text-lg text-gris-calido hover:text-verde">+</button>
                    </div>
                    <button type="button" @click="addToCart()" :disabled="!canAdd"
                            class="min-w-0 flex-1 rounded-full bg-linear-to-b from-verde to-verde-hondo px-6 py-3 text-sm text-crema shadow-[0_10px_20px_-10px_rgba(7,63,37,0.7)] transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none"
                            x-text="size === null ? 'Elige una talla' : (selectedStock <= 0 ? 'Agotado' : 'Agregar al carrito')"></button>
                    <button type="button" data-wishlist aria-label="Agregar a favoritos"
                            class="grid size-12 place-items-center rounded-full border border-laton text-verde transition hover:bg-verde hover:text-crema">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z" stroke-linejoin="round"/></svg>
                    </button>
                </div>

                <p class="mt-6 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gris-calido"><span>{{ config('tienda.envio_resumen') }}</span><span>{{ config('tienda.cambios_resumen') }}</span></p>

                {{-- Acordeones --}}
                <div class="mt-8 divide-y divide-arena border-y border-arena">
                    @foreach ($sections as [$heading, $body])
                        @continue($heading === 'Descripción' && empty($body))
                        <div x-data="{ open: false }">
                            <h2>
                                <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="acordeon-{{ $loop->index }}"
                                        class="flex w-full items-center justify-between py-4 text-left text-[0.95rem]">
                                    {{ $heading }}
                                    <span class="text-laton transition-transform" :class="open && 'rotate-45'" aria-hidden="true">+</span>
                                </button>
                            </h2>
                            <div id="acordeon-{{ $loop->index }}" class="grid transition-[grid-template-rows] duration-300" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                                <div class="overflow-hidden">
                                    <div class="pb-5 text-sm leading-relaxed text-gris-calido">
                                        @if ($heading === 'Descripción')
                                            {{ $body }}
                                        @elseif ($heading === 'Material y cuidado')
                                            @forelse ($product['materials'] as $material)
                                                <p>{{ $material['name'] }} {{ $material['percentage'] }}%</p>
                                            @empty
                                                <p>Composición no indicada.</p>
                                            @endforelse
                                        @else
                                            <p>{{ config('tienda.envios_cambios_texto') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Relacionados --}}
        @if (! empty($related['products']))
            <section class="pt-20" aria-labelledby="titulo-relacionados">
                <p class="text-xs text-laton">También te puede gustar</p>
                <h2 id="titulo-relacionados" class="mt-2 font-display text-4xl sm:text-5xl">Más de <em class="italic text-verde">{{ $related['title'] }}</em></h2>
                <div class="mt-8 grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
                    @foreach ($related['products'] as $item)
                        <x-store.product-card :product="$item" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-store.layout>
