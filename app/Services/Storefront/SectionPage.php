<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Services\ProductDetailsCatalog;
use App\Support\Concerns\NormalizesNames;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Los datos reales de la página de una sección (/hombre, /mujer, /ninos), con
 * exactamente la forma que resources/views/storefront/section.blade.php pide en
 * su comentario inicial (el contrato manda).
 *
 * Los filtros se aplican sobre una consulta de productos; talla, color y stock
 * se exigen sobre la misma variante activa (M+Azul no casa una variante
 * M-Rojo con una L-Azul), las familias distintas se suman (Y) y los valores de
 * una misma familia se alternan (O). Cada conteo que la vista enseña (categoría,
 * material, stock) y el total salen de una sola consulta agrupada, así que el
 * número de consultas de la página no crece según cuántas opciones o prendas
 * tenga la sección.
 *
 * Un valor que no pertenece a la sección se ignora: se cruza aquí con las
 * opciones que la sección realmente ofrece, que es el mismo saneo que deja una
 * URL rota servirse igual de bien.
 */
class SectionPage
{
    use NormalizesNames;

    /**
     * El paso de los dos deslizadores de precio, el mismo que usan las vistas.
     */
    private const PRICE_STEP = 1000;

    /**
     * Las opciones de orden, con su columna y su dirección.
     *
     * «relevancia» no tiene un dato de relevancia todavía: se ordena como las
     * novedades, que es el orden que ya guarda la tienda.
     *
     * @var array<string, array{column: string, direction: string}>
     */
    private const SORTING = [
        'novedades' => ['column' => 'created_at', 'direction' => 'desc'],
        'relevancia' => ['column' => 'created_at', 'direction' => 'desc'],
        'precio-asc' => ['column' => 'base_price', 'direction' => 'asc'],
        'precio-desc' => ['column' => 'base_price', 'direction' => 'desc'],
    ];

    public function __construct(private ProductDetailsCatalog $catalog) {}

