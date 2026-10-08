<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\SectionFilters;
use App\Services\Storefront\SectionPage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Escena
|--------------------------------------------------------------------------
|
| Una sección de hombre con tres categorías activas, dos tallas en las camisas
| y una en los pantalones, dos colores y dos materiales. Las prendas se reparten
| así, para que los filtros de talla y color tengan variantes que no casan:
|
|   Camisa Cebra    (camisas)     M-Negro(0), L-Azul(5)   Algodón        60.000
|   Camisa Nube     (camisas)     M-Azul(5),  L-Negro(0)  Algodón+Lino   80.000
|   Pantalón Delta  (pantalones)  42-Negro(3)             Lino          100.000
|   Abrigo Toro     (abrigos)     M-Negro(2)              Algodón       150.000
|
| Camisa Cebra tiene su M en Negro agotado y su Azul en L: una búsqueda de
| M+Azul no puede casarla, ni en stock, porque ninguna fila es M y Azul a la
| vez. Camisa Nube sí, con su M en Azul. Los ids de las tallas: las dos M viven
| en categorías distintas (camisas y abrigos), la L solo en camisas y el 42 en
| los pantalones.
*/

function escenaSeccion(): array
{
    $camisas = numberedCategory('CM1', 'Camisas');
    $pantalones = numberedCategory('PA1', 'Pantalones');
    $abrigos = numberedCategory('AB1', 'Abrigos');

    $mCamisas = $camisas->sizes()->create(['name' => 'M', 'order' => 1]);
    $lCamisas = $camisas->sizes()->create(['name' => 'L', 'order' => 2]);
    $cuarentaDos = $pantalones->sizes()->create(['name' => '42', 'order' => 3]);
    $mAbrigos = $abrigos->sizes()->create(['name' => 'M', 'order' => 1]);

    $negro = Color::factory()->create(['name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG', 'order' => 1]);
    $azul = Color::factory()->create(['name' => 'Azul', 'hex' => '#0000ff', 'code' => 'AZU', 'order' => 2]);

    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 2]);

    $camisaCebra = crearPrenda($camisas, 'Camisa Cebra', 'camisa-cebra', 60000, now()->subDays(3), [
        [$mCamisas, $negro, 0],
        [$lCamisas, $azul, 5],
    ]);
    $camisaCebra->materials()->attach($algodon, ['percentage' => 100]);

    $camisaNube = crearPrenda($camisas, 'Camisa Nube', 'camisa-nube', 80000, now()->subDay(), [
        [$mCamisas, $azul, 5],
        [$lCamisas, $negro, 0],
    ]);
    $camisaNube->materials()->attach($algodon, ['percentage' => 60]);
    $camisaNube->materials()->attach($lino, ['percentage' => 40]);

    $pantalon = crearPrenda($pantalones, 'Pantalón Delta', 'pantalon-delta', 100000, now()->subDays(5), [
        [$cuarentaDos, $negro, 3],
    ]);
    $pantalon->materials()->attach($lino, ['percentage' => 100]);

    $abrigo = crearPrenda($abrigos, 'Abrigo Toro', 'abrigo-toro', 150000, now()->subDays(2), [
        [$mAbrigos, $negro, 2],
    ]);
    $abrigo->materials()->attach($algodon, ['percentage' => 100]);

    return compact('camisas', 'pantalones', 'abrigos', 'mCamisas', 'lCamisas', 'cuarentaDos',
        'mAbrigos', 'negro', 'azul', 'algodon', 'lino', 'camisaCebra', 'camisaNube', 'pantalon', 'abrigo');
}

/**
 * Una prenda visible de la categoría, con sus variantes activas y si stock.
 * Cada variante se da como [Size, Color, stock].
 */
function crearPrenda(Category $category, string $name, string $slug, int $price, Carbon $createdAt, array $variants): Product
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

/**
 * Lo que SectionPage devolvería para la petición a una sección.
 */
