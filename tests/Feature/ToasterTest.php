<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Blade;

/*
 |--------------------------------------------------------------------------
 | Los avisos (toasts) del panel admin
 |--------------------------------------------------------------------------
 |
 | La pila de avisos vive en la store Alpine `toast`, registrada en
 | `resources/js/admin.js`. Para que el `x-for` la lea, el contenedor del
 | toaster tiene que ser una raíz de Alpine con su propio `x-data`, y para
 | que Livewire lo inicialice dentro del layout admin, va dentro del wrapper
 | con `x-data="{ sidebarOpen: false }"`.
 |
 | El z-index también importa: si el toaster quedara bajo los modales, el
 | aviso «Talla actualizada correctamente.» se vería únicamente al cerrar el
 | modal. Apila en el orden modales (z-50) < confirmación (z-[70]) < toasts
 | (z-[80]), dejando solo por encima el panel efímero del select (z-[9999]).
 |
 */

test('the toaster is its own Alpine root and sits above every modal', function () {
    $view = Blade::render('<x-toaster />');

    // `aria-live` identifica la raíz: el `x-data="{}"` tiene que estar ahí y
    // no en un hijo, o el `x-for` sobre `$store.toast` no tiene scope. Que el
    // `z-[80]` esté en la misma etiqueta también lo deja por encima de los
    // modales (admin-modal z-50 y confirm-dialog z-[70]).
    expect($view)
        ->toMatch('/<div\s+x-data="\{\}"[^>]*aria-live="polite"/')
        ->toMatch('/<div\s+x-data="\{\}"[^>]*z-\[80\][^>]*aria-live="polite"/');
});

test('the admin page renders the toaster inside the wrapper with the sidebar state', function () {
    $this->seed(RoleSeeder::class);

    $response = $this->actingAs(adminForPanel())->get('/admin/product-details');

    $response->assertOk();

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    @$dom->loadHTML($response->getContent());
    libxml_clear_errors();

    $toaster = (new DOMXPath($dom))->query('//*[@aria-live="polite"]')->item(0);

    expect($toaster)->not->toBeNull();

    $dentroDelWrapper = false;

    for ($node = $toaster->parentNode; $node; $node = $node->parentNode) {
        if (! $node instanceof DOMElement) {
            continue;
        }

        $xData = $node->getAttribute('x-data');

        if (str_contains($xData, 'sidebarOpen')) {
            $dentroDelWrapper = true;

            break;
        }
    }

    expect($dentroDelWrapper)->toBeTrue();
});

test('the toaster picks the icon with x-show, without any nested template', function () {
    $view = Blade::render('<x-toaster />');

    // Dentro de un <svg> el parser no crea un HTMLTemplateElement: todo lo que
    // vive en *foreign content* se queda como elemento del namespace SVG aun
    // con tagName "template", así que `el.content` es `undefined` y la
    // directiva `if` de Alpine tira en `show()` (livewire.js, «cloneNode»).
    // Por eso el icono se decide con `x-show` sobre los dos <path> que ya
    // están en el DOM. Además, los dos <svg> (el del icono y el del botón de
    // cerrar) son decorativos: tienen `aria-hidden="true"`.
    expect($view)
        ->not->toContain('x-if')
        ->toContain("x-show=\"toast.tone === 'success'\"")
        ->toContain("x-show=\"toast.tone !== 'success'\"")
        ->toMatch('/<svg[^>]*aria-hidden="true"/');
});
