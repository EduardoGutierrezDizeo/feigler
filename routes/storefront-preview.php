<?php

use App\Support\StorefrontPreview;
use Illuminate\Support\Facades\Route;

/*
 * Vista previa de las tres páginas maquetadas de la tienda, con datos falsos.
 *
 * Este archivo solo se exige desde routes/web.php si app()->environment('local'),
 * de modo que en staging y producción las tres rutas ni siquiera existen.
 */

Route::get('/_vista/inicio', function () {
    return view('storefront.home', StorefrontPreview::home());
})->name('preview.inicio');

Route::get('/_vista/producto', function () {
    return view('storefront.product', StorefrontPreview::product());
})->name('preview.producto');

Route::get('/_vista/mujer', function () {
    return view('storefront.section', StorefrontPreview::section());
})->name('preview.mujer');
