<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\ListingPage;
use App\Services\Storefront\ListingScope;
use App\Services\Storefront\SectionFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Escena
|--------------------------------------------------------------------------
|
| Un alcance que cubre dos secciones, Hombre y Mujer, y una tercera fuera de
| él: Niños tiene una prenda visible que ningún listado del alcance puede
| devolver, que es la que delata que el alcance acota de verdad.
|
| Los precios están puestos para que el rango de las dos juntas no sea el de
| ninguna sola: lo más barato está en Mujer (45.000) y lo más caro también
| (200.000), mientras Hombre va de 60.000 a 150.000. Niños queda por debajo
| (30.000), para que un alcance que se le abriera también bajara el mínimo.
|
|   Camisa Cebra     (camisas)    M-Negro(0), L-Azul(5)     Algodón        60.000  -3d
|   Camisa Nube      (camisas)    M-Azul(5),  L-Negro(0)    Algodón+Lino   80.000  -1d
|   Abrigo Toro      (abrigos)    M-Negro(2)                Algodón       150.000  -2d
|   Vestido Sombra   (vestidos)   M-Negro(3), S-Azul(1)     Algodón        45.000  -4d
|   Vestido Luna     (vestidos)   M-Azul(4),  S-Rojo(2)     Lino          200.000  -5d
|   Camiseta Niño    (infantil)   XS-Azul(3)                —              30.000  hoy
|
| Vestido Sombra tiene su M en Negro y su Azul en S: una búsqueda de M+Azul no
| puede casarla, igual que en la sección de hombre. El Rojo solo existe en
| Mujer y la S solo en Mujer, así que unas opciones que las traigan están
| mirando las dos secciones; la XS solo existe en Niños, que está fuera.
*/

/**
 * Una prenda visible de la categoría, con sus variantes activas y su stock.
 * Cada variante se da como [Size, Color, stock].
 */
function prendaDe(Category $category, string $name, string $slug, int $price, Carbon $createdAt, array $variants): Product
{
    $product = Product::factory()->for($category)->create([
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'base_price' => $price,
        'created_at' => $createdAt,
    ]);

    foreach ($variants as [$size, $color, $stock]) {
        ProductVariant::factory()->for($product)->inSize($size)->create([
            'color_id' => $color->getKey(),
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    return $product;
}

function escenaListado(): array
{
    $camisas = numberedCategory('LC1', 'Camisas');
    $abrigos = numberedCategory('LA1', 'Abrigos');
    $vestidos = numberedCategory('LV1', 'Vestidos', StoreSection::Mujer);
    $infantil = numberedCategory('LI1', 'Infantil', StoreSection::Ninos);

    $mCamisas = $camisas->sizes()->create(['name' => 'M', 'order' => 1]);
    $lCamisas = $camisas->sizes()->create(['name' => 'L', 'order' => 2]);
    $mAbrigos = $abrigos->sizes()->create(['name' => 'M', 'order' => 1]);
    $mVestidos = $vestidos->sizes()->create(['name' => 'M', 'order' => 1]);
    $sVestidos = $vestidos->sizes()->create(['name' => 'S', 'order' => 2]);
    $xsInfantil = $infantil->sizes()->create(['name' => 'XS', 'order' => 1]);

    $negro = Color::factory()->create(['name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG', 'order' => 1]);
    $azul = Color::factory()->create(['name' => 'Azul', 'hex' => '#0000ff', 'code' => 'AZU', 'order' => 2]);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'hex' => '#ff0000', 'code' => 'ROJ', 'order' => 3]);

    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 2]);

    $camisaCebra = prendaDe($camisas, 'Camisa Cebra', 'camisa-cebra', 60000, now()->subDays(3), [
        [$mCamisas, $negro, 0],
        [$lCamisas, $azul, 5],
    ]);
    $camisaCebra->materials()->attach($algodon, ['percentage' => 100]);

    $camisaNube = prendaDe($camisas, 'Camisa Nube', 'camisa-nube', 80000, now()->subDay(), [
        [$mCamisas, $azul, 5],
        [$lCamisas, $negro, 0],
    ]);
    $camisaNube->materials()->attach($algodon, ['percentage' => 60]);
    $camisaNube->materials()->attach($lino, ['percentage' => 40]);

    $abrigo = prendaDe($abrigos, 'Abrigo Toro', 'abrigo-toro', 150000, now()->subDays(2), [
        [$mAbrigos, $negro, 2],
    ]);
    $abrigo->materials()->attach($algodon, ['percentage' => 100]);

    $sombra = prendaDe($vestidos, 'Vestido Sombra', 'vestido-sombra', 45000, now()->subDays(4), [
        [$mVestidos, $negro, 3],
        [$sVestidos, $azul, 1],
    ]);
    $sombra->materials()->attach($algodon, ['percentage' => 100]);

    $luna = prendaDe($vestidos, 'Vestido Luna', 'vestido-luna', 200000, now()->subDays(5), [
        [$mVestidos, $azul, 4],
        [$sVestidos, $rojo, 2],
    ]);
    $luna->materials()->attach($lino, ['percentage' => 100]);

    $nino = prendaDe($infantil, 'Camiseta Niño', 'camiseta-nino', 30000, now(), [
        [$xsInfantil, $azul, 3],
    ]);

    $apagada = Product::factory()->for($camisas)->inactive()->create([
        'name' => 'Camisa Apagada',
        'slug' => 'camisa-apagada',
        'base_price' => 70000,
    ]);

    $sinVariantes = Product::factory()->for($vestidos)->create([
        'name' => 'Vestido Sin Variantes',
        'slug' => 'vestido-sin-variantes',
        'status' => 'active',
        'base_price' => 65000,
    ]);
    ProductVariant::factory()->for($sinVariantes)->inSize($mVestidos)->inactive()->create([
        'color_id' => $negro->getKey(),
        'stock' => 2,
    ]);

    return compact('camisas', 'abrigos', 'vestidos', 'infantil', 'mCamisas', 'lCamisas', 'mAbrigos',
        'mVestidos', 'sVestidos', 'xsInfantil', 'negro', 'azul', 'rojo', 'algodon', 'lino',
        'camisaCebra', 'camisaNube', 'abrigo', 'sombra', 'luna', 'nino', 'apagada', 'sinVariantes');
}

