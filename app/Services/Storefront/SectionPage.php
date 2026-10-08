<?php

namespace App\Services\Storefront;

use App\Enums\StoreSection;

/**
 * La página de una sección (/hombre, /mujer, /ninos): el envoltorio delgado que
 * ata una sección al motor de listado general.
 *
 * El motor vive en ListingPage y trabaja sobre un ListingScope, que es el que
 * dice qué prendas lista la página y desde qué URL se enlazan; aquí solo se
 * traduce una sección en el alcance de esa sección, para que las rutas, el
 * inicio y los tests de siempre no cambien.
 */
class SectionPage
{
    public function __construct(private ListingPage $listing) {}

    /**
     * Los datos de la página de una sección, con exactamente la forma que
     * resources/views/storefront/section.blade.php pide en su comentario inicial
     * (el contrato manda).
     *
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
        return $this->listing->for(ListingScope::section($section), $filters);
    }
}