function sectionPayload(string $uri, StoreSection $section): array
{
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    parse_str($query, $params);

    $request = Request::create($path, 'GET', $params);

    return app(SectionPage::class)->for($section, SectionFilters::fromRequest($request, $section));
}

test('las tres secciones responden con su listado y su título', function () {
    $color = Color::factory()->create();

    foreach (StoreSection::cases() as $indice => $cada) {
        $categoria = numberedCategory('SEC'.$indice, $cada->label(), $cada);
        $talla = $categoria->sizes()->create(['name' => 'M', 'order' => 1]);

        $producto = Product::factory()->for($categoria)->create([
            'name' => $cada->label().' Propia',
            'slug' => $cada->value.'-propia',
            'status' => 'active',
            'base_price' => 50000,
        ]);

        ProductVariant::factory()->for($producto)->create([
            'color_id' => $color->getKey(),
            'size_id' => $talla->getKey(),
            'stock' => 3,
            'is_active' => true,
        ]);
    }

    foreach (StoreSection::cases() as $cada) {
        $response = get('/'.$cada->value);

        $response->assertOk()
            ->assertSee($cada->label().' · Feigler', false)
            ->assertSee($cada->label().' Propia');

        foreach (StoreSection::cases() as $otra) {
            if ($otra === $cada) {
                continue;
            }

            $response->assertDontSee($otra->label().' Propia');
        }
    }
});

test('una sección que no existe da 404', function () {
    get('/zapatos')->assertNotFound();
    get('/hombre-')->assertNotFound();
});

test('una sección no lista prendas apagadas ni prendas sin variantes activas', function () {
    $escena = escenaSeccion();

    Product::factory()->for($escena['camisas'])->inactive()->create([
        'name' => 'Camisa Apagada',
        'slug' => 'camisa-apagada',
    ]);

    $sinVariantes = Product::factory()->for($escena['camisas'])->create([
        'name' => 'Camisa Sin Variantes',
        'slug' => 'camisa-sin-variantes',
        'status' => 'active',
    ]);
    ProductVariant::factory()->for($sinVariantes)->inactive()->create(['color_id' => $escena['negro']->getKey()]);

    Product::factory()->for($escena['abrigos'])->create(['name' => 'Abrigo Apagado', 'slug' => 'abrigo-apagado']);
    $soloVarianteApagada = Product::factory()->for($escena['abrigos'])->create([
        'name' => 'Abrigo Vivo',
        'slug' => 'abrigo-vivo',
        'status' => 'active',
    ]);
    ProductVariant::factory()->for($soloVarianteApagada)->inactive()->create(['color_id' => $escena['negro']->getKey()]);

    $payload = sectionPayload('/hombre', StoreSection::Hombre);
    $nombres = array_column($payload['products'], 'name');

    expect($payload['total'])->toBe(4);

    expect($nombres)->not->toContain('Camisa Apagada')
        ->and($nombres)->not->toContain('Camisa Sin Variantes')
        ->and($nombres)->not->toContain('Abrigo Apagado')
        ->and($nombres)->not->toContain('Abrigo Vivo');
});

test('una prenda agotada sale en la lista con su distintivo', function () {
    $escena = escenaSeccion();
    $agotada = crearPrenda($escena['camisas'], 'Camisa Vacía', 'camisa-vacia', 70000, now()->subDays(6), [
        [$escena['mCamisas'], $escena['negro'], 0],
        [$escena['lCamisas'], $escena['azul'], 0],
    ]);

    $payload = sectionPayload('/hombre', StoreSection::Hombre);
    $tarjeta = collect($payload['products'])->firstWhere('id', $agotada->getKey());

    expect($tarjeta['badge'])->toBe('agotado');
});