/**
 * Un alcance de dos secciones con su propia ruta temporal, registrada aquí
 * mismo: los enlaces de la página tienen que salir de esa ruta y no de
 * /hombre, que es la que serviría un motor todavía atado a una sección.
 */
function alcanceListado(): ListingScope
{
    Route::get('/listado-de-prueba', fn () => response('ok'))->name('listado.temporal');
    Route::getRoutes()->refreshNameLookups();

    return ListingScope::covering([StoreSection::Hombre, StoreSection::Mujer], 'listado.temporal');
}

/**
 * Lo que el motor devuelve para la petición a un alcance.
 */
function payloadDe(ListingScope $scope, string $uri): array
{
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    parse_str($query, $params);

    $request = Request::create($path, 'GET', $params);

    return app(ListingPage::class)->for($scope, SectionFilters::fromRequest($request, $scope->sections()[0]));
}

test('el alcance de varias secciones lista las prendas de sus secciones y solo esas', function () {
    escenaListado();
    $scope = alcanceListado();

    $nombres = array_column(payloadDe($scope, '/listado')['products'], 'name');

    expect($nombres)->toBe([
        'Camisa Nube',
        'Abrigo Toro',
        'Camisa Cebra',
        'Vestido Sombra',
        'Vestido Luna',
    ])
        ->and($nombres)->not->toContain('Camiseta Niño')
        ->and($nombres)->not->toContain('Camisa Apagada')
        ->and($nombres)->not->toContain('Vestido Sin Variantes');

    expect(payloadDe($scope, '/listado')['total'])->toBe(5)
        ->and(ListingScope::all('listado.temporal')->sectionValues())->toBe(['hombre', 'mujer', 'ninos']);
});

test('los conteos de cada familia suman sobre las secciones del alcance y no aplican el filtro de la suya', function () {
    $escena = escenaListado();
    $scope = alcanceListado();

    $sinFiltros = payloadDe($scope, '/listado');

    expect(collect($sinFiltros['filters']['categories'])->pluck('label')->all())->toBe(['Camisas', 'Abrigos', 'Vestidos'])
        ->and(collect($sinFiltros['filters']['categories'])->pluck('count')->all())->toBe([2, 1, 2])
        ->and(collect($sinFiltros['filters']['sizes'])->pluck('value')->all())->toBe(['M', 'L', 'S'])
        ->and(collect($sinFiltros['filters']['colors'])->pluck('label')->all())->toBe(['Negro', 'Azul', 'Rojo'])
        ->and(collect($sinFiltros['filters']['materials'])->pluck('label')->all())->toBe(['Algodón', 'Lino'])
        ->and(collect($sinFiltros['filters']['materials'])->pluck('count')->all())->toBe([4, 2])
        ->and($sinFiltros['filters']['stock']['count'])->toBe(5);

    $deCamisas = payloadDe($scope, '/listado?categoria[]='.$escena['camisas']->getKey());

    expect($deCamisas['total'])->toBe(2)
        ->and(collect($deCamisas['filters']['categories'])->pluck('count')->all())->toBe([2, 1, 2])
        ->and(collect($deCamisas['filters']['categories'])->pluck('checked')->all())->toBe([true, false, false]);

    $deLino = payloadDe($scope, '/listado?material[]='.$escena['lino']->getKey());

    expect($deLino['total'])->toBe(2)
        ->and(collect($deLino['filters']['materials'])->pluck('count')->all())->toBe([4, 2])
        ->and(collect($deLino['filters']['materials'])->pluck('checked')->all())->toBe([false, true]);

    $deM = payloadDe($scope, '/listado?talla[]=M');

    expect($deM['total'])->toBe(5)
        ->and(collect($deM['filters']['sizes'])->pluck('checked')->all())->toBe([true, false, false])
        ->and(collect($deM['filters']['colors'])->pluck('checked')->all())->toBe([false, false, false])
        ->and(collect($deM['filters']['categories'])->pluck('count')->all())->toBe([2, 1, 2]);
});

