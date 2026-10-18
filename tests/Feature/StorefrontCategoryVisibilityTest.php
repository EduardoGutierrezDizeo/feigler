<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\ListingPage;
use App\Services\Storefront\ListingScope;
use App\Services\Storefront\SectionFilters;
use Illuminate\Http\Request;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Escena
|--------------------------------------------------------------------------
|
| Apagar una categoría esconde de la tienda todas sus prendas, en cualquier
| superficie: los listados, la búsqueda, la ficha, los relacionados y el inicio.
| Reactivarla las devuelve. La regla es una sola —`Product::visible()`, que pide
| producto activo, una variante activa y la categoría activa—, así que estas
| comprobaciones vigilan que ninguna superficie la haya reimplementado.
|
|   Camisas     (activa)    Camisa Viva       M-Negro(3)   50.000
|   Pantalones  (apagada)   Pantalón Oculto   M-Negro(3)   60.000
|
*/

/**
 * Una prenda vendible de la categoría: activa, con una variante activa con stock.
 */
function prendaDeCategoria(Category $category, string $name, string $slug, int $price, string $sizeName): Product
{
    $product = Product::factory()->for($category)->create([
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'base_price' => $price,
    ]);

    ProductVariant::factory()->for($product)->inSize(
        $category->sizes()->create(['name' => $sizeName, 'order' => 1]),
    )->create([
        'color_id' => Color::factory()->create()->getKey(),
        'stock' => 3,
        'is_active' => true,
    ]);

    return $product;
}

function escenaCategoriaApagada(): array
{
    $visible = numberedCategory('CV1', 'Camisas');
    $apagada = numberedCategory('CV2', 'Pantalones');

    $viva = prendaDeCategoria($visible, 'Camisa Viva', 'camisa-viva', 50000, 'M');
    $oculta = prendaDeCategoria($apagada, 'Pantalón Oculto', 'pantalon-oculto', 60000, 'M');

    $apagada->update(['is_active' => false]);

    return compact('visible', 'apagada', 'viva', 'oculta');
}

test('una categoría apagada esconde sus prendas de la tienda, la sección, novedades y la búsqueda', function () {
    escenaCategoriaApagada();

    get('/tienda')->assertOk()
        ->assertSee('Camisa Viva')
        ->assertDontSee('Pantalón Oculto');

    get('/hombre')->assertOk()
        ->assertSee('Camisa Viva')
        ->assertDontSee('Pantalón Oculto');

    get('/novedades')->assertOk()
        ->assertSee('Camisa Viva')
        ->assertDontSee('Pantalón Oculto');

    get('/buscar?q=Viva')->assertOk()->assertSee('Camisa Viva');

    get('/buscar?q=Oculto')->assertOk()->assertDontSee('Pantalón Oculto');
});

test('la ficha y los relacionados de la categoría apagada no se sirven', function () {
    escenaCategoriaApagada();

    get('/producto/pantalon-oculto')->assertNotFound();

    get('/producto/camisa-viva')->assertOk()
        ->assertSee('Camisa Viva')
        ->assertDontSee('Pantalón Oculto');
});

test('el inicio no muestra ni cuenta las prendas de una categoría apagada', function () {
    $this->app->instance('env', 'local');

    escenaCategoriaApagada();

    get('/')->assertOk()
        ->assertSee('Camisa Viva')
        ->assertDontSee('Pantalón Oculto')
        ->assertDontSee('Pantalones');
});

test('los filtros del listado no ofrecen la categoría apagada', function () {
    $escena = escenaCategoriaApagada();

    $payload = app(ListingPage::class)->for(
        ListingScope::all('storefront.tienda'),
        SectionFilters::fromRequest(Request::create('/tienda', 'GET')),
    );

    $etiquetas = collect($payload['filters']['categories'])->pluck('label')->all();

    expect($payload['total'])->toBe(1)
        ->and($etiquetas)->toBe(['Camisas'])
        ->and($etiquetas)->not->toContain('Pantalones');
});

test('reactivar la categoría devuelve sus prendas a la tienda y a la ficha', function () {
    $escena = escenaCategoriaApagada();

    $escena['apagada']->update(['is_active' => true]);

    get('/tienda')->assertOk()->assertSee('Pantalón Oculto');
    get('/producto/pantalon-oculto')->assertOk()->assertSee('Pantalón Oculto');
});
