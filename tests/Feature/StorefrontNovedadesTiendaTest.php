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

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Escena
|--------------------------------------------------------------------------
|
| Las tres secciones con categorías repetidas y únicas, prendas nuevas y
| viejas, una agotada y dos que la tienda no debe listar:
|
|   Hombre  Camisas     Camisa Nueva     hoy          M-Negro(3)   50.000  nueva
|                   Camisa Clásica   -10d          L-Azul(2)    60.000  nueva
|                   Camisa Límite    -30d          M-Azul(1)    55.000  nueva (límite)
|                   Camisa Caduca    -31d          L-Negro(2)   58.000  vieja
|                   Camisa Apagada   apagada                        40.000  no lista
|                   Camisa Huérfana  sin variantes activas          45.000  no lista
|           Pantalones   Pantalón Toro    -40d          42-Negro(4)  150.000 vieja
|
|   Mujer   Camisas     Camisa Seda      -1d           L-Azul(2)    70.000  nueva
|           Vestidos     Vestido Fresco   -2d           S-Azul(1)    45.000  nueva
|                        Vestido Antiguo  -60d          M-Rojo(2)   200.000 vieja
|                        Vestido Agotado  hoy           M-Negro(0)   90.000  nueva, agotada
|
|   Niños   Infantil     Camiseta Niño    -1d           XS-Azul(3)   30.000  nueva
|
| «Camisas» existe en Hombre y en Mujer (N4); las demás categorías son únicas.
| La tienda lista 10 prendas; Novedades, las 7 creadas hace 30 días o menos
| (Camisa Límite dentro, Camisa Caduca fuera).
*/

/**
 * Una prenda visible de la categoría, con sus variantes activas y su stock.
 * Cada variante se da como [Size, Color, stock].
 */
function prendaEn(Category $category, string $name, string $slug, int $price, Carbon $createdAt, array $variants): Product
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

