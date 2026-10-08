<?php

use App\Enums\StoreSection;
use App\Livewire\Admin\HomePage\Featured;
use App\Models\Category;
use App\Models\Color;
use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Storefront\HomeNewProducts;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Una escena de novedades: un color activo, una categoría de hombre y su talla
 * M, lo mínimo para poder crear productos visibles de la escena.
 */
function baseNovedadesEscena(string $prefijo, string $nombre = 'Camisas'): array
{
    $color = Color::factory()->create([
        'is_active' => true,
        'name' => 'Color '.$prefijo,
        'hex' => '#000000',
        'code' => $prefijo,
    ]);
    $categoria = Category::factory()->section(StoreSection::Hombre)->create([
        'name' => $nombre,
        'sku_prefix' => $prefijo,
    ]);
    $talla = $categoria->sizes()->firstOrCreate(['name' => 'M'], ['is_active' => true]);

    return compact('color', 'categoria', 'talla');
}

/**
 * Un producto de la escena con su variante visible, para que la tienda lo
 * enseñe cuando el test no lo apaga.
 */
function productoNovedad(array $escena, string $nombre, string $referencia, string $status = 'active'): Product
{
    $producto = Product::factory()->create([
        'category_id' => $escena['categoria']->getKey(),
        'name' => $nombre,
        'slug' => Str::slug($nombre).'-'.mb_strtolower($referencia),
        'reference' => $referencia,
        'status' => $status,
        'base_price' => 10000,
    ]);

    ProductVariant::factory()->create([
        'product_id' => $producto->getKey(),
        'color_id' => $escena['color']->getKey(),
        'size_id' => $escena['talla']->getKey(),
        'is_active' => $status === 'active',
        'stock' => 5,
    ]);

    return $producto;
}

/**
 * La pestaña «Lo más nuevo» abierta por un administrador.
 */
function panelNovedades(?User $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Featured::class);
}

beforeEach(function () {
    $this->app->instance('env', 'local');
});

/**
 * Los ids de los productos de «Así se ve hoy en el inicio», en el orden en que
 * la pestaña los pinta, para poder compararlos con lo que entrega HomeNewProducts.
 */
function idsQueMuestraEnInicio(string $html): array
{
    preg_match_all('/wire:key="hoy-(\d+)"/', $html, $coincidencias);

    return array_map('intval', $coincidencias[1] ?? []);
}

/**
 * Los ids de los productos de «Tus elegidos», en el orden renderizado.
 */
function idsDeElegidos(string $html): array
{
    preg_match_all('/wire:key="featured-(\d+)"/', $html, $coincidencias);

    return array_map('intval', $coincidencias[1] ?? []);
}

test('la pestaña «Lo más nuevo» solo es de un administrador, también después de montarse', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    Livewire::actingAs($vendedor)->test(Featured::class)->assertForbidden();
    Livewire::test(Featured::class)->assertForbidden();

    $admin = adminForPanel();
    $escena = baseNovedadesEscena('GU1');
    $producto = productoNovedad($escena, 'Camisa Guardada', 'GU1-001');

    $panel = panelNovedades($admin);
    $panel->call('add', $producto->getKey());

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call('moveDown', HomeFeaturedProduct::query()->firstOrFail()->getKey())->assertForbidden();
});

test('se llega a la pestaña desde ?tab=mas-nuevo, con las dos pestañas a la vista', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())
        ->get('/admin/home-page?tab=mas-nuevo')
        ->assertOk()
        ->assertSee('Lo más nuevo')
        ->assertSeeHtml('aria-controls="home-panel-categorias"')
        ->assertSeeHtml('aria-controls="home-panel-mas-nuevo"')
        ->assertSeeHtml('wire:key="home-panel-mas-nuevo"')
        ->assertDontSeeHtml('wire:key="home-panel-categorias"');
});

