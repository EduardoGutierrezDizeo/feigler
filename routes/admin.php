<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ProductDetails;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Users;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::livewire('/admin/dashboard', Dashboard::class)->name('admin.dashboard');
    Route::livewire('/admin/product-details', ProductDetails\Index::class)->name('admin.product-details.index');
    Route::livewire('/admin/products', Products\Index::class)->name('admin.products.index');
    Route::livewire('/admin/users', Users\Index::class)->name('admin.users.index');

    // La dirección anterior se conserva como puerta de entrada, no como panel: el
    // bloque de categorías es una de las cuatro pestañas de «Detalles de productos» y
    // los marcadores, los chats y los correos con la URL vieja siguen llegando aquí.
    // Sin nombre a propósito, para que ninguna parte del panel vuelva a usarla como
    // enlace: `route('admin.categories.index')` no volvería a compilar.
    Route::redirect('/admin/categories', '/admin/product-details?tab=categorias');
});