function escenaPaginas(): array
{
    $camisasHombre = numberedCategory('TH1', 'Camisas');
    $pantalones = numberedCategory('TP1', 'Pantalones');
    $camisasMujer = numberedCategory('TM1', 'Camisas', StoreSection::Mujer);
    $vestidos = numberedCategory('TV1', 'Vestidos', StoreSection::Mujer);
    $infantil = numberedCategory('TI1', 'Infantil', StoreSection::Ninos);

    $mCamisas = $camisasHombre->sizes()->create(['name' => 'M', 'order' => 1]);
    $lCamisas = $camisasHombre->sizes()->create(['name' => 'L', 'order' => 2]);
    $cuarentaDos = $pantalones->sizes()->create(['name' => '42', 'order' => 1]);
    $lCamisasMujer = $camisasMujer->sizes()->create(['name' => 'L', 'order' => 1]);
    $mVestidos = $vestidos->sizes()->create(['name' => 'M', 'order' => 1]);
    $sVestidos = $vestidos->sizes()->create(['name' => 'S', 'order' => 2]);
    $xsInfantil = $infantil->sizes()->create(['name' => 'XS', 'order' => 1]);

    $negro = Color::factory()->create(['name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG', 'order' => 1]);
    $azul = Color::factory()->create(['name' => 'Azul', 'hex' => '#0000ff', 'code' => 'AZU', 'order' => 2]);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'hex' => '#ff0000', 'code' => 'ROJ', 'order' => 3]);

    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 2]);

    $nueva = prendaEn($camisasHombre, 'Camisa Nueva', 'camisa-nueva', 50000, now(), [
        [$mCamisas, $negro, 3],
    ]);
    $nueva->materials()->attach($algodon, ['percentage' => 100]);

    $clasica = prendaEn($camisasHombre, 'Camisa Clásica', 'camisa-clasica', 60000, now()->subDays(10), [
        [$lCamisas, $azul, 2],
    ]);
    $clasica->materials()->attach($algodon, ['percentage' => 60]);
    $clasica->materials()->attach($lino, ['percentage' => 40]);

    prendaEn($camisasHombre, 'Camisa Límite', 'camisa-limite', 55000, now()->subDays(30), [
        [$mCamisas, $azul, 1],
    ]);

    $caduca = prendaEn($camisasHombre, 'Camisa Caduca', 'camisa-caduca', 58000, now()->subDays(31), [
        [$lCamisas, $negro, 2],
    ]);
    $caduca->materials()->attach($lino, ['percentage' => 100]);

    Product::factory()->for($camisasHombre)->inactive()->create([
        'name' => 'Camisa Apagada',
        'slug' => 'camisa-apagada',
        'base_price' => 40000,
    ]);

    $huerfana = Product::factory()->for($camisasHombre)->create([
        'name' => 'Camisa Huérfana',
        'slug' => 'camisa-huerfana',
        'status' => 'active',
        'base_price' => 45000,
    ]);
    ProductVariant::factory()->for($huerfana)->inSize($mCamisas)->inactive()->create([
        'color_id' => $negro->getKey(),
        'stock' => 5,
    ]);

    $toro = prendaEn($pantalones, 'Pantalón Toro', 'pantalon-toro', 150000, now()->subDays(40), [
        [$cuarentaDos, $negro, 4],
    ]);
    $toro->materials()->attach($lino, ['percentage' => 100]);

    $seda = prendaEn($camisasMujer, 'Camisa Seda', 'camisa-seda', 70000, now()->subDays(1), [
        [$lCamisasMujer, $azul, 2],
    ]);
    $seda->materials()->attach($lino, ['percentage' => 100]);

    $fresco = prendaEn($vestidos, 'Vestido Fresco', 'vestido-fresco', 45000, now()->subDays(2), [
        [$sVestidos, $azul, 1],
    ]);
    $fresco->materials()->attach($algodon, ['percentage' => 100]);

    prendaEn($vestidos, 'Vestido Antiguo', 'vestido-antiguo', 200000, now()->subDays(60), [
        [$mVestidos, $rojo, 2],
    ]);

    prendaEn($vestidos, 'Vestido Agotado', 'vestido-agotado', 90000, now(), [
        [$mVestidos, $negro, 0],
        [$sVestidos, $rojo, 0],
    ]);

    $nino = prendaEn($infantil, 'Camiseta Niño', 'camiseta-nino', 30000, now()->subDays(1), [
        [$xsInfantil, $azul, 3],
    ]);
    $nino->materials()->attach($algodon, ['percentage' => 100]);

    return compact(
        'camisasHombre', 'pantalones', 'camisasMujer', 'vestidos', 'infantil',
        'mCamisas', 'lCamisas', 'cuarentaDos', 'lCamisasMujer', 'mVestidos', 'sVestidos', 'xsInfantil',
        'negro', 'azul', 'rojo', 'algodon', 'lino',
    );
}

/**
 * Lo que el motor devuelve para la petición a /novedades o /tienda, con el
 * mismo alcance que la ruta le monta.
 */
function payloadDePagina(string $uri): array
{
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    parse_str($query, $params);

    $request = Request::create($path, 'GET', $params);

    $scope = $path === '/novedades'
        ? ListingScope::all('storefront.novedades')->onlyNew()
        : ListingScope::all('storefront.tienda');

    return app(ListingPage::class)->for($scope, SectionFilters::fromRequest($request));
}

/**
 * Lo que el motor devuelve para la petición a una sección de siempre.
 */
function payloadDeSeccion(StoreSection $section, string $uri): array
{
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    parse_str($query, $params);

    $request = Request::create($path, 'GET', $params);

    return app(ListingPage::class)->for(
        ListingScope::section($section),
        SectionFilters::fromRequest($request, $section),
    );
}

test('las dos páginas responden con su título, su antetítulo y su estado vacío', function () {
    $novedades = get('/novedades')->assertOk();

    $novedades->assertSee('Novedades · Feigler', false)
        ->assertSee('Últimos 30 días')
        ->assertSee('Aún no hay novedades. Vuelve pronto.')
        ->assertDontSee('Quita alguno o empieza de nuevo.')
        ->assertDontSee('Limpiar filtros')
        ->assertDontSee('Mostrar más');

    $tienda = get('/tienda')->assertOk();

    $tienda->assertSee('Tienda · Feigler', false)
        ->assertSee('Todas las secciones')
        ->assertSee('No hay prendas con estos filtros')
        ->assertSee('Quita alguno o empieza de nuevo.')
        ->assertSee('Limpiar filtros')
        ->assertDontSee('Mostrar más');

    expect(preg_match('/<a href="[^"]*\/novedades"[^>]*aria-current="page"/', $novedades->getContent()))->toBe(1)
        ->and(preg_match('/<a href="[^"]*\/tienda"[^>]*aria-current="page"/', $tienda->getContent()))->toBe(1);
});