test('un tab desconocido en la URL cae a la primera pestaña', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())
        ->get('/admin/home-page?tab=reporte-invalido')
        ->assertOk()
        ->assertSeeHtml('wire:key="home-panel-categorias"')
        ->assertDontSeeHtml('wire:key="home-panel-mas-nuevo"');
});

test('agregar por el buscador pone el producto en «Tus elegidos» y en «Así se ve hoy»', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('AG1', 'Camisas');
    $bermuda = productoNovedad($escena, 'Camisa Bermuda', 'AG1-001');

    $panel = panelNovedades();

    $panel->assertSeeHtml('id="novedades-buscador"');

    $panel->set('search', 'Bermuda')
        ->assertSee('Camisa Bermuda')
        ->assertSeeHtml('wire:key="candidate-'.$bermuda->getKey().'"');

    $panel->call('add', $bermuda->getKey());

    $fila = HomeFeaturedProduct::query()->where('product_id', $bermuda->getKey())->firstOrFail();

    $panel->assertSet('search', '')
        ->assertSet('notice', 'Producto añadido a las novedades.')
        ->assertSeeHtml('wire:key="featured-'.$fila->getKey().'"')
        ->assertSeeHtml('wire:key="hoy-'.$bermuda->getKey().'"')
        ->assertSee('Selección manual (1 producto)');
});

test('un elegido que deja de ser visible sigue en la lista con el aviso y ya no se ve en el inicio', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('TA1', 'Camisas');
    $activo = productoNovedad($escena, 'Camisa Día', 'TA1-001');
    $tapado = productoNovedad($escena, 'Camisa Noche', 'TA1-002');

    $panel = panelNovedades();
    $panel->call('add', $activo->getKey());
    $panel->call('add', $tapado->getKey());

    $tapado->update(['status' => 'inactive']);

    $panel->set('search', 'zzz');

    $panel->assertSee('No es visible: no aparece en el inicio')
        ->assertSee('Selección manual (1 producto)')
        ->assertSeeHtml('wire:key="hoy-'.$activo->getKey().'"')
        ->assertDontSeeHtml('wire:key="hoy-'.$tapado->getKey().'"');
});

test('si ninguno de los elegidos está visible, el inicio vuelve a la regla automática', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('AU2', 'Camisas');
    $automatico = productoNovedad($escena, 'Camisa Automática', 'AU2-003');
    $tapadoUno = productoNovedad($escena, 'Descontinuada Uno', 'AU2-001');
    $tapadoDos = productoNovedad($escena, 'Descontinuada Dos', 'AU2-002');

    $panel = panelNovedades();
    $panel->call('add', $tapadoUno->getKey());
    $panel->call('add', $tapadoDos->getKey());

    $tapadoUno->update(['status' => 'inactive']);
    $tapadoDos->update(['status' => 'inactive']);

    $panel->set('search', 'zzz');

    $panel->assertSee('Automática: ninguno de los elegidos está visible')
        ->assertSeeHtml('wire:key="hoy-'.$automatico->getKey().'"')
        ->assertDontSeeHtml('wire:key="hoy-'.$tapadoUno->getKey().'"')
        ->assertDontSeeHtml('wire:key="hoy-'.$tapadoDos->getKey().'"');
});

test('el buscador no ofrece productos que la tienda no muestra', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('BU1', 'Camisas');
    $fantasma = productoNovedad($escena, 'Camisa Fantasma', 'BU1-001', 'inactive');

    panelNovedades()
        ->set('search', 'Fantasma')
        ->assertDontSeeHtml('wire:key="candidate-'.$fantasma->getKey().'"')
        ->assertSee('No encontramos productos visibles con ese texto.');
});

test('el buscador no vuelve a ofrecer un producto que ya está elegido', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('BU2', 'Camisas');
    $elegido = productoNovedad($escena, 'Camisa Elegida', 'BU2-001');
    $libre = productoNovedad($escena, 'Camisa Libre', 'BU2-002');

    $panel = panelNovedades();
    $panel->call('add', $elegido->getKey());

    $panel->set('search', 'Camisa')
        ->assertSeeHtml('wire:key="candidate-'.$libre->getKey().'"')
        ->assertDontSeeHtml('wire:key="candidate-'.$elegido->getKey().'"');
});