test('el orden por defecto son las más recientes y los precios lo ordenan', function () {
    escenaSeccion();

    $novedades = array_column(sectionPayload('/hombre', StoreSection::Hombre)['products'], 'name');
    $porPrecio = array_column(sectionPayload('/hombre?orden=precio-asc', StoreSection::Hombre)['products'], 'name');
    $precioBajo = array_column(sectionPayload('/hombre?orden=precio-desc', StoreSection::Hombre)['products'], 'name');

    expect($novedades)->toBe(['Camisa Nube', 'Abrigo Toro', 'Camisa Cebra', 'Pantalón Delta'])
        ->and(array_column(sectionPayload('/hombre?orden=relevancia', StoreSection::Hombre)['products'], 'name'))->toBe($novedades)
        ->and(array_column(sectionPayload('/hombre?orden=magia', StoreSection::Hombre)['products'], 'name'))->toBe($novedades)
        ->and($porPrecio)->toBe(['Camisa Cebra', 'Camisa Nube', 'Pantalón Delta', 'Abrigo Toro'])
        ->and($precioBajo)->toBe(array_reverse($porPrecio));
});

test('una misma familia se alterna (O) y las familias distintas se suman (Y)', function () {
    $escena = escenaSeccion();

    $tallasMCaicas = sectionPayload('/hombre?talla[]=M&talla[]=L', StoreSection::Hombre);
    expect($tallasMCaicas['total'])->toBe(3)
        ->and(array_column($tallasMCaicas['products'], 'name'))->toBe(['Camisa Nube', 'Abrigo Toro', 'Camisa Cebra']);

    $deCamisas = sectionPayload('/hombre?categoria[]='.$escena['camisas']->getKey(), StoreSection::Hombre);
    expect($deCamisas['total'])->toBe(2)
        ->and(array_column($deCamisas['products'], 'name'))->not->toContain('Abrigo Toro');

    $camisasYlino = sectionPayload('/hombre?categoria[]='.$escena['camisas']->getKey().'&material[]='.$escena['lino']->getKey(), StoreSection::Hombre);
    expect($camisasYlino['total'])->toBe(1)
        ->and(array_column($camisasYlino['products'], 'name'))->toBe(['Camisa Nube']);
});

test('talla y color se exigen sobre la misma variante activa', function () {
    $escena = escenaSeccion();
    $azul = $escena['azul']->getKey();
    $negro = $escena['negro']->getKey();

    $mAzul = sectionPayload('/hombre?talla[]=M&color[]='.$azul, StoreSection::Hombre);
    expect($mAzul['total'])->toBe(1)
        ->and(array_column($mAzul['products'], 'name'))->toBe(['Camisa Nube']);

    $mNegro = sectionPayload('/hombre?talla[]=M&color[]='.$negro, StoreSection::Hombre);
    expect($mNegro['total'])->toBe(2)
        ->and(array_column($mNegro['products'], 'name'))->toBe(['Abrigo Toro', 'Camisa Cebra']);

    $mAzulStock = sectionPayload('/hombre?talla[]=M&color[]='.$azul.'&stock=1', StoreSection::Hombre);
    expect($mAzulStock['total'])->toBe(1);

    $lNegro = sectionPayload('/hombre?talla[]=L&color[]='.$negro, StoreSection::Hombre);
    expect($lNegro['total'])->toBe(1)
        ->and(array_column($lNegro['products'], 'name'))->toBe(['Camisa Nube']);

    $lNegroStock = sectionPayload('/hombre?talla[]=L&color[]='.$negro.'&stock=1', StoreSection::Hombre);
    expect($lNegroStock['total'])->toBe(0);
});

test('los conteos de una familia ignoran el filtro de su propia familia', function () {
    $escena = escenaSeccion();

    $sinFiltros = sectionPayload('/hombre', StoreSection::Hombre);

    expect(collect($sinFiltros['filters']['categories'])->pluck('count')->all())->toBe([2, 1, 1])
        ->and(collect($sinFiltros['filters']['materials'])->pluck('count')->all())->toBe([3, 2])
        ->and($sinFiltros['filters']['stock']['count'])->toBe(4);

    $marcada = sectionPayload('/hombre?categoria[]='.$escena['camisas']->getKey(), StoreSection::Hombre);

    expect(collect($marcada['filters']['categories'])->pluck('count')->all())->toBe([2, 1, 1])
        ->and(collect($marcada['filters']['categories'])->pluck('checked')->all())->toBe([true, false, false]);
});

