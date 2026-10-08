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
 * El motor de listado: los datos reales de una página que lista prendas bajo un
 * ListingScope, con exactamente la forma que
 * resources/views/storefront/section.blade.php pide en su comentario inicial
 * (el contrato manda).
 *
 * El alcance decide de dónde salen las prendas y desde dónde se enlaza la
 * página; las reglas de los filtros son las mismas para una sección que para
 * varias: talla, color y stock se exigen sobre la misma variante activa (M+Azul
 * no casa una variante M-Rojo con una L-Azul), las familias distintas se suman
 * (Y) y los valores de una misma familia se alternan (O). Cada conteo que la
 * vista enseña (categoría, material, stock) y el total salen de una sola consulta
 * agrupada, así que el número de consultas de la página no crece según cuántas
 * opciones o prendas tenga el alcance.
 *
 * Un valor que no pertenece al alcance se ignora: se cruza aquí con las opciones
 * que el alcance realmente ofrece, que es el mismo saneo que deja una URL rota
 * servirse igual de bien.
 */
class ListingPage
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
     *         sections: list<array{value: string, label: string, count: int, checked: bool}>|null,
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
    public function for(ListingScope $scope, SectionFilters $filters): array
    {
        $scopeCategories = $this->scopeCategories($scope);
        $sizeGroups = $this->catalog->sizeNamesForFilter($scope);
        $colors = $this->catalog->colorsForFilter($scope);
        $materials = $this->catalog->materialsForFilter($scope);

        $coversMany = count($scope->sections()) > 1;

        // El texto buscado, cuando lo hay, viaja en todas las URLs que la página
        // genera (la canónica, el limpiar, los chips y el «Mostrar más»): quitar
        // los demás filtros no debe perder la búsqueda.
        $searchParams = $this->searchParams($filters);

        // La familia de sección solo existe en páginas de varias secciones: en
        // una de sola, el parámetro seccion[] se ignora entero (N3).
        $selectedSections = $coversMany
            ? $this->intersectSections($filters->sections, $scope->sectionValues())
            : [];

        $categoryLabels = $coversMany
            ? $this->disambiguatedCategoryLabels($scopeCategories)
            : $scopeCategories->mapWithKeys(fn (Category $category): array => [$category->getKey() => $category->name])->all();

        $selectedCategories = $this->intersectIds($filters->categories, $scopeCategories->pluck('id'));
        $selectedColors = $this->intersectIds($filters->colors, $colors->pluck('id'));
        $selectedMaterials = $this->intersectIds($filters->materials, $materials->pluck('id'));
        $selectedSizes = $this->matchSizeNames($filters->sizes, $sizeGroups);
        $sizeIds = $this->idsOfSizeNames($selectedSizes, $sizeGroups);

        [$sliderMin, $sliderMax] = $this->priceBounds($scope);

        $from = $this->movedBound($filters->priceFrom, $sliderMin, $sliderMax, from: true);
        $to = $this->movedBound($filters->priceTo, $sliderMin, $sliderMax, from: false);

        $variantClause = ($sizeIds !== [] || $selectedColors !== [] || $filters->inStockOnly)
            ? $this->variantsOf($sizeIds, $selectedColors, $filters->inStockOnly)
            : null;

        $productsQuery = $this->productsQuery(
            $scope,
            $selectedSections,
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
            $scope,
            $selectedSections,
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
            $scope,
            $selectedSections,
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

        // Los conteos de la familia de sección ignoran su propia familia y
        // aplican las demás, como cualquier otra (D4): una sola consulta
        // agrupada, y solo cuando el alcance cubre varias secciones.
        $sectionCounts = $coversMany
            ? $this->productsQuery($scope, [], $selectedCategories, $selectedMaterials, $from, $to, $variantClause)
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->groupBy('categories.section')
                ->selectRaw('categories.section as section, count(*) as total')
                ->pluck('total', 'section')
            : new Collection;

        $stockCount = $this->productsQuery(
            $scope,
            $selectedSections,
            $selectedCategories,
            $selectedMaterials,
            $from,
            $to,
            $this->variantsOf($sizeIds, $selectedColors, true),
        )->count();

        $params = $this->queryParams(
            $filters,
            $selectedSections,
            $selectedCategories,
            $selectedSizes,
            $selectedColors,
            $selectedMaterials,
            $from,
            $to,
        );

        return [
            'section' => [
                'key' => $scope->key(),
                'label' => $scope->label(),
                'url' => $scope->url($searchParams),
            ],
            'total' => $total,
            'shown' => $shown,
            'products' => ProductCards::makeAll($products),
            'nextUrl' => $this->nextUrl($filters, $scope, $params, $total, $shown),
            'clearUrl' => $scope->url($searchParams),
            'sort' => [
                'value' => $filters->sort,
                'options' => array_map(
                    fn (string $value): array => ['value' => $value, 'label' => $this->sortLabel($value)],
                    SectionFilters::SORT_OPTIONS,
                ),
            ],
            'active' => $this->activeFilters($scope, $params, $filters->search, $selectedSections, $categoryLabels, $selectedSizes, $colors, $selectedColors, $materials, $selectedMaterials, $from, $to, $filters->inStockOnly),
            'filters' => [
                'sections' => $coversMany
                    ? $this->sectionsOptions($scope->sections(), $selectedSections, $sectionCounts)
                    : null,
                'categories' => $this->categoriesOptions($scopeCategories, $categoryLabels, $selectedCategories, $categoryCounts),
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
     * La consulta de prendas del alcance con los filtros ya aplicados.
     *
     * El conjunto base lo aplica el propio alcance (visibles, de categoría
     * activa, de sus secciones y, si el alcance lo pide, nuevas); encima, la
     * familia de sección se exige sobre la sección de la categoría, y las
     * categorías y los materiales se aplican al producto (Y con todo lo demás).
     * El precio se aplica al producto y la talla, el color y el stock se exigen
     * sobre la misma variante activa. Sin filtros de variante, `visible()` ya
     * garantiza que el producto se vende en una variante activa, así que no se
     * añade nada más.
     *
     * @param  list<string>  $sections  Valores de sección de la familia seccion[].
     * @param  list<int>  $categories
     * @param  list<int>  $materials
     * @return Builder<Product>
     */
    private function productsQuery(
        ListingScope $scope,
        array $sections,
        array $categories,
        array $materials,
        ?int $from,
        ?int $to,
        ?Closure $variantClause,
    ): Builder {
        return $scope->applyTo(Product::query())
            ->when($sections !== [], fn (Builder $query): Builder => $query->whereHas('category', fn (Builder $categoryQuery): Builder => $categoryQuery->whereIn('section', $sections)))
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
     * Las categorías activas de las secciones del alcance que tienen al menos
     * una prenda del conjunto base: solo lo que se puede mostrar se ofrece.
     *
     * El cruce con «al menos una prenda» es el propio `applyTo()` del alcance,
     * así que una categoría sin prendas nuevas desaparece de Novedades sin que
     * esta lista tenga una segunda copia de la regla.
     *
     * @return Collection<int, Category>
     */
    private function scopeCategories(ListingScope $scope): Collection
    {
        return Category::query()
            ->whereIn('section', $scope->sectionValues())
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $query): Builder => $scope->applyTo($query))
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Los límites del deslizador de precio: el precio mínimo y máximo de las
     * prendas visibles del alcance, redondeados hacia fuera al paso solo si no
     * son ya múltiplos del paso. Un máximo de 150000 con paso 1000 da 150000,
     * y 150400 da 151000.
     *
     * Es de todo el alcance a propósito: el rango no cambia según qué filtros
     * estén puestos, o mover la talla reencuadraría el rango bajo los dedos.
     *
     * @return array{0: int, 1: int}
     */
    private function priceBounds(ListingScope $scope): array
    {
        $row = $scope->applyTo(Product::query())
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
     * Los ids candidatos que el alcance realmente ofrece.
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
     * Los valores de las secciones pedidas que el alcance cubre, en el orden en
     * que llegaron: una sección fuera del alcance se ignora igual que un id de
     * categoría de otra sección.
     *
     * @param  list<StoreSection>  $candidates
     * @param  list<string>  $scopeValues
     * @return list<string>
     */
    private function intersectSections(array $candidates, array $scopeValues): array
    {
        $values = array_map(fn (StoreSection $section): string => $section->value, $candidates);

        return array_values(array_filter($values, fn (string $value): bool => in_array($value, $scopeValues, true)));
    }

    /**
     * Los nombres de las categorías con la sección detrás solo a los que se
     * repiten en más de una (N4): en una página de varias secciones «Camisas»
     * de Hombre y «Camisas» de Mujer no pueden llamarse igual, y las que no se
     * confunden se quedan con su nombre tal cual.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, string>
     */
    private function disambiguatedCategoryLabels(Collection $categories): array
    {
        $repeated = $categories->countBy(fn (Category $category): string => $category->name)
            ->filter(fn (int $times): bool => $times > 1)
            ->keys();

        return $categories->mapWithKeys(function (Category $category) use ($repeated): array {
            $label = $repeated->contains($category->name)
                ? $category->name.' · '.$category->section->label()
                : $category->name;

            return [$category->getKey() => $label];
        })->all();
    }

    /**
     * Los nombres de talla que el alcance ofrece, con su ortografía canónica
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
     * El texto buscado como parámetro de la URL, cuando lo hay: es lo primero
     * que llevan todas las URLs de la página del buscador.
     *
     * @return array<string, mixed>
     */
    private function searchParams(SectionFilters $filters): array
    {
        return $filters->search !== null ? ['q' => $filters->search->text] : [];
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
     * @param  list<string>  $sections
     * @param  list<int>  $categories
     * @param  list<string>  $sizes
     * @param  list<int>  $colors
     * @param  list<int>  $materials
     * @return array<string, mixed>
     */
    private function queryParams(
        SectionFilters $filters,
        array $sections,
        array $categories,
        array $sizes,
        array $colors,
        array $materials,
        ?int $from,
        ?int $to,
    ): array {
        $params = $this->searchParams($filters);

        if ($sections !== []) {
            $params['seccion[]'] = $sections;
        }

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
    private function nextUrl(SectionFilters $filters, ListingScope $scope, array $params, int $total, int $shown): ?string
    {
        $next = $shown + SectionFilters::SHOW_STEP;

        if ($total <= $shown || $next > SectionFilters::SHOW_MAX) {
            return null;
        }

        $params['mostrar'] = $next;

        return $scope->url($params);
    }

    /**
     * Los chips de los filtros activos: uno por valor elegido, con el enlace que
     * quita solo ese valor y sin «mostrar». La familia de sección abre la fila,
     * como la primera de la lista de filtros, y solo aparece en páginas de
     * varias secciones.
     *
     * @param  array<string, mixed>  $params
     * @param  SearchTerm|null  $search  El texto buscado, cuando lo hay.
     * @param  list<string>  $sections  Valores de sección ya saneados.
     * @param  array<int, string>  $categoryLabels  Id de categoría => etiqueta ya desambiguada.
     * @param  list<string>  $selectedSizes
     * @param  list<int>  $selectedColors
     * @param  list<int>  $selectedMaterials
     * @return list<array{label: string, removeUrl: string}>
     */
    private function activeFilters(
        ListingScope $scope,
        array $params,
        ?SearchTerm $search,
        array $sections,
        array $categoryLabels,
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

        // El texto buscado va primero y su enlace lo quita entero: sin texto la
        // página del buscador vuelve a su estado de «escribe qué prenda buscas».
        if ($search !== null) {
            $active[] = [
                'label' => $search->text,
                'removeUrl' => $scope->url(),
            ];
        }

        foreach ($sections as $value) {
            $active[] = [
                'label' => StoreSection::from($value)->label(),
                'removeUrl' => $scope->url($this->withoutValue($params, 'seccion[]', $value)),
            ];
        }

        foreach ($selectedCategories = $this->selectedOf($params, 'categoria[]') as $id) {
            $active[] = [
                'label' => $categoryLabels[$id] ?? (string) $id,
                'removeUrl' => $scope->url($this->withoutValue($params, 'categoria[]', $id)),
            ];
        }

        foreach ($selectedSizes as $name) {
            $active[] = [
                'label' => 'Talla '.$name,
                'removeUrl' => $scope->url($this->withoutValue($params, 'talla[]', $name)),
            ];
        }

        foreach ($selectedColors as $id) {
            $active[] = [
                'label' => $colors->firstWhere('id', $id)->name,
                'removeUrl' => $scope->url($this->withoutValue($params, 'color[]', $id)),
            ];
        }

        foreach ($selectedMaterials as $id) {
            $active[] = [
                'label' => $materials->firstWhere('id', $id)->name,
                'removeUrl' => $scope->url($this->withoutValue($params, 'material[]', $id)),
            ];
        }

        if ($inStockOnly) {
            $active[] = [
                'label' => 'Solo en stock',
                'removeUrl' => $scope->url($this->withoutKey($params, 'stock')),
            ];
        }

        if ($from !== null) {
            $active[] = [
                'label' => 'Desde '.$this->money($from),
                'removeUrl' => $scope->url($this->withoutKey($params, 'precio_min')),
            ];
        }

        if ($to !== null) {
            $active[] = [
                'label' => 'Hasta '.$this->money($to),
                'removeUrl' => $scope->url($this->withoutKey($params, 'precio_max')),
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
     * @param  array<int, string>  $labels  Id de categoría => etiqueta ya desambiguada (N4).
     * @param  list<int>  $selected
     * @param  Collection<int, mixed>  $counts
     * @return list<array{value: string, label: string, count: int, checked: bool}>
     */
    private function categoriesOptions(Collection $categories, array $labels, array $selected, Collection $counts): array
    {
        return $categories
            ->map(fn (Category $category): array => [
                'value' => (string) $category->getKey(),
                'label' => $labels[$category->getKey()] ?? $category->name,
                'count' => (int) ($counts[$category->getKey()] ?? 0),
                'checked' => in_array($category->getKey(), $selected, true),
            ])
            ->values()
            ->all();
    }

    /**
     * Las opciones de la familia de sección: el conteo de cada una (con el mismo
     * planteamiento que el resto — ignora su propia familia —) y la marca de las
     * elegidas.
     *
     * @param  list<StoreSection>  $sections
     * @param  list<string>  $selected  Valores de sección ya saneados.
     * @param  Collection<int, int|string>  $counts  Conteos por valor de sección.
     * @return list<array{value: string, label: string, count: int, checked: bool}>
     */
    private function sectionsOptions(array $sections, array $selected, Collection $counts): array
    {
        return array_map(fn (StoreSection $section): array => [
            'value' => $section->value,
            'label' => $section->label(),
            'count' => (int) ($counts[$section->value] ?? 0),
            'checked' => in_array($section->value, $selected, true),
        ], $sections);
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