test('el buscador pide al menos dos letras y encuentra por nombre o por referencia', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('NO1', 'Camisas');
    $azul = productoNovedad($escena, 'Camisa Azul', 'NO1-001');
    $lino = productoNovedad($escena, 'Camisa Lino', 'NO1-002');

    $panel = panelNovedades();

    $panel->set('search', 'A')
        ->assertDontSeeHtml('wire:key="candidate-'.$azul->getKey().'"')
        ->assertDontSee('coincidencias');

    $panel->set('search', 'Lino')
        ->assertSeeHtml('wire:key="candidate-'.$lino->getKey().'"');

    $panel->set('search', 'NO1-001')
        ->assertSeeHtml('wire:key="candidate-'.$azul->getKey().'"');

    $panel->set('search', 'no-hay-nada')
        ->assertSee('No encontramos productos visibles con ese texto.');
});

test('el buscador muestra como mucho ocho coincidencias', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('OC1', 'Pantalones');

    for ($i = 1; $i <= 10; $i++) {
        productoNovedad($escena, 'Pantalón Chino '.$i, sprintf('OC1-%03d', $i));
    }

    panelNovedades()->set('search', 'Chino')->assertSee('8 coincidencias');
});

test('los comodines de LIKE se buscan como texto, no como comodines', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('CO1', 'Camisas');
    $algodon = productoNovedad($escena, 'Camisa Algodón Premium', 'CO1-001');
    $seda = productoNovedad($escena, 'Camisa Seda A1', 'CO1-002');

    $panel = panelNovedades();

    // «%» no es comodín: «lgod%» no encuentra «Algodón».
    $panel->set('search', 'lgod%')
        ->assertDontSeeHtml('wire:key="candidate-'.$algodon->getKey().'"')
        ->assertSee('No encontramos productos visibles con ese texto.');

    $panel->set('search', 'lgod')
        ->assertSeeHtml('wire:key="candidate-'.$algodon->getKey().'"');

    // «_» no es comodín: «Seda_1» no encuentra «Seda A1».
    $panel->set('search', 'Seda_1')
        ->assertDontSeeHtml('wire:key="candidate-'.$seda->getKey().'"')
        ->assertSee('No encontramos productos visibles con ese texto.');

    $panel->set('search', 'Seda')
        ->assertSeeHtml('wire:key="candidate-'.$seda->getKey().'"');
});

test('con cuatro elegidos no se puede agregar un quinto y el buscador se retira', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('MX1', 'Camisas');

    for ($i = 1; $i <= 4; $i++) {
        $elegidos[] = productoNovedad($escena, 'Camisa Max '.$i, sprintf('MX1-00%d', $i));
    }
    $quinto = productoNovedad($escena, 'Camisa Quinta', 'MX1-005');

    $panel = panelNovedades();

    foreach ($elegidos as $producto) {
        $panel->call('add', $producto->getKey());
    }

    $panel->assertSee('Llegaste al máximo de 4. Quita uno para agregar otro.')
        ->assertDontSeeHtml('id="novedades-buscador"');

    $panel->call('add', $quinto->getKey())
        ->assertSet('noticeType', 'error')
        ->assertSet('notice', 'Las novedades ya tienen sus 4 productos. Quita uno antes de destacar otro.');

    expect(HomeFeaturedProduct::query()->count())->toBe(4);
});

