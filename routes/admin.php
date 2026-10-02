<?php

use App\Livewire\Admin\Categories;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Products;
use App\Livewire\Admin\Users;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::livewire('/admin/dashboard', Dashboard::class)->name('admin.dashboard');
    Route::livewire('/admin/categories', Categories\Index::class)->name('admin.categories.index');
    Route::livewire('/admin/products', Products\Index::class)->name('admin.products.index');
    Route::livewire('/admin/users', Users\Index::class)->name('admin.users.index');
});