test('los límites de precio abarcan las secciones del alcance y no cambian con los otros filtros', function () {
    $escena = escenaListado();

    $sinFiltros = payloadDe(alcanceListado(), '/listado');

    expect($sinFiltros['filters']['price']['min'])->toBe(45000)
        ->and($sinFiltros['filters']['price']['max'])->toBe(200000)
        ->and($sinFiltros['filters']['price']['step'])->toBe(1000);

    $conFiltros = payloadDe(
        alcanceListado(),
        '/listado?talla[]=M&color[]='.$escena['azul']->getKey().'&stock=1&categoria[]='.$escena['camisas']->getKey(),
    );

    expect($conFiltros['total'])->toBe(1)
        ->and($conFiltros['filters']['price']['min'])->toBe(45000)
        ->and($conFiltros['filters']['price']['max'])->toBe(200000);

    $deHombre = payloadDe(ListingScope::section(StoreSection::Hombre), '/listado');

    expect($deHombre['filters']['price']['min'])->toBe(60000)
        ->and($deHombre['filters']['price']['max'])->toBe(150000);
});

test('talla y color se exigen sobre la misma variante activa en el alcance de varias secciones', function () {
    $escena = escenaListado();
    $scope = alcanceListado();
    $azul = $escena['azul']->getKey();
    $negro = $escena['negro']->getKey();

    $mAzul = payloadDe($scope, '/listado?talla[]=M&color[]='.$azul);
    expect($mAzul['total'])->toBe(2)
        ->and(array_column($mAzul['products'], 'name'))->toBe(['Camisa Nube', 'Vestido Luna']);

    $mAzulStock = payloadDe($scope, '/listado?talla[]=M&color[]='.$azul.'&stock=1');
    expect($mAzulStock['total'])->toBe(2);

    $mNegro = payloadDe($scope, '/listado?talla[]=M&color[]='.$negro);
    expect($mNegro['total'])->toBe(3)
        ->and(array_column($mNegro['products'], 'name'))->toBe(['Abrigo Toro', 'Camisa Cebra', 'Vestido Sombra']);

    $sAzul = payloadDe($scope, '/listado?talla[]=S&color[]='.$azul);
    expect($sAzul['total'])->toBe(1)
        ->and(array_column($sAzul['products'], 'name'))->toBe(['Vestido Sombra']);
});

test('los enlaces de la página se construyen sobre la URL base del alcance', function () {
    $escena = escenaListado();
    $scope = alcanceListado();

    foreach (range(1, 13) as $n) {
        prendaDe($escena['camisas'], 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays(30 + $n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);
    }

    $camisas = $escena['camisas']->getKey();
    $base = url('/listado-de-prueba');

    $payload = payloadDe($scope, '/listado?categoria[]='.$camisas.'&stock=1');

    expect($payload['section']['url'])->toBe($base)
        ->and($payload['clearUrl'])->toBe($base)
        ->and($payload['nextUrl'])->toBe($base.'?categoria%5B%5D='.$camisas.'&stock=1&mostrar=24')
        ->and(array_column($payload['active'], 'label'))->toBe(['Camisas', 'Solo en stock'])
        ->and($payload['active'][0]['removeUrl'])->toBe($base.'?stock=1')
        ->and($payload['active'][1]['removeUrl'])->toBe($base.'?categoria%5B%5D='.$camisas);

    foreach ([
        $payload['section']['url'],
        $payload['clearUrl'],
        $payload['nextUrl'],
        $payload['active'][0]['removeUrl'],
        $payload['active'][1]['removeUrl'],
    ] as $enlace) {
        expect($enlace)->toContain($base)
            ->and($enlace)->not->toContain('/hombre');
    }
});

test('el número de consultas no depende del tamaño del alcance', function () {
    $escena = escenaListado();
    $scope = alcanceListado();
    $camisas = $escena['camisas']->getKey();

    $medir = function () use ($scope, $camisas): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        payloadDe($scope, '/listado?categoria[]='.$camisas.'&stock=1');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $chico = $medir();

    foreach (range(1, 7) as $n) {
        prendaDe($escena['camisas'], 'Camisa Chica '.$n, 'camisa-chica-'.$n, 40000 + $n, now()->subDays(40 + $n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);

        prendaDe($escena['vestidos'], 'Vestido Chico '.$n, 'vestido-chico-'.$n, 45000 + $n, now()->subDays(50 + $n), [
            [$escena['mVestidos'], $escena['azul'], 3],
        ]);
    }

    $grande = $medir();

    expect($chico)->toBe($grande)
        ->and($chico)->toBeLessThanOrEqual(14);
});
