<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (app()->environment('local')) {
        // En local, si hay datos reales, mostrar storefront.home; si no hay, mantener vista previa disponible via /_vista
        // pero queremos raíz con datos reales cuando existan. Por ahora, usar servicio HomePage.
        return view('storefront.home', (new \App\Services\Storefront\HomePage())->home());
    }

    return view('welcome');
});

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