test('quitar un elegido lo saca de la lista y del inicio y libera el buscador', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('QT1', 'Camisas');

    for ($i = 1; $i <= 4; $i++) {
        $elegidos[] = productoNovedad($escena, 'Camisa Cinco '.$i, sprintf('QT1-00%d', $i));
    }

    $panel = panelNovedades();

    foreach ($elegidos as $producto) {
        $panel->call('add', $producto->getKey());
    }

    $fila = HomeFeaturedProduct::query()->orderBy('order')->orderBy('id')->firstOrFail();

    $panel->call('remove', $fila->getKey())
        ->assertSet('notice', 'Producto quitado de las novedades.')
        ->assertDontSeeHtml('wire:key="featured-'.$fila->getKey().'"')
        ->assertDontSeeHtml('wire:key="hoy-'.$fila->product_id.'"')
        ->assertSeeHtml('id="novedades-buscador"')
        ->assertDontSee('Llegaste al máximo de 4');

    expect(HomeFeaturedProduct::query()->count())->toBe(3);
});

test('la pantalla cuesta las mismas consultas con un elegido que con cuatro', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('QC1', 'Camisas');
    $uno = productoNovedad($escena, 'Camisa Uno', 'QC1-001');

    $panel = panelNovedades();
    $panel->call('add', $uno->getKey());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $panel->set('search', 'x');
    $consultasUno = count(DB::getQueryLog());
    DB::disableQueryLog();

    for ($i = 2; $i <= 4; $i++) {
        $panel->call('add', productoNovedad($escena, 'Camisa Cuatro '.$i, sprintf('QC1-00%d', $i))->getKey());
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $panel->set('search', 'y');
    $consultasCuatro = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($consultasCuatro)->toBe($consultasUno);
});

test('la búsqueda de ocho resultados añade dos consultas: el LIKE y las portadas', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('BQ1', 'Chinos');

    for ($i = 1; $i <= 8; $i++) {
        productoNovedad($escena, 'Chino Cargo '.$i, sprintf('BQ1-%03d', $i));
    }

    $panel = panelNovedades();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $panel->set('search', 'x');
    $soloPantalla = count(DB::getQueryLog());
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $panel->set('search', 'Cargo');
    $conBuscador = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($conBuscador - $soloPantalla)->toBe(2);
});

test('«Así se ve hoy en el inicio» enseña lo mismo que HomeNewProducts en los tres estados', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('CO1', 'Camisas');
    $a = productoNovedad($escena, 'Camisa A', 'CO1-001');
    $b = productoNovedad($escena, 'Camisa B', 'CO1-002');
    $c = productoNovedad($escena, 'Camisa C', 'CO1-003');

    $idsDelInicio = fn (): array => app(HomeNewProducts::class)->effective()['products']->pluck('id')->all();

    // Manual: la lista dice exactamente los elegidos visibles, en el orden de las filas.
    $panel = panelNovedades();

    foreach ([$a, $b, $c] as $producto) {
        $panel->call('add', $producto->getKey());
    }

    expect(idsQueMuestraEnInicio($panel->html()))->toBe([$a->getKey(), $b->getKey(), $c->getKey()])
        ->and(idsQueMuestraEnInicio($panel->html()))->toBe($idsDelInicio());

    // Sin elegidos: la portada vuelve a la regla automática (las más recientes visibles).
    HomeFeaturedProduct::query()->delete();

    expect(idsQueMuestraEnInicio(panelNovedades()->html()))->toBe($idsDelInicio());

    // Elegidos ocultos: ninguno se ve, el inicio vuelve a las más recientes visibles.
    $ocultos = panelNovedades();
    $ocultos->call('add', $a->getKey());
    $ocultos->call('add', $b->getKey());

    $a->update(['status' => 'inactive']);
    $b->update(['status' => 'inactive']);

    // El cambio se hizo fuera del componente: una interacción fuerza la relectura.
    $ocultos->set('search', 'zzz');

    expect(idsQueMuestraEnInicio($ocultos->html()))->toBe([$c->getKey()])
        ->and(idsQueMuestraEnInicio($ocultos->html()))->toBe($idsDelInicio());
});