test('la tienda lista las prendas visibles de las tres secciones y deja fuera las que no se venden', function () {
    escenaPaginas();

    $payload = payloadDePagina('/tienda');
    $nombres = array_column($payload['products'], 'name');

    expect($payload['total'])->toBe(10)
        ->and($nombres)->toContain('Camisa Nueva', 'Pantalón Toro', 'Camisa Seda', 'Vestido Antiguo', 'Camiseta Niño')
        ->and($nombres)->not->toContain('Camisa Apagada', 'Camisa Huérfana');

    get('/tienda')->assertOk()
        ->assertSee('Camisa Nueva')
        ->assertSee('Camisa Seda')
        ->assertSee('Camiseta Niño')
        ->assertDontSee('Camisa Apagada')
        ->assertDontSee('Camisa Huérfana');
});

test('novedades respeta el límite de treinta días y una prenda nueva agotada sale agotada', function () {
    $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

    escenaPaginas();

    $payload = payloadDePagina('/novedades');
    $nombres = array_column($payload['products'], 'name');
    $agotada = collect($payload['products'])->firstWhere('name', 'Vestido Agotado');

    expect($payload['total'])->toBe(7)
        ->and($nombres)->toContain('Camisa Límite')
        ->and($nombres)->not->toContain('Camisa Caduca')
        ->and($nombres)->not->toContain('Pantalón Toro')
        ->and($agotada['badge'])->toBe('agotado');

    get('/novedades')->assertOk()
        ->assertSee('Camisa Límite')
        ->assertDontSee('Camisa Caduca')
        ->assertSeeHtml('>Agotado</span>');
});

test('el distintivo de nuevo y la página de novedades dicen lo mismo', function () {
    escenaPaginas();

    $novedades = payloadDePagina('/novedades');
    $tienda = payloadDePagina('/tienda');

    foreach ($novedades['products'] as $tarjeta) {
        if ($tarjeta['badge'] === 'agotado') {
            continue;
        }

        expect($tarjeta['badge'])->toBe('nuevo');
    }

    $enNovedades = array_column($novedades['products'], 'id');

    foreach ($tienda['products'] as $tarjeta) {
        if ($tarjeta['badge'] === 'nuevo') {
            expect($enNovedades)->toContain($tarjeta['id']);
        }
    }

    expect(substr_count(get('/novedades')->getContent(), '>Nuevo</span>'))->toBe(6)
        ->and(substr_count(get('/tienda')->getContent(), '>Nuevo</span>'))->toBe(6);
});

test('la familia de sección filtra sola, en pareja y con las demás familias', function () {
    $escena = escenaPaginas();

    $deMujer = payloadDePagina('/tienda?seccion[]=mujer');

    expect($deMujer['total'])->toBe(4)
        ->and(array_column($deMujer['products'], 'name'))->toContain('Vestido Fresco')
        ->and(array_column($deMujer['products'], 'name'))->not->toContain('Pantalón Toro')
        ->and(collect($deMujer['filters']['sections'])->where('checked', true)->pluck('value')->all())->toBe(['mujer'])
        ->and(array_column($deMujer['active'], 'label'))->toBe(['Mujer'])
        ->and($deMujer['active'][0]['removeUrl'])->toBe(url('/tienda'));

    $dos = payloadDePagina('/tienda?seccion[]=mujer&seccion[]=ninos');

    expect($dos['total'])->toBe(5);

    $invalida = payloadDePagina('/tienda?seccion[]=zapatos');

    expect($invalida['total'])->toBe(10)
        ->and(collect($invalida['filters']['sections'])->where('checked', true)->all())->toBe([]);

    $combinada = payloadDePagina(
        '/tienda?seccion[]=hombre&categoria[]='.$escena['camisasHombre']->getKey()
        .'&talla[]=M&color[]='.$escena['negro']->getKey().'&stock=1',
    );

    expect($combinada['total'])->toBe(1)
        ->and(array_column($combinada['products'], 'name'))->toBe(['Camisa Nueva'])
        ->and(array_column($combinada['active'], 'label'))
        ->toBe(['Hombre', 'Camisas · Hombre', 'Talla M', 'Negro', 'Solo en stock']);

    get('/tienda?seccion[]=mujer')->assertOk()
        ->assertSee('Vestido Fresco')
        ->assertDontSee('Pantalón Toro');
});

