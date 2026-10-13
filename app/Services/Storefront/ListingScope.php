<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use App\Models\Product;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Qué conjunto de prendas lista una página y desde qué URL se enlazan sus
 * enlaces.
 *
 * Es un objeto de valor inmutable. Las páginas de sección de siempre (/hombre,
 * /mujer y /ninos) cubren una sección; las páginas que listan prendas de varias
 * (Novedades, Tienda, Buscador) cubren más de una, y las dos se describen con
 * lo mismo: el alcance acota la base sobre la que el motor de listado aplica los
 * filtros, y da la URL sobre la que la página construye la suya.
 *
 * El conjunto base es el que una sección siempre tuvo: prendas visibles — ni
 * apagadas ni sin ninguna variante activa — cuya categoría esté activa y sea de
 * una de las secciones del alcance. Un alcance puede pedir además, con
 * `onlyNew()`, que solo cubra prendas nuevas, que es la regla de la insignia
 * «Nuevo» leída desde Product. Aplicarlo está escrito una sola vez en
 * `applyTo()`, para que la consulta de prendas, el límite del deslizador y las
 * listas de opciones del catálogo no puedan divergir sobre qué es una prenda
 * del alcance. El resto de las reglas (los filtros, los conteos, el orden) son
 * del motor, no del alcance.
 *
 * La URL base es el nombre de una ruta nombrada con sus parámetros: `url()` es
 * lo único que sabe construir enlaces, así que ninguna parte del motor tiene que
 * saber qué ruta sirve la página.
 */
class ListingScope
{
    /**
     * @param  list<StoreSection>  $sections  Las secciones que cubre, sin repetir y al menos una.
     * @param  array<string, mixed>  $routeParams  Los parámetros de la ruta nombrada.
     * @param  bool  $newOnly  Si solo cubre prendas nuevas (la regla de la insignia «Nuevo»).
     * @param  SearchTerm|null  $search  El texto buscado, cuando la página es un buscador.
     */
    private function __construct(
        private readonly array $sections,
        private readonly string $routeName,
        private readonly array $routeParams = [],
        private readonly bool $newOnly = false,
        private readonly ?SearchTerm $search = null,
    ) {}

    /**
     * El alcance de una sola sección, que se enlaza en su propia ruta nombrada.
     */
    public static function section(StoreSection $section): self
    {
        return new self([$section], 'storefront.section.'.$section->value);
    }

    /**
     * El alcance que cubre unas secciones dadas, se enlaza en la ruta nombrada
     * que la página tenga.
     *
     * @param  list<StoreSection>  $sections
     */
    public static function covering(array $sections, string $routeName, array $routeParams = []): self
    {
        $unique = [];

        foreach ($sections as $section) {
            $unique[$section->value] = $section;
        }

        if ($unique === []) {
            throw new InvalidArgumentException('Un alcance debe cubrir al menos una sección.');
        }

        return new self(array_values($unique), $routeName, $routeParams);
    }

    /**
     * El alcance de toda la tienda, sección por sección.
     */
    public static function all(string $routeName, array $routeParams = []): self
    {
        return self::covering(StoreSection::cases(), $routeName, $routeParams);
    }

    /**
     * El mismo alcance restringido a las prendas nuevas: las creadas hace
     * Product::NUEVO_DIAS días o menos, que es la misma regla de la insignia
     * «Nuevo» de las tarjetas (la fuente única vive en Product).
     */
    public function onlyNew(): self
    {
        return new self($this->sections, $this->routeName, $this->routeParams, true);
    }

    /**
     * El mismo alcance restringido a un texto buscado: el nombre del producto, su
     * referencia (`PREFIJO-NNN`) o el nombre de su categoría tienen que contener
     * cada palabra. Un texto nulo deja el alcance como estaba.
     */
    public function searching(?SearchTerm $term): self
    {
        return new self($this->sections, $this->routeName, $this->routeParams, $this->newOnly, $term);
    }

    /**
     * Las secciones que cubre, al menos una.
     *
     * @return list<StoreSection>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * Los valores de esas secciones, que es como la categoría las guarda.
     *
     * @return list<string>
     */
    public function sectionValues(): array
    {
        return array_map(fn (StoreSection $section): string => $section->value, $this->sections);
    }

    /**
     * La pestaña de la portada que la página señala: la de su sección cuando
     * cubre una sola, y ninguna cuando cubre varias, porque no hay una pestaña
     * que señalar.
     */
    public function key(): string
    {
        return count($this->sections) === 1 ? $this->sections[0]->value : '';
    }

    /**
     * El título de la página: la de su sección cuando cubre una sola, y todas
     * separadas por « · » cuando cubre varias.
     */
    public function label(): string
    {
        if (count($this->sections) === 1) {
            return $this->sections[0]->label();
        }

        return implode(' · ', array_map(
            fn (StoreSection $section): string => $section->label(),
            $this->sections,
        ));
    }

    /**
     * La restricción del conjunto base sobre una consulta de prendas: visibles,
     * de categoría activa, de una de las secciones del alcance, cuando el
     * alcance lo pide nuevas (creadas desde Product::newCutoff()) y, cuando hay
     * un texto buscado, las que coinciden con él.
     *
     * `products.created_at` va calificada porque la consulta de conteos por
     * sección une la tabla categories, que también tiene created_at.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function applyTo(Builder $products): Builder
    {
        $products = $products
            ->visible()
            ->when($this->newOnly, fn (Builder $query): Builder => $query->where('products.created_at', '>=', Product::newCutoff()))
            ->whereHas('category', fn (Builder $categories): Builder => $categories
                ->whereIn('section', $this->sectionValues())
                ->where('is_active', true));

        return $this->search === null ? $products : $this->applySearch($products);
    }

    /**
     * La restricción del texto buscado: cada palabra tiene que aparecer en al
     * menos uno de los tres campos (nombre, referencia o nombre de la categoría),
     * y todas las palabras tienen que aparecer.
     *
     * Los comodines de `LIKE` van escapados con el ayudante compartido, así que
     * un `%` o un `_` escritos se buscan como los caracteres que son. El nombre
     * de la categoría se lee por la relación, en su propia subconsulta.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    private function applySearch(Builder $products): Builder
    {
        $escape = LikePattern::escapeCharacter();

        foreach ($this->search->words as $word) {
            $pattern = LikePattern::contains($word);

            $products->where(function (Builder $query) use ($pattern, $escape): void {
                $query
                    ->whereRaw('LOWER(products.name) LIKE ? ESCAPE ?', [$pattern, $escape])
                    ->orWhereRaw('LOWER(products.reference) LIKE ? ESCAPE ?', [$pattern, $escape])
                    ->orWhereHas('category', fn (Builder $categories): Builder => $categories->whereRaw('LOWER(categories.name) LIKE ? ESCAPE ?', [$pattern, $escape]));
            });
        }

        return $products;
    }

    /**
     * La página con los parámetros ya puestos: la URL base más su cadena de
     * consulta.
     *
     * @param  array<string, mixed>  $params
     */
    public function url(array $params = []): string
    {
        $url = route($this->routeName, $this->routeParams);

        if ($params === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').self::queryString($params);
    }

    /**
     * Los parámetros de una página como cadena de consulta, con los arreglos
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
}