test('la cabecera de estado dice por qué se muestra cada lista', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('ES1', 'Camisas');
    $elegido = productoNovedad($escena, 'Camisa Estado', 'ES1-001');

    expect(panelNovedades()->html())->toContain('Automática: los 4 productos más recientes');

    $panel = panelNovedades();
    $panel->call('add', $elegido->getKey());

    expect($panel->html())->toContain('Selección manual (1 producto)');

    $elegido->update(['status' => 'inactive']);

    // El cambio se hizo fuera del componente: una interacción fuerza la relectura.
    $panel->set('search', 'zzz');

    expect($panel->html())->toContain('Automática: ninguno de los elegidos está visible');
});

test('mover arriba y abajo reordena los elegidos y el inicio lo refleja', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('OR1', 'Camisas');
    $a = productoNovedad($escena, 'Camisa Orden A', 'OR1-001');
    $b = productoNovedad($escena, 'Camisa Orden B', 'OR1-002');
    $c = productoNovedad($escena, 'Camisa Orden C', 'OR1-003');

    $panel = panelNovedades();

    foreach ([$a, $b, $c] as $producto) {
        $panel->call('add', $producto->getKey());
    }

    $filas = fn (): array => HomeFeaturedProduct::query()->orderBy('order')->orderBy('id')->pluck('product_id')->all();

    expect($filas())->toBe([$a->getKey(), $b->getKey(), $c->getKey()])
        ->and(idsDeElegidos($panel->html()))->toBe([$a->getKey(), $b->getKey(), $c->getKey()])
        ->and(idsQueMuestraEnInicio($panel->html()))->toBe([$a->getKey(), $b->getKey(), $c->getKey()]);

    $filaDe = fn (Product $producto) => HomeFeaturedProduct::query()->where('product_id', $producto->getKey())->sole();

    // Bajar el primero: el orden pasa a B, A, C.
    $panel->call('moveDown', $filaDe($a)->getKey());

    expect($filas())->toBe([$b->getKey(), $a->getKey(), $c->getKey()])
        ->and(idsDeElegidos($panel->html()))->toBe([$b->getKey(), $a->getKey(), $c->getKey()])
        ->and(idsQueMuestraEnInicio($panel->html()))->toBe([$b->getKey(), $a->getKey(), $c->getKey()]);

    // Subir el último: el orden pasa a B, C, A.
    $panel->call('moveUp', $filaDe($c)->getKey());

    expect($filas())->toBe([$b->getKey(), $c->getKey(), $a->getKey()]);

    // Forzar mover el primero hacia arriba y el último hacia abajo: es un no-op.
    $panel->call('moveUp', $filaDe($b)->getKey());
    $panel->call('moveDown', $filaDe($a)->getKey());

    expect($filas())->toBe([$b->getKey(), $c->getKey(), $a->getKey()])
        ->and(HomeFeaturedProduct::query()->count())->toBe(3);
});

test('el primer elegido no puede subir y el último no puede bajar: sus botones llegan deshabilitados', function () {
    $this->seed(RoleSeeder::class);

    $escena = baseNovedadesEscena('DB1', 'Camisas');
    $a = productoNovedad($escena, 'Camisa Deshabilitada A', 'DB1-001');
    $b = productoNovedad($escena, 'Camisa Deshabilitada B', 'DB1-002');

    $panel = panelNovedades();
    $panel->call('add', $a->getKey());
    $panel->call('add', $b->getKey());

    $filaDe = fn (Product $producto) => HomeFeaturedProduct::query()->where('product_id', $producto->getKey())->sole();
    $idA = $filaDe($a)->getKey();
    $idB = $filaDe($b)->getKey();

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($panel->html());
    libxml_clear_errors();

    $wireClicks = [];

    foreach ((new DOMXPath($dom))->query('//button') as $boton) {
        $wireClicks[$boton->getAttribute('wire:click')] = $boton->hasAttribute('disabled');
    }

    expect($wireClicks['moveUp('.$idA.')'])->toBeTrue()
        ->and($wireClicks['moveDown('.$idA.')'])->toBeFalse()
        ->and($wireClicks['moveUp('.$idB.')'])->toBeFalse()
        ->and($wireClicks['moveDown('.$idB.')'])->toBeTrue();
});
