<?php

use App\Support\StorefrontPreview;
use Illuminate\Support\Facades\Route;

/*
 * La tienda pública con los datos falsos de StorefrontPreview. Las tres rutas
 * /_vista/* solo se registran si la aplicación arranca en local, así que cada
 * test arranca la suya con refreshApplicationIn() antes de pedirlas.
 */

beforeEach(function () {
    $this->refreshApplicationIn('local');
});

/**
 * Lee la configuración que @js() incrusta en el x-data de un componente, tal y
 * como la leería Alpine en el navegador: JSON.parse('...') a array.
 *
 * Los botones de talla y de color no están en el HTML: los pinta Alpine dentro
 * de un template, así que la única manera de comprobar qué recibe el componente
 * es leer el objeto con el que se invoca.
 */
function storefrontAlpinePayload(string $html, string $component): array
{
    $pattern = '/'.preg_quote($component, '/').'\(JSON\.parse\('."'(.*?)'".'\)\)"/s';

    preg_match($pattern, $html, $matches);

    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"');
    expect($json)->toBeString();

    $payload = json_decode($json, true);
    expect($payload)->toBeArray();

    return $payload;
}

test('las tres rutas de la vista previa responden en local', function (string $uri) {
    expect(app()->environment())->toBe('local');

    $this->get($uri)->assertOk();
})->with(['/_vista/inicio', '/_vista/producto', '/_vista/mujer']);

test('las rutas de la vista previa no existen en producción', function () {
    $this->refreshApplicationIn('production');

    expect(app()->environment())->toBe('production')
        ->and(Route::has('preview.inicio'))->toBeFalse()
        ->and(Route::has('preview.producto'))->toBeFalse()
        ->and(Route::has('preview.mujer'))->toBeFalse()
        ->and(Route::has('dashboard'))->toBeTrue();

    foreach (['/_vista/inicio', '/_vista/producto', '/_vista/mujer'] as $uri) {
        $this->get($uri)->assertNotFound();
    }

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('el inicio muestra el encabezado y las cuatro novedades', function () {
    $home = StorefrontPreview::home();

    $response = $this->get('/_vista/inicio');

    $response->assertOk()
        ->assertSeeHtml('Todo en un solo')
        ->assertSeeHtml($home['store']['hours']);

    foreach ($home['newProducts'] as $product) {
        $response->assertSeeHtml($product['name']);
    }

    expect(preg_match_all('/<article\b/', $response->getContent()))
        ->toBe(count($home['newProducts']));
});

test('el producto muestra el nombre, las tallas y cada color', function () {
    $product = StorefrontPreview::product()['product'];

    $response = $this->get('/_vista/producto');

    $response->assertOk()
        ->assertSeeHtml($product['name'])
        ->assertSeeHtml('role="group" aria-label="Talla"')
        ->assertSeeHtml('role="group" aria-label="Color"');

    $purchase = storefrontAlpinePayload($response->getContent(), 'productPurchase');

    expect($purchase['sizes'])->toBe($product['sizes'])
        ->and(array_column($purchase['colors'], 'name'))->toBe(array_column($product['colors'], 'name'))
        ->and($purchase['variants'])->toHaveCount(count($product['variants']))
        ->and($purchase['variants'][0]['color'])->toBe($product['colors'][0]['id'])
        ->and($purchase['variants'][0]['size'])->toBe($product['sizes'][0])
        ->and($purchase['variants'][0]['stock'])->toBeInt();
});

test('la sección muestra los filtros, el orden y el botón de mostrar más', function () {
    $this->get('/_vista/mujer')
        ->assertOk()
        ->assertSeeHtml('name="categoria[]"')
        ->assertSeeHtml('name="talla[]"')
        ->assertSeeHtml('name="color[]"')
        ->assertSeeHtml('name="material[]"')
        ->assertSeeHtml('name="stock"')
        ->assertSeeHtml('name="orden"')
        ->assertSeeHtml('Quitar filtro')
        ->assertSeeHtml('Vestido Selva')
        ->assertSeeHtml('Mostrar más');
});

test('ninguna de las tres vistas de la tienda usa x-if', function (string $view) {
    $source = file_get_contents(resource_path('views/storefront/'.$view.'.blade.php'));

    expect($source)->not->toContain('x-if');
})->with(['home', 'product', 'section']);

test('la sección sin prendas avisa y deja de ofrecer mostrar más', function () {
    $data = StorefrontPreview::section();
    $data['products'] = [];

    $html = view('storefront.section', $data)->render();

    expect($html)->toContain('No hay prendas con estos filtros')
        ->not->toContain('Mostrar más');
});