    /**
     * @return array{
     *     section: array{key: string, label: string, url: string},
     *     total: int,
     *     shown: int,
     *     products: list<array{id: int, name: string, url: string, price: int, image: string|null, badge: string|null, colors: list<array{name: string, hex: string}>}>,
     *     nextUrl: string|null,
     *     clearUrl: string,
     *     sort: array{value: string, options: list<array{value: string, label: string}>},
     *     active: list<array{label: string, removeUrl: string}>,
     *     filters: array{
     *         categories: list<array{value: string, label: string, count: int, checked: bool}>,
     *         sizes: list<array{value: string, label: string, checked: bool}>,
     *         colors: list<array{value: string, label: string, hex: string, checked: bool}>,
     *         materials: list<array{value: string, label: string, count: int, checked: bool}>,
     *         price: array{min: int, max: int, from: int|null, to: int|null, step: int},
     *         stock: array{count: int, checked: bool}
     *     },
     *     cartCount: int
     * }
     */
    public function for(StoreSection $section, SectionFilters $filters): array
    {
        $sectionCategories = $this->sectionCategories($section);
        $sizeGroups = $this->catalog->sizeNamesForFilter($section);
        $colors = $this->catalog->colorsForFilter($section);
        $materials = $this->catalog->materialsForFilter($section);

        $selectedCategories = $this->intersectIds($filters->categories, $sectionCategories->pluck('id'));
        $selectedColors = $this->intersectIds($filters->colors, $colors->pluck('id'));
        $selectedMaterials = $this->intersectIds($filters->materials, $materials->pluck('id'));
        $selectedSizes = $this->matchSizeNames($filters->sizes, $sizeGroups);
        $sizeIds = $this->idsOfSizeNames($selectedSizes, $sizeGroups);

        [$sliderMin, $sliderMax] = $this->priceBounds($section);

        $from = $this->movedBound($filters->priceFrom, $sliderMin, $sliderMax, from: true);
        $to = $this->movedBound($filters->priceTo, $sliderMin, $sliderMax, from: false);

        $variantClause = ($sizeIds !== [] || $selectedColors !== [] || $filters->inStockOnly)
            ? $this->variantsOf($sizeIds, $selectedColors, $filters->inStockOnly)
            : null;

        $productsQuery = $this->productsQuery(
            $section,
            $selectedCategories,
            $selectedMaterials,
            $from,
            $to,
            $variantClause,
        );

        $total = (clone $productsQuery)->count();

        $page = (clone $productsQuery)
            ->with(['images', 'variants.color'])
            ->limit($filters->show);

        $sorting = self::SORTING[$filters->sort];
        $page->orderBy($sorting['column'], $sorting['direction'])->orderBy('id', $sorting['direction']);

        $products = $page->get();
        $shown = $products->count();

        $categoryCounts = $this->productsQuery(
            $section,
            [],
            $selectedMaterials,
            $from,
            $to,
            $variantClause,
        )
            ->groupBy('category_id')
            ->selectRaw('category_id, count(*) as total')
            ->pluck('total', 'category_id');

        $materialCounts = $this->productsQuery(
            $section,
            $selectedCategories,
            [],
            $from,
            $to,
            $variantClause,
        )
            ->join('material_product', 'material_product.product_id', '=', 'products.id')
            ->groupBy('material_product.material_id')
            ->selectRaw('material_product.material_id, count(*) as total')
            ->pluck('total', 'material_product.material_id');

        $stockCount = $this->productsQuery(
            $section,
            $selectedCategories,
            $selectedMaterials,
            $from,
            $to,
            $this->variantsOf($sizeIds, $selectedColors, true),
        )->count();

        $params = $this->queryParams(
            $filters,
            $selectedCategories,
            $selectedSizes,
            $selectedColors,
            $selectedMaterials,
            $from,
            $to,
        );

        return [
            'section' => [
                'key' => $section->value,
                'label' => $section->label(),
                'url' => $this->pageUrl($section),
            ],
            'total' => $total,
            'shown' => $shown,
            'products' => ProductCards::makeAll($products),
            'nextUrl' => $this->nextUrl($filters, $section, $params, $total, $shown),
            'clearUrl' => $this->pageUrl($section),
            'sort' => [
                'value' => $filters->sort,
                'options' => array_map(
                    fn (string $value): array => ['value' => $value, 'label' => $this->sortLabel($value)],
                    SectionFilters::SORT_OPTIONS,
                ),
            ],
            'active' => $this->activeFilters($section, $params, $sectionCategories, $selectedSizes, $colors, $selectedColors, $materials, $selectedMaterials, $from, $to, $filters->inStockOnly),
            'filters' => [
                'categories' => $this->categoriesOptions($sectionCategories, $selectedCategories, $categoryCounts),
                'sizes' => $this->sizesOptions($sizeGroups, $selectedSizes),
                'colors' => $this->colorsOptions($colors, $selectedColors),
                'materials' => $this->materialsOptions($materials, $selectedMaterials, $materialCounts),
                'price' => [
                    'min' => $sliderMin,
                    'max' => $sliderMax,
                    'from' => $from,
                    'to' => $to,
                    'step' => self::PRICE_STEP,
                ],
                'stock' => [
                    'count' => $stockCount,
                    'checked' => $filters->inStockOnly,
                ],
            ],
            'cartCount' => 0,
        ];
    }