test('una opción marcada sale aunque su conteo sea cero', function () {
    $escena = escenaSeccion();

    $payload = sectionPayload('/hombre?color[]='.$escena['azul']->getKey(), StoreSection::Hombre);
    $pantalones = collect($payload['filters']['categories'])->firstWhere('value', (string) $escena['pantalones']->getKey());

    expect($pantalones)->not->toBeNull()
        ->and($pantalones['count'])->toBe(0)
        ->and(collect($payload['filters']['colors'])->pluck('checked')->all())->toBe([false, true]);
});

test('el conteo de stock es el de las prendas que cumplen los demás filtros teniendo stock', function () {
    $escena = escenaSeccion();

    $deCamisas = sectionPayload('/hombre?categoria[]='.$escena['camisas']->getKey(), StoreSection::Hombre);

    expect($deCamisas['filters']['stock']['count'])->toBe(2)
        ->and(sectionPayload('/hombre?stock=1', StoreSection::Hombre)['total'])->toBe(4);
});

test('la lista crece de doce en doce hasta un tope', function () {
    $escena = escenaSeccion();
    $camisas = $escena['camisas']->getKey();

    foreach (range(1, 20) as $n) {
        crearPrenda($escena['camisas'], 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays(30 + $n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);
    }

    $primera = sectionPayload('/hombre?categoria[]='.$camisas, StoreSection::Hombre);
    expect($primera['shown'])->toBe(12)
        ->and($primera['total'])->toBe(22)
        ->and($primera['nextUrl'])->toContain('mostrar=24')
        ->and($primera['nextUrl'])->toContain('categoria%5B%5D%5B0%5D='.$camisas);

    $segunda = sectionPayload('/hombre?categoria[]='.$camisas.'&mostrar=24', StoreSection::Hombre);
    expect($segunda['shown'])->toBe(22)
        ->and($segunda['total'])->toBe(22)
        ->and($segunda['nextUrl'])->toBeNull();

    $tope = sectionPayload('/hombre?categoria[]='.$camisas.'&mostrar=1000', StoreSection::Hombre);
    expect($tope['shown'])->toBe(22)
        ->and($tope['total'])->toBe(22)
        ->and($tope['nextUrl'])->toBeNull();

    $corregida = sectionPayload('/hombre?categoria[]='.$camisas.'&mostrar=7', StoreSection::Hombre);
    expect($corregida['shown'])->toBe(12)
        ->and($corregida['nextUrl'])->toContain('mostrar=24');
});

test('el enlace de siguiente paso conserva los filtros', function () {
    $escena = escenaSeccion();
    $camisas = $escena['camisas']->getKey();
    $lino = $escena['lino']->getKey();

    foreach (range(1, 20) as $n) {
        $extra = crearPrenda($escena['camisas'], 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays(30 + $n), [
            [$escena['mCamisas'], $escena['negro'], 3],
        ]);
        $extra->materials()->attach($escena['lino'], ['percentage' => 100]);
    }

    $nextUrl = sectionPayload('/hombre?categoria[]='.$camisas.'&material[]='.$lino.'&stock=1&orden=precio-asc', StoreSection::Hombre)['nextUrl'];

    expect($nextUrl)->toContain('categoria%5B%5D%5B0%5D='.$camisas)
        ->and($nextUrl)->toContain('material%5B%5D%5B0%5D='.$lino)
        ->and($nextUrl)->toContain('stock=1')
        ->and($nextUrl)->toContain('orden=precio-asc')
        ->and($nextUrl)->toContain('mostrar=24');
});

test('los chips de los filtros activos quitan un valor y no llevan mostrar', function () {
    $escena = escenaSeccion();
    $camisas = $escena['camisas']->getKey();
    $lino = $escena['lino']->getKey();

    $payload = sectionPayload('/hombre?categoria[]='.$camisas.'&material[]='.$lino.'&stock=1&mostrar=24', StoreSection::Hombre);

    expect(array_column($payload['active'], 'label'))->toBe(['Camisas', 'Lino', 'Solo en stock'])
        ->and($payload['clearUrl'])->toBe(url('/hombre'));

    $chip = $payload['active'][1];

    expect($chip['removeUrl'])->not->toContain('material')
        ->and($chip['removeUrl'])->not->toContain('mostrar')
        ->and($chip['removeUrl'])->toContain('categoria%5B%5D%5B0%5D='.$camisas)
        ->and($chip['removeUrl'])->toContain('stock=1');
});

test('un valor que no pertenece a la sección se ignora', function () {
    $escena = escenaSeccion();
    $deMujer = numberedCategory('VM1', 'Vestidos', StoreSection::Mujer);
    $tallaS = $deMujer->sizes()->create(['name' => 'S', 'order' => 1]);
    $vestido = Product::factory()->for($deMujer)->create([
        'name' => 'Vestido Mujer',
        'slug' => 'vestido-mujer',
        'status' => 'active',
        'base_price' => 90000,
    ]);
    ProductVariant::factory()->for($vestido)->create([
        'color_id' => $escena['azul']->getKey(),
        'size_id' => $tallaS->getKey(),
        'stock' => 5,
        'is_active' => true,
    ]);

    $payload = sectionPayload('/hombre?categoria[]='.$deMujer->getKey(), StoreSection::Hombre);

    expect($payload['total'])->toBe(4)
        ->and(array_column($payload['products'], 'name'))->not->toContain('Vestido Mujer')
        ->and(collect($payload['filters']['categories'])->pluck('checked')->filter()->all())->toBe([])
        ->and($payload['clearUrl'])->toBe(url('/hombre'));
});

test('los parámetros que no se entienden se ignoran en vez de responder mal', function () {
    escenaSeccion();

    $payload = sectionPayload('/hombre?categoria[]=abc&color[]=9999&material[]=x4&talla[]=ZZZ&precio_min=hola&orden=magia', StoreSection::Hombre);

    expect($payload['total'])->toBe(4)
        ->and($payload['sort']['value'])->toBe('novedades')
        ->and(collect($payload['filters']['categories'])->pluck('checked')->filter()->all())->toBe([]);
});

test('un rango de precio invertido se intercambia y se acota al rango de la sección', function () {
    escenaSeccion();

    $payload = sectionPayload('/hombre?precio_min=90000&precio_max=1000', StoreSection::Hombre);

    expect($payload['total'])->toBe(2)
        ->and(array_column($payload['products'], 'name'))->toBe(['Camisa Nube', 'Camisa Cebra'])
        ->and($payload['filters']['price']['from'])->toBeNull()
        ->and($payload['filters']['price']['to'])->toBe(90000)
        ->and($payload['filters']['price']['min'])->toBe(60000)
        ->and($payload['filters']['price']['max'])->toBe(151000)
        ->and($payload['filters']['price']['step'])->toBe(1000);
});

test('un límite de precio que queda fuera del rango no se cuenta como movido', function () {
    escenaSeccion();

    $payload = sectionPayload('/hombre?precio_min=50000&precio_max=300000', StoreSection::Hombre);

    expect($payload['total'])->toBe(4)
        ->and($payload['filters']['price']['from'])->toBeNull()
        ->and($payload['filters']['price']['to'])->toBeNull();
});

test('un límite de precio movido se refleja en el chip y en la página', function () {
    escenaSeccion();

    $payload = sectionPayload('/hombre?precio_min=70000', StoreSection::Hombre);

    expect($payload['filters']['price']['from'])->toBe(70000)
        ->and($payload['filters']['price']['to'])->toBeNull()
        ->and(collect($payload['active'])->pluck('label'))->toContain('Desde $70.000');

    get('/hombre?precio_min=70000')->assertOk()->assertSee('Desde $70.000');
});

test('la sección solo ofrece lo que puede mostrar', function () {
    $escena = escenaSeccion();

    $verde = Color::factory()->inactive()->create(['name' => 'Verde', 'code' => 'VER', 'order' => 3]);
    $tallaApagada = $escena['camisas']->sizes()->create(['name' => 'XL', 'order' => 3, 'is_active' => false]);
    $seda = Material::factory()->inactive()->create(['name' => 'Seda']);

    $conApagados = crearPrenda($escena['abrigos'], 'Abrigo Verde', 'abrigo-verde', 110000, now()->subDays(4), [
        [$escena['mAbrigos'], $verde, 2],
        [$tallaApagada, $escena['negro'], 2],
    ]);
    $conApagados->materials()->attach($seda, ['percentage' => 100]);

    $payload = sectionPayload('/hombre', StoreSection::Hombre);

    expect(collect($payload['filters']['sizes'])->pluck('value')->all())->toBe(['M', 'L', '42'])
        ->and(collect($payload['filters']['colors'])->pluck('label')->all())->toBe(['Negro', 'Azul'])
        ->and(collect($payload['filters']['materials'])->pluck('label')->all())->toBe(['Algodón', 'Lino'])
        ->and(collect($payload['filters']['categories'])->pluck('label')->all())->toBe(['Camisas', 'Pantalones', 'Abrigos']);
});

test('las tarjetas salen con su precio, su color y su enlace', function () {
    escenaSeccion();

    $payload = sectionPayload('/hombre', StoreSection::Hombre);
    $tarjeta = collect($payload['products'])->firstWhere('name', 'Camisa Cebra');

    expect($tarjeta['price'])->toBe(60000)
        ->and($tarjeta['url'])->toBe(url('/producto/camisa-cebra'))
        ->and($tarjeta['colors'])->toBe([
            ['name' => 'Negro', 'hex' => '#000000'],
            ['name' => 'Azul', 'hex' => '#0000ff'],
        ]);
});

test('una prenda recién creada lleva el distintivo de nuevo', function () {
    $escena = escenaSeccion();
    $nueva = crearPrenda($escena['camisas'], 'Camisa Nueva', 'camisa-nueva', 55000, now(), [
        [$escena['mCamisas'], $escena['negro'], 2],
    ]);

    $payload = sectionPayload('/hombre', StoreSection::Hombre);

    expect(collect($payload['products'])->firstWhere('id', $nueva->getKey())['badge'])->toBe('nuevo');
});

test('el número de consultas no depende del tamaño del catálogo', function () {
    $escena = escenaSeccion();
    $camisas = $escena['camisas']->getKey();
    $negro = $escena['negro'];

    $medir = function () use ($camisas): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        sectionPayload('/hombre?categoria[]='.$camisas.'&stock=1', StoreSection::Hombre);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $chico = $medir();

    foreach (range(1, 14) as $n) {
        $otra = numberedCategory('EX'.$n, 'Extra '.$n);
        $talla = $otra->sizes()->create(['name' => 'S', 'order' => 1]);
        $producto = Product::factory()->for($otra)->create([
            'name' => 'Prenda Extra '.$n,
            'slug' => 'prenda-extra-'.$n,
            'status' => 'active',
            'base_price' => 40000 + $n,
        ]);
        ProductVariant::factory()->for($producto)->create([
            'color_id' => $negro->getKey(),
            'size_id' => $talla->getKey(),
            'stock' => 2,
            'is_active' => true,
        ]);
    }

    $grande = $medir();

    expect($chico)->toBe($grande)
        ->and($chico)->toBeLessThanOrEqual(14);
});

test('la vista previa de la sección se sigue sirviendo', function () {
    $this->refreshApplicationIn('local');

    get('/_vista/mujer')->assertOk()->assertSeeHtml('name="categoria[]"');
});
