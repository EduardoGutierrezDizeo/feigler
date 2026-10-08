<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;
use Illuminate\Http\Request;

/**
 * Los filtros que la URL de una sección puede llevar, ya saneados.
 *
 * Es un objeto de valor: no consulta la base de datos. El saneo aquí es puramente
 * sintáctico — solo números para los ids, valores conocidos para el orden, un
 * rango 12..120 para «mostrar» — y el chequeo de que un valor pertenezca a la
 * sección (un id de otra sección se ignora, una talla que no existe se ignora)
 * lo hace SectionPage al cruzar estos valores con las opciones que la sección
 * ofrece, porque ese cruce tiene a mano la base de datos.
 *
 * Un filtro que llega mal escrito no es un error: la página se sirve igual sin
 * ese valor, que es lo que la URL de una tienda compartida puede legítimamente
 * traer.
 */
class SectionFilters
{
    /**
     * Las opciones de orden, en el orden en que se ofrecen.
     *
     * @var list<string>
     */
    public const SORT_OPTIONS = ['novedades', 'relevancia', 'precio-asc', 'precio-desc'];

    /**
     * El paso con el que «Mostrar más» va descubriendo la lista.
     */
    public const SHOW_STEP = 12;

    /**
     * El tope inferior de una lista pedida con «mostrar».
     */
    public const SHOW_MIN = 12;

    /**
     * El tope superior de una lista pedida con «mostrar»: la lista nunca enseña
     * más de 120 prendas, y una página con más no crece más.
     */
    public const SHOW_MAX = 120;

    /**
     * @param  list<int>  $categories  Ids de categorías tal y como llegaron, sin validar contra la sección.
     * @param  list<string>  $sizes  Nombres de talla tal y como llegaron.
     * @param  list<int>  $colors  Ids de colores tal y como llegaron.
     * @param  list<int>  $materials  Ids de materiales tal y como llegaron.
     */
    public function __construct(
        public readonly StoreSection $section,
        public readonly array $categories = [],
        public readonly array $sizes = [],
        public readonly array $colors = [],
        public readonly array $materials = [],
        public readonly ?int $priceFrom = null,
        public readonly ?int $priceTo = null,
        public readonly bool $inStockOnly = false,
        public readonly string $sort = 'novedades',
        public readonly int $show = SectionFilters::SHOW_STEP,
    ) {}

    public static function fromRequest(Request $request, StoreSection $section): self
    {
        $from = self::priceBound($request->input('precio_min'));
        $to = self::priceBound($request->input('precio_max'));

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            section: $section,
            categories: self::positiveInts($request->input('categoria')),
            sizes: self::sizeNames($request->input('talla')),
            colors: self::positiveInts($request->input('color')),
            materials: self::positiveInts($request->input('material')),
            priceFrom: $from,
            priceTo: $to,
            inStockOnly: (string) $request->input('stock') === '1',
            sort: self::canonicalSort($request->input('orden', 'novedades')),
            show: self::boundedShow($request->input('mostrar')),
        );
    }

    /**
     * La posición de un orden pedido: el nombre si es de los que se ofrecen, o
     * «novedades» si es un valor que no existe.
     */
    private static function canonicalSort(mixed $wanted): string
    {
        return in_array($wanted, self::SORT_OPTIONS, true) ? $wanted : 'novedades';
    }

    /**
     * Un valor de lista que debe ser un id: solo se quedan los números enteros
     * positivos escritos, en el orden en que llegaron y sin repetir. «abc» y
     * «0» se caen en el mismo paso que un valor ausente.
     *
     * @return list<int>
     */
    private static function positiveInts(mixed $values): array
    {
        $items = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_filter(array_map(
            static function (mixed $value): ?int {
                if (! is_numeric($value) || (int) $value < 1) {
                    return null;
                }

                return (int) $value;
            },
            $items,
        ))));
    }

    /**
     * El nombre de una talla tal y como se escribió, recortado. Una talla que no
     * existe en la sección la descarta SectionPage al cruzarla con las que la
     * sección ofrece.
     *
     * @return list<string>
     */
    private static function sizeNames(mixed $values): array
    {
        $items = is_array($values) ? $values : [$values];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== ''
                ? trim((string) $value)
                : null,
            $items,
        ))));
    }

    /**
     * Un límite de precio: un número no negativo o nada, para que «abc» y «-5»
     * se lean igual que un campo vacío.
     */
    private static function priceBound(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * El límite de una lista pedida con «mostrar», corregido al rango 12..120 en
     * vez de responder con un error: «mostrar=7» enseña 12 y «mostrar=1000»
     * enseña 120.
     */
    private static function boundedShow(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::SHOW_STEP;
        }

        return max(self::SHOW_MIN, min((int) $value, self::SHOW_MAX));
    }
}