    /**
     * La consulta de productos de la sección con los filtros ya aplicados.
     *
     * Las categorías y los materiales se aplican al producto (Y con todo lo
     * demás), el precio se aplica al producto y la talla, el color y el stock se
     * exigen sobre la misma variante activa. Sin filtros de variante, `visible()`
     * ya garantiza que el producto se vende en una variante activa, así que no se
     * añade nada más.
     *
     * @param  list<int>  $categories
     * @param  list<int>  $materials
     * @return Builder<Product>
     */
    private function productsQuery(
        StoreSection $section,
        array $categories,
        array $materials,
        ?int $from,
        ?int $to,
        ?Closure $variantClause,
    ): Builder {
        return Product::query()
            ->visible()
            ->whereHas('category', fn (Builder $query): Builder => $query
                ->inSection($section)
                ->where('is_active', true))
            ->when($categories !== [], fn (Builder $query): Builder => $query->whereIn('category_id', $categories))
            ->when($materials !== [], fn (Builder $query): Builder => $query->whereHas('materials', fn (Builder $query): Builder => $query->whereIn('materials.id', $materials)))
            ->when($from !== null, fn (Builder $query): Builder => $query->where('base_price', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('base_price', '<=', $to))
            ->when($variantClause !== null, fn (Builder $query): Builder => $query->whereHas('variants', $variantClause));
    }

    /**
     * La cláusula que pide talla, color y stock sobre la misma variante activa.
     *
     * Se le pasa a `whereHas('variants', ...)`, así que los tres se exigen sobre
     * una sola fila:una prenda con la M en rojo y la L en azul no casa con
     * «M+Azul».
     *
     * @param  list<int>  $sizeIds
     * @param  list<int>  $colorIds
     */
    private function variantsOf(array $sizeIds, array $colorIds, bool $inStockOnly): Closure
    {
        return function (Builder $variants) use ($sizeIds, $colorIds, $inStockOnly): void {
            $variants
                ->where('is_active', true)
                ->when($sizeIds !== [], fn (Builder $query): Builder => $query->whereIn('size_id', $sizeIds))
                ->when($colorIds !== [], fn (Builder $query): Builder => $query->whereIn('color_id', $colorIds))
                ->when($inStockOnly, fn (Builder $query): Builder => $query->where('stock', '>', 0));
        };
    }

    /**
     * Las categorías activas de la sección que tienen al menos una prenda
     * visible: solo lo que se puede mostrar se ofrece.
     *
     * @return Collection<int, Category>
     */
    private function sectionCategories(StoreSection $section): Collection
    {
        return Category::query()
            ->inSection($section)
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $query): Builder => $query->visible())
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los límites del deslizador de precio: el precio mínimo y máximo de las
     * prendas visibles de la sección, redondeados hacia fuera al paso solo si no
     * son ya múltiplos del paso. Un máximo de 150000 con paso 1000 da 150000,
     * y 150400 da 151000.
     *
     * Es de toda la sección a propósito: el rango no cambia según qué filtros
     * estén puestos, o mover la talla reencuadraría el rango bajo los dedos.
     *
     * @return array{0: int, 1: int}
     */
    private function priceBounds(StoreSection $section): array
    {
        $row = Product::query()
            ->visible()
            ->whereHas('category', fn (Builder $query): Builder => $query
                ->inSection($section)
                ->where('is_active', true))
            ->selectRaw('min(base_price) as lo, max(base_price) as hi')
            ->first();

        $min = (int) ($row?->lo ?? 0);
        $max = (int) ($row?->hi ?? 0);

        return [
            (int) floor($min / self::PRICE_STEP) * self::PRICE_STEP,
            (int) ceil($max / self::PRICE_STEP) * self::PRICE_STEP,
        ];
    }

    /**
     * Un límite de precio recibido, acotado al rango del deslizador y considerado
     * «movido» solo cuando se apartó del borde completo.
     *
     * Un límite que coincide con el borde se devuelve como nada, que es como la
     * vista entiende que el campo no se movió y no lo envía.
     */
    private function movedBound(?int $value, int $min, int $max, bool $from): ?int
    {
        if ($value === null) {
            return null;
        }

        $clamped = max($min, min($max, $value));

        if ($from) {
            return $clamped > $min ? $clamped : null;
        }

        return $clamped < $max ? $clamped : null;
    }

    /**
     * Los ids candidatos que la sección realmente ofrece.
     *
     * @param  list<int>  $candidates
     * @return list<int>
     */
    private function intersectIds(array $candidates, Collection $offered): array
    {
        $allowed = $offered->map(fn (int $id): int => $id)->all();

        return array_values(array_intersect($candidates, $allowed));
    }

    /**
     * Los nombres de talla que la sección ofrece, con su ortografía canónica
     * (la del grupo que viene primero en orden de catálogo).
     *
     * @param  list<string>  $candidates
     * @param  Collection<int, array{name: string, ids: list<int>}>  $groups
     * @return list<string>
     */
    private function matchSizeNames(array $candidates, Collection $groups): array
    {
        $matched = [];

        foreach ($candidates as $candidate) {
            foreach ($groups as $group) {
                if ($this->nameKey($candidate) === $this->nameKey($group['name'])) {
                    $matched[$this->nameKey($group['name'])] = $group['name'];
                    break;
                }
            }
        }

        return array_values($matched);
    }

