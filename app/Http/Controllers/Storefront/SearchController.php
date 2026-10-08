<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\SearchPage;
use App\Services\Storefront\SectionFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El buscador del encabezado: /buscar responde la página de resultados con el
 * texto de `q`. El saneo y el estado sin búsqueda viven en SearchPage; aquí solo
 * se ata la petición a la página.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, SearchPage $search): View
    {
        return view('storefront.section', $search->for(SectionFilters::fromRequest($request)));
    }
}