test('los conteos de la familia de sección ignoran su propia familia y aplican las demás', function () {
    $escena = escenaPaginas();

    $sinFiltros = payloadDePagina('/tienda');

    expect(collect($sinFiltros['filters']['sections'])->pluck('value')->all())->toBe(['hombre', 'mujer', 'ninos'])
        ->and(collect($sinFiltros['filters']['sections'])->pluck('count')->all())->toBe([5, 4, 1]);

    $deMujer = payloadDePagina('/tienda?seccion[]=mujer');

    expect(collect($deMujer['filters']['sections'])->pluck('count')->all())->toBe([5, 4, 1]);

    $conCategoria = payloadDePagina('/tienda?seccion[]=mujer&categoria[]='.$escena['vestidos']->getKey());

    expect(collect($conCategoria['filters']['sections'])->pluck('count')->all())->toBe([0, 3, 0]);

    $marcadaEnCero = payloadDePagina('/tienda?seccion[]=hombre&categoria[]='.$escena['vestidos']->getKey());
    $hombre = collect($marcadaEnCero['filters']['sections'])->firstWhere('value', 'hombre');

    expect($marcadaEnCero['total'])->toBe(0)
        ->and($hombre)->not->toBeNull()
        ->and($hombre['checked'])->toBeTrue()
        ->and($hombre['count'])->toBe(0);
});

test('las páginas de una sola sección ignoran seccion[] y no pintan la familia', function () {
    escenaPaginas();

    $conParametros = payloadDeSeccion(StoreSection::Hombre, '/hombre?seccion[]=mujer&seccion[]=ninos');
    $sinParametros = payloadDeSeccion(StoreSection::Hombre, '/hombre');

    expect($conParametros['total'])->toBe($sinParametros['total'])
        ->and($conParametros['total'])->toBe(5)
        ->and($conParametros['filters']['sections'])->toBeNull()
        ->and(array_column($conParametros['active'], 'label'))->not->toContain('Mujer');

    get('/hombre')->assertOk()->assertDontSeeHtml('name="seccion[]"');
    get('/hombre?seccion[]=mujer')->assertOk()->assertDontSeeHtml('name="seccion[]"');
    get('/tienda')->assertOk()->assertSeeHtml('name="seccion[]"');
});

test('las categorías que repiten nombre se distinguen con su sección y las únicas no', function () {
    escenaPaginas();

    $etiquetas = collect(payloadDePagina('/tienda')['filters']['categories'])->pluck('label')->all();

    expect($etiquetas)->toBe(['Camisas · Hombre', 'Pantalones', 'Camisas · Mujer', 'Vestidos', 'Infantil']);

    $deNovedades = collect(payloadDePagina('/novedades')['filters']['categories'])->pluck('label')->all();

    expect($deNovedades)->toBe(['Camisas · Hombre', 'Camisas · Mujer', 'Vestidos', 'Infantil']);

    get('/tienda')->assertOk()
        ->assertSee('Camisas · Hombre')
        ->assertSee('Camisas · Mujer')
        ->assertSee('Infantil');
});

