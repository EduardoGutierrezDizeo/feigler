<?php

namespace App\Services\Storefront;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Collection;

/**
 * Los datos reales de la ficha pública de producto, con exactamente la forma
 * que resources/views/storefront/product.blade.php pide en su comentario
 * inicial (el contrato manda): variantes, stock por talla y color, galería por
 * color, materiales y relacionados.
 *
 * Todo se trae por adelantado —variantes con su talla y su color, imágenes,
 * materiales, categoría— y se arma en memoria, de modo que la ruta no hace una
 * consulta por variante, por imagen ni por relacionado: el número de consultas
 * es el mismo con tres variantes que con doce.
 *
 * Solo un producto visible llega aquí: un slug inexistente, un producto
 * apagado o uno sin variantes activas terminan en 404 (firstOrFail).
 */
class ProductPage
{
    /**
     * Prendas sugeridas que caben al pie de la ficha.
     */
    private const RELATED_LIMIT = 4;

    /**
     * La ficha de un producto por su slug público.
     *
     * @return array{
     *     product: array{
     *         name: string,
     *         reference: string,
     *         section_label: string,
     *         category_label: string,
     *         price: int,
     *         summary: null,
     *         description: null,
     *         materials: list<array{name: string, percentage: int}>,
     *         colors: list<array{id: int, name: string, hex: string, images: list<array{url: string, thumb: string|null}>}>,
     *         initialColor: int,
     *         sizes: list<string>,
     *         variants: list<array{id: int, color: int, size: string, stock: int}>,
     *         breadcrumb: list<array{label: string, url: string}>
     *     },
     *     related: array{title: string, products: list<array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}>},
     *     cartCount: int
     * }
     */
    public function forSlug(string $slug): array
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->visible()
            ->with([
                'category',
                'materials',
                'images',
                'variants' => fn ($query) => $query->where('is_active', true)->with(['size', 'color']),
            ])
            ->firstOrFail();

        $colors = $this->colorsOf($product);
        $initialColor = (int) ($colors->search(
            fn (Color $color): bool => $color->getKey() === $product->cover_color_id,
            true,
        ) ?: 0);

        return [
            'product' => [
                'name' => $product->name,
                'reference' => $product->reference,
                'section_label' => $product->section?->label() ?? '',
                'category_label' => $product->category->name,
                'price' => (int) round((float) $product->base_price),
                // La descripción guardada ya no se enseña en la tienda.
                'summary' => null,
                'description' => null,
                'materials' => $this->materialsOf($product),
                'colors' => $colors->map(fn (Color $color): array => [
                    'id' => $color->getKey(),
                    'name' => $color->name,
                    'hex' => $color->hex,
                    'images' => $this->galleryFor($product, $color),
                ])->values()->all(),
                'initialColor' => $initialColor,
                'sizes' => $this->sizesOf($product),
                'variants' => $this->variantsOf($product),
                'breadcrumb' => $this->breadcrumbFor($product),
            ],
            'related' => $this->relatedFor($product),
            'cartCount' => app(CartService::class)->currentCount(),
        ];
    }

    /**
     * Los colores distintos de las variantes activas, en el orden de la pestaña
     * Colores (`colors.order`); el id rompe los empates.
     *
     * @return Collection<int, Color>
     */
    private function colorsOf(Product $product): Collection
    {
        return $product->variants
            ->map(fn (ProductVariant $variant) => $variant->color)
            ->filter()
            ->unique('id')
            ->sortBy([['order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Las tallas de la categoría en que la prenda se vende con una variante
     * activa, en el orden de `sizes.order`. Una talla sin variante activa no
     * entra: no hay nada que vender en ella.
     *
     * @return list<string>
     */
    private function sizesOf(Product $product): array
    {
        return $product->variants
            ->map(fn (ProductVariant $variant) => $variant->size)
            ->filter()
            ->unique('id')
            ->sortBy([['order', 'asc'], ['id', 'asc']])
            ->map(fn (Size $size): string => $size->name)
            ->values()
            ->all();
    }

    /**
     * Una entrada por combinación talla + color con una variante activa. Las
     * combinaciones que no están aquí leen stock 0 en el navegador, que es
     * exactamente lo que product-purchase.js hace con una variante ausente.
     *
     * @return list<array{id: int, color: int, size: string, stock: int}>
     */
    private function variantsOf(Product $product): array
    {
        return $product->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->size !== null)
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->getKey(),
                'color' => $variant->color_id,
                'size' => $variant->size->name,
                'stock' => (int) $variant->stock,
            ])
            ->values()
            ->all();
    }

    /**
     * Las fotos de un color: la principal primero y el resto en el orden en que
     * se subieron. Un color sin fotos devuelve la lista vacía, que es lo que la
     * vista pinta con su marcador «Este color todavía no tiene fotos».
     *
     * @return list<array{url: string, thumb: string|null}>
     */
    private function galleryFor(Product $product, Color $color): array
    {
        return $product->images
            ->where('color_id', $color->getKey())
            ->sortBy([['is_primary', 'desc'], ['order', 'asc'], ['id', 'asc']])
            ->map(fn (ProductImage $image): array => [
                'url' => $image->url,
                'thumb' => $image->thumbnail_path !== null ? $image->thumbnailUrl() : null,
            ])
            ->values()
            ->all();
    }

    /**
     * La composición del producto, de mayor a menor porcentaje. Un producto sin
     * materiales devuelve el arreglo vacío, con el que la vista enseña
     * «Composición no indicada».
     *
     * @return list<array{name: string, percentage: int}>
     */
    private function materialsOf(Product $product): array
    {
        return $product->materials
            ->sortByDesc(fn (Material $material): int => (int) $material->pivot->percentage)
            ->map(fn (Material $material): array => [
                'name' => $material->name,
                'percentage' => (int) $material->pivot->percentage,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function breadcrumbFor(Product $product): array
    {
        $section = $product->section;

        return [
            ['label' => 'Inicio', 'url' => '/'],
            ['label' => $section?->label() ?? '', 'url' => $section !== null ? $section->route() : ''],
            ['label' => $product->name, 'url' => route('storefront.product', $product->slug)],
        ];
    }

    /**
     * Hasta cuatro prendas visibles: primero las de la misma categoría (las más
     * recientes) y, si faltan, de la misma sección, también las más recientes.
     *
     * Es una sola consulta con la categoría ordenada delante, así que el número
     * de consultas no cambia según cuántos relacionados haya.
     *
     * @return array{title: string, products: list<array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}>}
     */
    private function relatedFor(Product $product): array
    {
        $related = Product::query()
            ->visible()
            ->where('id', '!=', $product->getKey())
            ->where(function ($query) use ($product) {
                $query->where('category_id', $product->category_id)
                    ->orWhereHas('category', fn ($categories) => $categories->inSection($product->section));
            })
            ->orderByRaw('CASE WHEN category_id = '.(int) $product->category_id.' THEN 0 ELSE 1 END')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::RELATED_LIMIT)
            ->get();

        $related->load(['images', 'variants.color']);

        // En el futuro el administrador podrá elegir estos relacionados a mano.

        return [
            'title' => $product->category->name,
            'products' => ProductCards::makeAll($related),
        ];
    }
}
