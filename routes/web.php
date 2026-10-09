<?php

use App\Enums\StoreSection;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\SearchController;
use App\Services\Storefront\HomePage;
use App\Services\Storefront\ListingPage;
use App\Services\Storefront\ListingScope;
use App\Services\Storefront\ProductPage;
use App\Services\Storefront\SectionFilters;
use App\Services\Storefront\SectionPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (app()->environment('local')) {
        // En local, si hay datos reales, mostrar storefront.home; si no hay, mantener vista previa disponible via /_vista
        // pero queremos raíz con datos reales cuando existan. Por ahora, usar servicio HomePage.
        return view('storefront.home', (new HomePage)->home());
    }

    return view('welcome');
});

// La ficha pública vive en el slug del producto: es la dirección inmutable.
Route::get('/producto/{slug}', function (string $slug) {
    return view('storefront.product', (new ProductPage)->forSlug($slug));
})->name('storefront.product');

// Una ruta por sección, con el nombre de su pestaña en la portada. Un valor que
// no es una sección no tiene ruta, así que devuelve 404 él solo.
foreach (StoreSection::cases() as $section) {
    Route::get('/'.$section->value, function (Request $request) use ($section) {
        return view('storefront.section', app(SectionPage::class)->for($section, SectionFilters::fromRequest($request, $section)));
    })->name('storefront.section.'.$section->value);
}

// Novedades: toda la tienda, pero solo las prendas creadas en los últimos
// Product::NUEVO_DIAS días, con la misma regla que la insignia «Nuevo» (N2).
Route::get('/novedades', function (Request $request) {
    $page = app(ListingPage::class)->for(
        ListingScope::all('storefront.novedades')->onlyNew(),
        SectionFilters::fromRequest($request),
    );

    $page['section'] = ['key' => 'novedades', 'label' => 'Novedades', 'url' => $page['section']['url']];

    return view('storefront.section', $page + [
        'subtitle' => 'Últimos 30 días',
        'emptyMessage' => $page['total'] === 0 && $page['active'] === []
            ? 'Aún no hay novedades. Vuelve pronto.'
            : null,
    ]);
})->name('storefront.novedades');

// Tienda: las prendas visibles de las tres secciones, con la misma página y los
// mismos filtros que una sección.
Route::get('/tienda', function (Request $request) {
    $page = app(ListingPage::class)->for(
        ListingScope::all('storefront.tienda'),
        SectionFilters::fromRequest($request),
    );

    $page['section'] = ['key' => 'tienda', 'label' => 'Tienda', 'url' => $page['section']['url']];

    return view('storefront.section', $page + [
        'subtitle' => 'Todas las secciones',
    ]);
})->name('storefront.tienda');

// Buscador del encabezado: el texto de `q` busca por nombre, referencia o
// categoría en todas las secciones, con la misma página que la tienda. Sin
// texto que buscar responde 200 y pide escribirlo.
Route::get('/buscar', SearchController::class)->name('storefront.search');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'redirect.customer', 'verified'])->name('dashboard');

// Mi cuenta: la página del cliente con sus pestañas. El personal y los
// invitados no entran. La verificación del correo todavía no bloquea nada.
Route::get('/cuenta', [AccountController::class, 'index'])
    ->middleware(['auth', 'role:cliente'])
    ->name('account.index');

// El cliente solo edita nombre, apellido y teléfono; el correo y la cuenta los
// gestiona la tienda, así que el servidor ignora cualquier otro campo.
Route::patch('/cuenta/perfil', [AccountController::class, 'updateProfile'])
    ->middleware(['auth', 'role:cliente'])
    ->name('account.profile.update');

Route::get('/staff', function () {
    $modules = [
        'vendedor' => 'Ventas',
        'bodega' => 'Inventario',
        'contador' => 'Reportes',
    ];

    $role = auth()->user()->getRoleNames()->first();

    return view('staff.placeholder', ['module' => $modules[$role] ?? 'Portal de personal']);
})->middleware(['auth', 'role:vendedor|bodega|contador'])->name('staff.placeholder');

// /profile queda solo para el personal: un cliente no lee (va a Mi cuenta) ni
// muta (403), porque cambiar el correo o borrar la cuenta son decisiones de la
// tienda.
Route::middleware(['auth', 'profile.staff'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/admin.php';

require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/storefront-preview.php';
}