test('los enlaces de novedades y de tienda se construyen sobre sus propias rutas y acumulan', function () {
    $escena = escenaPaginas();

    foreach (range(1, 12) as $n) {
        $extra = prendaEn($escena['camisasHombre'], 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays($n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);
        $extra->materials()->attach($escena['lino'], ['percentage' => 100]);
    }

    $camisas = $escena['camisasHombre']->getKey();

    $novedades = payloadDePagina('/novedades?seccion[]=hombre&categoria[]='.$camisas);

    expect($novedades['total'])->toBe(15)
        ->and($novedades['shown'])->toBe(12)
        ->and($novedades['section']['url'])->toBe(url('/novedades'))
        ->and($novedades['clearUrl'])->toBe(url('/novedades'))
        ->and($novedades['nextUrl'])
        ->toBe(url('/novedades').'?seccion%5B%5D=hombre&categoria%5B%5D='.$camisas.'&mostrar=24')
        ->and(array_column($novedades['active'], 'label'))->toBe(['Hombre', 'Camisas · Hombre'])
        ->and($novedades['active'][0]['removeUrl'])
        ->toBe(url('/novedades').'?categoria%5B%5D='.$camisas)
        ->and($novedades['active'][1]['removeUrl'])
        ->toBe(url('/novedades').'?seccion%5B%5D=hombre');

    $segunda = payloadDePagina('/novedades?seccion[]=hombre&categoria[]='.$camisas.'&mostrar=24');

    expect($segunda['shown'])->toBe(15)
        ->and($segunda['nextUrl'])->toBeNull();

    $tienda = payloadDePagina('/tienda?seccion[]=ninos');

    expect($tienda['section']['url'])->toBe(url('/tienda'))
        ->and($tienda['clearUrl'])->toBe(url('/tienda'))
        ->and(array_column($tienda['active'], 'label'))->toBe(['Niños'])
        ->and($tienda['active'][0]['removeUrl'])->toBe(url('/tienda'));

    get('/novedades')->assertSeeHtml('<form method="GET" action="'.url('/novedades').'"');
    get('/tienda')->assertSeeHtml('<form method="GET" action="'.url('/tienda').'"');
});

test('los límites de precio son los de la página y no cambian con los otros filtros', function () {
    $escena = escenaPaginas();

    $novedades = payloadDePagina('/novedades');

    expect($novedades['filters']['price']['min'])->toBe(30000)
        ->and($novedades['filters']['price']['max'])->toBe(90000);

    $conFiltros = payloadDePagina(
        '/novedades?seccion[]=hombre&categoria[]='.$escena['camisasHombre']->getKey()
        .'&talla[]=M&stock=1',
    );

    expect($conFiltros['total'])->toBe(2)
        ->and($conFiltros['filters']['price']['min'])->toBe(30000)
        ->and($conFiltros['filters']['price']['max'])->toBe(90000);

    $tienda = payloadDePagina('/tienda?talla[]=XS');

    expect($tienda['total'])->toBe(1)
        ->and($tienda['filters']['price']['min'])->toBe(30000)
        ->and($tienda['filters']['price']['max'])->toBe(200000)
        ->and($tienda['filters']['price']['step'])->toBe(1000);
});

test('el menú de la portada y de la ficha enlaza novedades y tienda en escritorio y en móvil', function () {
    escenaPaginas();

    $this->app->instance('env', 'local');

    $portada = get('/')->assertOk()->getContent();
    $ficha = get('/producto/camisa-nueva')->assertOk()->getContent();

    foreach ([$portada, $ficha] as $html) {
        expect(preg_match_all('/href="[^"]*\/novedades"/', $html))->toBeGreaterThanOrEqual(2)
            ->and(preg_match_all('/href="[^"]*\/tienda"/', $html))->toBeGreaterThanOrEqual(2);
    }
});

test('el número de consultas de la tienda no depende del tamaño del catálogo', function () {
    $escena = escenaPaginas();

    $combinada = '/tienda?seccion[]=hombre&categoria[]='.$escena['camisasHombre']->getKey()
        .'&color[]='.$escena['negro']->getKey().'&material[]='.$escena['lino']->getKey().'&stock=1';

    $medir = function (string $uri): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        payloadDePagina($uri);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $chico = [$medir('/tienda'), $medir($combinada)];

    foreach (range(1, 14) as $n) {
        $extra = prendaEn($escena['camisasHombre'], 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays(40 + $n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);
        $extra->materials()->attach($escena['lino'], ['percentage' => 100]);
    }

    $grande = [$medir('/tienda'), $medir($combinada)];

    expect($chico)->toBe([14, 14])
        ->and($grande)->toBe($chico);
});

test('ninguna página de la tienda usa x-if', function () {
    $vista = file_get_contents(resource_path('views/storefront/section.blade.php'));

    expect($vista)->not->toContain('x-if');

    escenaPaginas();

    foreach (['/novedades', '/tienda', '/hombre'] as $uri) {
        $html = get($uri)->assertOk()->getContent();

        expect(preg_match('/(?<!template )x-if/', $html))->toBe(0);
    }
});

test('la ruta de la vista previa de una sección sigue respondiendo en local', function () {
    $this->refreshApplicationIn('local');

    get('/_vista/mujer')->assertOk()->assertSeeHtml('name="categoria[]"');
});
