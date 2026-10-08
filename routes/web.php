<?php

use App\Enums\StoreSection;
use App\Http\Controllers\ProfileController;
use App\Services\Storefront\HomePage;
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

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/staff', function () {
    $modules = [
        'vendedor' => 'Ventas',
        'bodega' => 'Inventario',
        'contador' => 'Reportes',
    ];

    $role = auth()->user()->getRoleNames()->first();

    return view('staff.placeholder', ['module' => $modules[$role] ?? 'Portal de personal']);
})->middleware(['auth', 'role:vendedor|bodega|contador'])->name('staff.placeholder');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/admin.php';

require __DIR__.'/auth.php';

if (app()->environment('local')) {
    require __DIR__.'/storefront-preview.php';
}
