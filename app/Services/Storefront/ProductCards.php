<?php

namespace App\Services\Storefront;

use App\Models\Color;
use App\Models\Product;
use Carbon\Carbon;

/**
 * La tarjeta de producto que pintan la portada y la ficha (relacionados), con
 * la forma que exige resources/views/components/store/product-card.blade.php.
 *
 * Existe para que una prenda se arregle en un solo sitio: si mañana cambia el
 * distintivo o la URL, cambia en los dos lugares a la vez. Y se arma leyendo las
 * relaciones que el llamador ya trajo (variantes con su color, imágenes), así
 * que una lista de cuatro tarjetas no cuesta ninguna consulta en lugar de una
 * por tarjeta.
 */
class ProductCards
{
    /**
     * Días que un producto recién creado conserva el distintivo «nuevo».
     */
    private const BADGE_NUEVO_DIAS = 30;

    /**
     * Las tarjetas de una lista de productos, en el orden en que llegan.
     *
     * @param  iterable<int, Product>  $products
     * @return list<array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}>
     */
    public static function makeAll(iterable $products): array
    {
        return collect($products)
            ->map(fn (Product $product): array => static::make($product))
            ->values()
            ->all();
    }

    /**
     * La tarjeta de un producto: nombre, enlace, precio, miniatura, distintivo
     * y los puntos de color que se ofrecen.
     *
     * @return array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}
     */
    public static function make(Product $product): array
    {
        $badge = null;

        if ($product->stock_total <= 0) {
            $badge = 'agotado';
        } elseif (static::isNewProduct($product)) {
            $badge = 'nuevo';
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'url' => route('storefront.product', $product->slug),
            'price' => (int) round((float) $product->base_price),
            'image' => $product->coverImage?->thumbnailUrl(),
            'badge' => $badge,
            'colors' => static::colorsOf($product),
        ];
    }

    /**
     * Los colores en que la prenda se vende, por id, con los apagados fuera.
     *
     * Lee `variants` (ya cargada por el llamador) y el color de cada una, así
     * que una tarjeta no consulta nada.
     *
     * @return list<array{name: string, hex: string}>
     */
    private static function colorsOf(Product $product): array
    {
        return $product->variants
            ->where('is_active', true)
            ->map(fn ($variant) => $variant->color)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->filter(fn (Color $color): bool => $color->is_active)
            ->map(fn (Color $color): array => [
                'name' => $color->name,
                'hex' => $color->hex,
            ])
            ->values()
            ->all();
    }

    /**
     * Si el producto entró en el catálogo hace menos de 30 días.
     */
    private static function isNewProduct(Product $product): bool
    {
        if (! $product->created_at) {
            return false;
        }

        $created = $product->created_at instanceof Carbon
            ? $product->created_at
            : Carbon::parse($product->created_at);

        return $created->greaterThanOrEqualTo(now()->subDays(self::BADGE_NUEVO_DIAS)->startOfDay());
    }
}
