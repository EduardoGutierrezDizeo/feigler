<?php

namespace App\Services\Storefront;

use App\Models\Product;

/**
 * La página de resultados del buscador del encabezado (/buscar).
 *
 * Es el envoltorio delgado que ata el texto buscado al motor de listado general:
 * el alcance cubre todas las secciones y lleva la restricción de texto, y el
 * motor hace el resto (filtros, conteos, chips, orden y «Mostrar más»), así que
 * esta página solo decide el título, los mensajes y el estado sin búsqueda.
 *
 * Sin un texto que buscar (`SearchTerm` nulo) la página no lista nada: devuelve
 * el estado que pide escribir qué se busca, sin filtros ni «Mostrar más». Un
 * texto válido que no encuentra nada y un texto que sí encuentra prendas pero
 * cuyos filtros las vacían se distinguen con dos mensajes distintos, que es lo
 * que la vista necesita para hablarle claro a quien buscó.
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
 *         sections: null,
 *         categories: list<array{value: string, label: string, count: int, checked: bool}>,
 *         sizes: list<array{value: string, label: string, checked: bool}>,
 *         colors: list<array{value: string, label: string, hex: string, checked: bool}>,
 *         materials: list<array{value: string, label: string, count: int, checked: bool}>,
 *         price: array{min: int, max: int, from: int|null, to: int|null, step: int},
 *         stock: array{count: int, checked: bool}
 *     },
 *     cartCount: int,
 *     subtitle: string,
 *     searchQuery: string|null,
 *     searchPrompt: bool,
 *     emptyMessage?: string,
 *     emptyLink?: array{url: string, label: string}
 * }
 */
class SearchPage
{
    public function __construct(private ListingPage $listing) {}

    /**
     * Los datos de la página de resultados, con la forma que
     * resources/views/storefront/section.blade.php pide en su comentario inicial
     * más las claves propias del buscador.
     *
     * @return array<string, mixed>
     */
    public function for(SectionFilters $filters): array
    {
        $scope = ListingScope::all('storefront.search')->searching($filters->search);

        if ($filters->search === null) {
            return $this->prompt($scope);
        }

        $text = $filters->search->text;
        $page = $this->listing->for($scope, $filters);

        $page['section'] = [
            'key' => '',
            'label' => 'Resultados para “'.$text.'”',
            'url' => $page['section']['url'],
        ];

        if ($page['total'] === 0 && ! $scope->applyTo(Product::query())->exists()) {
            $page['emptyMessage'] = 'No encontramos prendas para “'.$text.'”.';
            $page['emptyLink'] = ['url' => route('storefront.tienda'), 'label' => 'Ver toda la tienda'];
        }

        return $page + [
            'subtitle' => 'Búsqueda',
            'searchQuery' => $text,
            'searchPrompt' => false,
        ];
    }

    /**
     * El estado sin texto que buscar: la página responde 200 y solo dice que se
     * escriba qué prenda se busca. Las listas del contrato van vacías para que la
     * vista no pinte filtros ni nada que parezca un resultado.
     *
     * @return array<string, mixed>
     */
    private function prompt(ListingScope $scope): array
    {
        return [
            'section' => ['key' => '', 'label' => 'Buscar', 'url' => $scope->url()],
            'total' => 0,
            'shown' => 0,
            'products' => [],
            'nextUrl' => null,
            'clearUrl' => $scope->url(),
            'sort' => ['value' => 'novedades', 'options' => []],
            'active' => [],
            'filters' => [
                'sections' => null,
                'categories' => [],
                'sizes' => [],
                'colors' => [],
                'materials' => [],
                'price' => ['min' => 0, 'max' => 0, 'from' => null, 'to' => null, 'step' => 1000],
                'stock' => ['count' => 0, 'checked' => false],
            ],
            'cartCount' => app(CartService::class)->currentCount(),
            'subtitle' => 'Búsqueda',
            'searchQuery' => null,
            'searchPrompt' => true,
            'emptyMessage' => 'Escribe qué prenda buscas.',
        ];
    }
}
