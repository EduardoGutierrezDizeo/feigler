{{--
    Tarjeta de producto.
    $product: { name, url, price (int, pesos), image (url|null), badge ('nuevo'|'agotado'|null),
                colors: [{ name, hex }] }
--}}
@props(['product'])

@php
    $badge = $product['badge'] ?? null;
    $soldOut = $badge === 'agotado';
@endphp

<article class="group relative">
    <a href="{{ $product['url'] }}"
       class="block overflow-hidden rounded-2xl border border-arena/80 bg-crema shadow-[0_10px_28px_-18px_rgba(30,27,24,0.35)] transition duration-300 hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-laton">
        <div class="relative aspect-4/5 bg-linear-to-b from-[#F3ECDD] to-arena">
            @if (! empty($product['image']))
                <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy"
                     class="h-full w-full object-cover {{ $soldOut ? 'opacity-60' : '' }}">
            @endif
            @if ($badge === 'nuevo')
                <span class="absolute left-3 top-3 rounded-full bg-verde-hondo px-3 py-1 text-xs text-crema">Nuevo</span>
            @elseif ($soldOut)
                <span class="absolute left-3 top-3 rounded-full bg-ladrillo/15 px-3 py-1 text-xs text-ladrillo">Agotado</span>
            @endif
        </div>
        <div class="space-y-1 p-4">
            <h3 class="truncate text-[0.95rem]">{{ $product['name'] }}</h3>
            <p class="text-sm text-gris-calido">${{ number_format($product['price'], 0, ',', '.') }}</p>
            @if (! empty($product['colors']))
                <ul class="flex gap-1.5 pt-1" aria-label="Colores disponibles">
                    @foreach ($product['colors'] as $color)
                        <li class="size-3.5 rounded-full border border-black/10" style="background-color: {{ $color['hex'] }}" title="{{ $color['name'] }}"></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </a>

    <button type="button" data-wishlist="{{ $product['id'] ?? '' }}" aria-label="Agregar {{ $product['name'] }} a favoritos"
            class="absolute right-3 top-3 grid size-9 place-items-center rounded-full bg-crema/90 text-verde shadow-sm transition hover:bg-crema hover:text-verde-hondo">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z" stroke-linejoin="round"/></svg>
    </button>
</article>
