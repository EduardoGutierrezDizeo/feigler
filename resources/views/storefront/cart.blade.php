{{--
    La página del carrito.

    Contrato de datos (`$cart`, arreglo construido en CartController::page):
      items: [{ id (int), quantity (int), unit_price (int), subtotal (int),
                available_stock (int), status (string:
                    'available' | 'insufficient_stock' | 'unavailable'),
                product_name (string), product_slug (string), product_url (string),
                color_name (string), color_hex (string|null), size_name (string),
                image (string|null), notice (string), max_options (int),
                urls: { quantity (string), remove (string) } }]
      subtotal: int (solo líneas disponibles e insuficientes)
      units: int
      empty: bool

    La página llega pintada por el servidor para no parpadear; los componentes
    Alpine cartPage y cartLine solo re-pintan las partes que cambian. Una línea
    que ya no se vende se muestra atenuada, sin control de cantidad ni subtotal,
    solo con la opción de eliminarla, y no suma al total. El encabezado ya viene
    con el contador; cuando re-pinta, cart-page dispara `cart-updated` para
    actualizar el marcador sin recargar.
--}}
<x-store.layout :title="'Carrito · Feigler'" :cart-count="$cart['units']">
    <div x-data="cartPage(@js($cart))" @cart-changed.window="apply($event.detail)"
         class="mx-auto w-full max-w-5xl px-4 pt-10 pb-20 sm:px-8 sm:pt-14">

        <header>
            <h1 class="font-display text-4xl text-verde">Carrito</h1>
            <p class="mt-2 text-sm text-gris-calido">Tus prendas a tu medida, listas para el siguiente paso.</p>
        </header>

        <div x-show="empty" @if (! $cart['empty']) style="display:none" @endif
             class="mt-6">
            <div class="rounded-3xl border border-arena bg-crema/70 px-6 py-12 text-center">
                <h2 class="font-display text-2xl text-tinta">Tu carrito está vacío.</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-gris-calido">Todavía no diste ninguna pasada: sin miedo, la tienda está llena de puntos para la primera.</p>
                <a href="{{ route('storefront.tienda') }}"
                   class="mt-6 inline-block rounded-full border border-laton px-6 py-2.5 text-sm text-verde transition hover:bg-verde hover:text-crema">Ir a la tienda</a>
                <a href="{{ route('storefront.novedades') }}"
                   class="mt-4 block text-sm text-verde underline decoration-laton/60 underline-offset-4 hover:text-verde-hondo">Ver novedades</a>
            </div>
        </div>

        <div x-show="!empty" @if ($cart['empty']) style="display:none" @endif
             class="mt-6">
            <ul class="space-y-4">
                @foreach ($cart['items'] as $line)
                    <li x-data="cartLine(@js($line))" :class="status === 'unavailable' ? 'opacity-60' : null"
                        class="flex flex-wrap items-center gap-4 rounded-3xl border border-arena bg-crema/70 p-4 sm:flex-nowrap sm:gap-6 sm:p-5">

                        <a href="{{ $line['product_url'] }}" class="block shrink-0 overflow-hidden rounded-2xl border border-arena">
                            @if ($line['image'] !== null)
                                <img src="{{ $line['image'] }}" alt="" loading="lazy" width="96" height="128"
                                     class="aspect-3/4 h-24 w-20 object-cover">
                            @else
                                <span class="block aspect-3/4 h-24 w-20 bg-linear-to-b from-[#F3ECDD] to-arena" aria-hidden="true"></span>
                            @endif
                        </a>

                        <div class="min-w-0 grow">
                            <p class="font-display leading-snug text-tinta">
                                <a href="{{ $line['product_url'] }}" class="hover:text-verde">{{ $line['product_name'] }}</a>
                            </p>
                            <p class="mt-1 text-sm text-gris-calido">
                                {{ $line['color_name'] !== '' ? $line['color_name'] : 'Color único' }}@if ($line['size_name'] !== '')<span class="mx-2" aria-hidden="true">·</span>{{ $line['size_name'] }}@endif
                            </p>
                            <p class="mt-2 text-sm text-ladrillo" x-show="notice"
                               @if ($line['notice'] === '') style="display:none" @endif
                               x-text="notice">{{ $line['notice'] }}</p>
                            <p class="mt-1 text-sm text-ladrillo" x-show="failed" style="display:none" x-text="failed"></p>
                            <p class="mt-1 text-xs text-gris-calido" x-show="busy" style="display:none">Guardando…</p>
                        </div>

                        <div class="flex w-full items-end justify-between gap-x-4 gap-y-3 sm:w-auto sm:flex-col sm:items-end sm:gap-3">
                            <div x-show="status !== 'unavailable'" @if ($line['status'] === 'unavailable') style="display:none" @endif>
                                <label for="cantidad-{{ $line['id'] }}" class="mb-1 block text-xs text-gris-calido">Cantidad</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="stepQuantity(-1)" :disabled="busy || quantity <= 1"
                                            aria-label="Disminuir cantidad"
                                            class="grid size-10 shrink-0 place-items-center rounded-full border border-arena bg-crema text-lg text-verde transition hover:border-laton focus:outline-none disabled:cursor-not-allowed disabled:opacity-50">−</button>
                                    <div class="relative">
                                        <select id="cantidad-{{ $line['id'] }}" x-ref="quantity" @change="setQuantity($event)"
                                                class="appearance-none bg-none rounded-full border border-arena bg-crema py-2.5 ps-4 pe-10 text-sm text-tinta shadow-lift transition focus:border-laton focus:ring-1 focus:ring-laton focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="busy">
                                            @for ($n = 1; $n <= $line['max_options']; $n++)
                                                <option value="{{ $n }}" @selected($n === $line['quantity']) @disabled($n > $line['available_stock']) :disabled="{{ $n }} > availableStock">{{ $n }}</option>
                                            @endfor
                                        </select>
                                        <svg class="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-gris-calido" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                    <button type="button" @click="stepQuantity(1)" :disabled="busy || quantity >= availableStock"
                                            aria-label="Aumentar cantidad"
                                            class="grid size-10 shrink-0 place-items-center rounded-full border border-arena bg-crema text-lg text-verde transition hover:border-laton focus:outline-none disabled:cursor-not-allowed disabled:opacity-50">+</button>
                                </div>
                            </div>

                            <div class="text-right" x-show="status !== 'unavailable'" @if ($line['status'] === 'unavailable') style="display:none" @endif>
                                <p class="text-xs text-gris-calido">Subtotal</p>
                                <p class="font-display text-lg text-verde" x-text="subtotalLabel">${{ number_format($line['subtotal'], 0, ',', '.') }}</p>
                            </div>

                            <button type="button" @click="remove()" :disabled="busy"
                                    class="shrink-0 text-xs text-gris-calido underline decoration-gris-calido/40 underline-offset-4 transition hover:text-ladrillo disabled:opacity-60">
                                Eliminar
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>

            <section aria-label="Resumen" class="mt-6">
                <div class="rounded-3xl border border-arena bg-crema/70 p-5 sm:p-6">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-sm text-gris-calido">Unidades</p>
                        <p class="text-sm text-tinta" x-text="unitsLabel">{{ $cart['units'] === 1 ? '1 unidad' : $cart['units'].' unidades' }}</p>
                    </div>
                    <div class="mt-2 flex items-baseline justify-between gap-4">
                        <p class="font-display text-verde">Subtotal</p>
                        <p class="font-display text-2xl text-verde" x-text="subtotalLabel">${{ number_format($cart['subtotal'], 0, ',', '.') }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gris-calido">Envío: {{ config('tienda.envio_resumen') }}</p>
                    <button type="button" disabled
                            class="mt-5 w-full cursor-not-allowed rounded-full bg-verde px-6 py-3 text-sm text-crema opacity-60"
                            title="La compra estará disponible próximamente">Finalizar compra · Próximamente</button>
                </div>
            </section>
        </div>
    </div>
</x-store.layout>