    /**
     * Los ids de todas las filas que llevan uno de los nombres de talla elegidos.
     *
     * Una talla es un nombre por categoría, así que un «M» global puede agrupar
     * el «M» de las camisas y el «M» de los pantalones.
     *
     * @param  list<string>  $selectedSizes
     * @param  Collection<int, array{name: string, ids: list<int>}>  $groups
     * @return list<int>
     */
    private function idsOfSizeNames(array $selectedSizes, Collection $groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            if (in_array($this->nameKey($group['name']), $this->nameKeys($selectedSizes), true)) {
                $ids = [...$ids, ...$group['ids']];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function nameKeys(array $names): array
    {
        return array_map(fn (string $name): string => $this->nameKey($name), $names);
    }

    /**
     * Los parámetros canónicos de la página, tal y como los serializa la URL.
     *
     * Los arreglos se serializan planos y sin índices (categoria[]=5, no
     * categoria[0]=5 ni categoria[][0]=5). Los pasos de precio solo entran
     * cuando se movieron del borde; el orden solo entra cuando no es el que ya
     * trae una página sin él. «mostrar» no entra: los enlaces se construyen
     * aparte con el paso que corresponda.
     *
     * @param  list<int>  $categories
     * @param  list<string>  $sizes
     * @param  list<int>  $colors
     * @param  list<int>  $materials
     * @return array<string, mixed>
     */
    private function queryParams(
        SectionFilters $filters,
        array $categories,
        array $sizes,
        array $colors,
        array $materials,
        ?int $from,
        ?int $to,
    ): array {
        $params = [];

        if ($categories !== []) {
            $params['categoria[]'] = $categories;
        }

        if ($sizes !== []) {
            $params['talla[]'] = $sizes;
        }

        if ($colors !== []) {
            $params['color[]'] = $colors;
        }

        if ($materials !== []) {
            $params['material[]'] = $materials;
        }

        if ($from !== null) {
            $params['precio_min'] = $from;
        }

        if ($to !== null) {
            $params['precio_max'] = $to;
        }

        if ($filters->inStockOnly) {
            $params['stock'] = '1';
        }

        if ($filters->sort !== 'novedades') {
            $params['orden'] = $filters->sort;
        }

        return $params;
    }

    /**
     * El enlace de «Mostrar más»: la misma página con los mismos filtros y el
     * paso siguiente, hasta el tope de 120.
     *
     * @param  array<string, mixed>  $params
     */
    private function nextUrl(SectionFilters $filters, StoreSection $section, array $params, int $total, int $shown): ?string
    {
        $next = $shown + SectionFilters::SHOW_STEP;

        if ($total <= $shown || $next > SectionFilters::SHOW_MAX) {
            return null;
        }

        $params['mostrar'] = $next;

        return $this->pageUrl($section, $params);
    }

    /**
     * Los chips de los filtros activos: uno por valor elegido, con el enlace que
     * quita solo ese valor y sin «mostrar».
     *
     * @param  array<string, mixed>  $params
     * @param  Collection<int, Category>  $sectionCategories
     * @param  list<string>  $selectedSizes
     * @param  list<int>  $selectedColors
     * @param  list<int>  $selectedMaterials
     * @return list<array{label: string, removeUrl: string}>
     */
    private function activeFilters(
        StoreSection $section,
        array $params,
        Collection $sectionCategories,
        array $selectedSizes,
        Collection $colors,
        array $selectedColors,
        Collection $materials,
        array $selectedMaterials,
        ?int $from,
        ?int $to,
        bool $inStockOnly,
    ): array {
        $active = [];

        foreach ($selectedCategories = $this->selectedOf($params, 'categoria[]') as $id) {
            $active[] = [
                'label' => $sectionCategories->firstWhere('id', $id)?->name ?? (string) $id,
                'removeUrl' => $this->pageUrl($section, $this->withoutValue($params, 'categoria[]', $id)),
            ];
        }

        foreach ($selectedSizes as $name) {
            $active[] = [
                'label' => 'Talla '.$name,
                'removeUrl' => $this->pageUrl($section, $this->withoutValue($params, 'talla[]', $name)),
            ];
        }

        foreach ($selectedColors as $id) {
            $active[] = [
                'label' => $colors->firstWhere('id', $id)->name,
                'removeUrl' => $this->pageUrl($section, $this->withoutValue($params, 'color[]', $id)),
            ];
        }

        foreach ($selectedMaterials as $id) {
            $active[] = [
                'label' => $materials->firstWhere('id', $id)->name,
                'removeUrl' => $this->pageUrl($section, $this->withoutValue($params, 'material[]', $id)),
            ];
        }

        if ($inStockOnly) {
            $active[] = [
                'label' => 'Solo en stock',
                'removeUrl' => $this->pageUrl($section, $this->withoutKey($params, 'stock')),
            ];
        }

        if ($from !== null) {
            $active[] = [
                'label' => 'Desde '.$this->money($from),
                'removeUrl' => $this->pageUrl($section, $this->withoutKey($params, 'precio_min')),
            ];
        }

        if ($to !== null) {
            $active[] = [
                'label' => 'Hasta '.$this->money($to),
                'removeUrl' => $this->pageUrl($section, $this->withoutKey($params, 'precio_max')),
            ];
        }

        return $active;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<int>
     */
    private function selectedOf(array $params, string $key): array
    {
        return array_map(fn (mixed $value): int => (int) $value, $params[$key] ?? []);
    }

    /**
     * La página con un valor de una lista quitado; la lista que se queda vacía
     * desaparece entera.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withoutValue(array $params, string $key, int|string $removed): array
    {
        $params[$key] = array_values(array_filter(
            $params[$key] ?? [],
            fn (int|string $value): bool => $value !== $removed,
        ));

        if ($params[$key] === []) {
            unset($params[$key]);
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withoutKey(array $params, string $key): array
    {
        unset($params[$key]);

        return $params;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  list<int>  $selected
     * @param  Collection<int, mixed>  $counts
     * @return list<array{value: string, label: string, count: int, checked: bool}>
     */
    private function categoriesOptions(Collection $categories, array $selected, Collection $counts): array
    {
        return $categories
            ->map(fn (Category $category): array => [
                'value' => (string) $category->getKey(),
                'label' => $category->name,
                'count' => (int) ($counts[$category->getKey()] ?? 0),
                'checked' => in_array($category->getKey(), $selected, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{name: string, ids: list<int>}>  $groups
     * @param  list<string>  $selected
     * @return list<array{value: string, label: string, checked: bool}>
     */
    private function sizesOptions(Collection $groups, array $selected): array
    {
        return $groups
            ->map(fn (array $group): array => [
                'value' => $group['name'],
                'label' => $group['name'],
                'checked' => in_array($this->nameKey($group['name']), $this->nameKeys($selected), true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Color>  $colors
     * @param  list<int>  $selected
     * @return list<array{value: string, label: string, hex: string, checked: bool}>
     */
    private function colorsOptions(Collection $colors, array $selected): array
    {
        return $colors
            ->map(fn (Color $color): array => [
                'value' => (string) $color->getKey(),
                'label' => $color->name,
                'hex' => $color->hex,
                'checked' => in_array($color->getKey(), $selected, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Material>  $materials
     * @param  list<int>  $selected
     * @param  Collection<int, mixed>  $counts
     * @return list<array{value: string, label: string, count: int, checked: bool}>
     */
    private function materialsOptions(Collection $materials, array $selected, Collection $counts): array
    {
        return $materials
            ->map(fn (Material $material): array => [
                'value' => (string) $material->getKey(),
                'label' => $material->name,
                'count' => (int) ($counts[$material->getKey()] ?? 0),
                'checked' => in_array($material->getKey(), $selected, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function pageUrl(StoreSection $section, array $params = []): string
    {
        $url = $section->route();

        return $params === [] ? $url : $url.'?'.static::queryString($params);
    }

    /**
     * Los parámetros de una sección como cadena de consulta, con los arreglos
     * planos y sin índices: una categoría se escribe `categoria[]=5` y dos,
     * `categoria[]=5&categoria[]=3`, que es el formato que el propio formulario
     * emite con sus casillas repetidas.
     *
     * @param  array<string, mixed>  $params
     */
    private static function queryString(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    private function sortLabel(string $value): string
    {
        return match ($value) {
            'novedades' => 'Novedades',
            'relevancia' => 'Relevancia',
            'precio-asc' => 'Precio: menor a mayor',
            'precio-desc' => 'Precio: mayor a menor',
        };
    }

    private function money(int $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }
}
