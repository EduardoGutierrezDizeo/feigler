<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\SearchPage;
use App\Services\Storefront\SearchTerm;
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
| El buscador del encabezado tiene que encontrar en todas las secciones, por
| nombre, por referencia o por categoría, sin comerse los comodines de LIKE:
|
|   Hombre  Torso      Camisa Alba           SCT-001   50000  M-Negro(3)
|                     Camisa Blanca         SCT-002   60000  L-Azul(2)
|                     Remera 100%           SCT-003   40000  M-Negro(5)
|   Mujer   Vestidos   Vestido Fresco        SVE-001   45000  S-Azul(1)
|                     Vestido Antiguo       GALA-777  200000  M-Rojo(2)
|                     Camisa Doble__Azul    SVE-002   55000  M-Azul(2)
|   Niños   Infantil   Conjunto Nino         SNI-001   30000  XS-Azul(3)
|
| «Camisa» vive en Hombre y en Mujer (prueba las tres secciones). «Infantil»
| solo está en el nombre de la categoría de Conjunto Nino; «GALA» solo en la
| referencia de Vestido Antiguo. Doble__Azul y «Remera 100%» llevan comodines
| de LIKE escritos en el nombre.
*/

/**
 * Una prenda visible de la categoría, con sus variantes activas.
 * Cada variante se da como [Size, Color, stock].
 */
function searchProduct(Category $category, string $reference, string $name, string $slug, int $price, Carbon $createdAt, array $variants): Product
{
    $product = Product::factory()->for($category)->create([
        'reference' => $reference,
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

function searchScene(): array
{
    $camisas = numberedCategory('SCT', 'Torso');
    $vestidos = numberedCategory('SVE', 'Vestidos', StoreSection::Mujer);
    $infantil = numberedCategory('SNI', 'Infantil', StoreSection::Ninos);

    $mCamisas = $camisas->sizes()->create(['name' => 'M', 'order' => 1]);
    $lCamisas = $camisas->sizes()->create(['name' => 'L', 'order' => 2]);
    $sVestidos = $vestidos->sizes()->create(['name' => 'S', 'order' => 1]);
    $mVestidos = $vestidos->sizes()->create(['name' => 'M', 'order' => 2]);
    $xsInfantil = $infantil->sizes()->create(['name' => 'XS', 'order' => 1]);

    $negro = Color::factory()->create(['name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG', 'order' => 1]);
    $azul = Color::factory()->create(['name' => 'Azul', 'hex' => '#0000ff', 'code' => 'AZU', 'order' => 2]);
    $rojo = Color::factory()->create(['name' => 'Rojo', 'hex' => '#ff0000', 'code' => 'ROJ', 'order' => 3]);

    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);

    $alba = searchProduct($camisas, 'SCT-001', 'Camisa Alba', 'camisa-alba', 50000, now(), [
        [$mCamisas, $negro, 3],
    ]);
    $alba->materials()->attach($algodon, ['percentage' => 100]);

    searchProduct($camisas, 'SCT-002', 'Camisa Blanca', 'camisa-blanca', 60000, now()->subDays(10), [
        [$lCamisas, $azul, 2],
    ]);

    searchProduct($camisas, 'SCT-003', 'Remera 100%', 'remera-100', 40000, now()->subDays(5), [
        [$mCamisas, $negro, 5],
    ]);

    searchProduct($vestidos, 'SVE-001', 'Vestido Fresco', 'vestido-fresco', 45000, now()->subDays(2), [
        [$sVestidos, $azul, 1],
    ]);

    searchProduct($vestidos, 'GALA-777', 'Vestido Antiguo', 'vestido-antiguo', 200000, now()->subDays(60), [
        [$mVestidos, $rojo, 2],
    ]);

    searchProduct($vestidos, 'SVE-002', 'Camisa Doble__Azul', 'camisa-doble-azul', 55000, now()->subDays(3), [
        [$mVestidos, $azul, 2],
    ]);

    searchProduct($infantil, 'SNI-001', 'Conjunto Nino', 'conjunto-nino', 30000, now()->subDays(1), [
        [$xsInfantil, $azul, 3],
    ]);

    return compact('camisas', 'vestidos', 'infantil', 'negro', 'azul', 'rojo', 'algodon');
}

/**
 * La página de resultados, con el mismo alcance que la ruta le monta.
 */
function searchPayload(string $query): array
{
    parse_str($query, $params);

    $request = Request::create('/buscar', 'GET', $params);

    return app(SearchPage::class)->for(SectionFilters::fromRequest($request));
}

test('el encabezado busca por la ruta nombrada y el resultado lo rellena', function () {
    searchScene();

    expect(route('storefront.search'))->toBe(url('/buscar'));

    get('/tienda')->assertOk()
        ->assertSeeHtml('action="'.url('/buscar').'"')
        ->assertSeeHtml('name="q"');

    get('/buscar?q=camisa')->assertOk()
        ->assertSeeHtml('type="search" name="q" value="camisa"');
});

test('sin texto que buscar la página pide escribirlo y no lista ni filtra', function () {
    searchScene();

    foreach (['', 'q=', 'q=%20%20', 'q=a', 'q[]=camisa'] as $query) {
        get('/buscar?'.$query)->assertOk()
            ->assertSee('Escribe qué prenda buscas.')
            ->assertDontSee('Mostrar más')
            ->assertDontSeeHtml('name="categoria[]"')
            ->assertDontSeeHtml('name="seccion[]"');
    }

    get('/buscar')->assertOk()->assertSee('Escribe qué prenda buscas.');
});

test('encuentra por nombre en todas las secciones y titula con el texto buscado', function () {
    searchScene();

    $payload = searchPayload('q=camisa');
    $nombres = array_column($payload['products'], 'name');

    expect($payload['total'])->toBe(3)
        ->and($nombres)->toContain('Camisa Alba', 'Camisa Blanca', 'Camisa Doble__Azul')
        ->and($payload['section']['label'])->toBe('Resultados para “camisa”')
        ->and($payload['searchQuery'])->toBe('camisa');

    get('/buscar?q=camisa')->assertOk()
        ->assertSee('Resultados para “camisa”')
        ->assertSee('Camisa Alba')
        ->assertSee('Camisa Doble__Azul')
        ->assertDontSee('Vestido Antiguo');
});

test('encuentra por referencia y por nombre de categoría aunque el nombre no lo diga', function () {
    searchScene();

    $referencia = searchPayload('q=gala');

    expect(array_column($referencia['products'], 'name'))->toBe(['Vestido Antiguo']);

    $categoria = searchPayload('q=infantil');

    expect(array_column($categoria['products'], 'name'))->toBe(['Conjunto Nino'])
        ->and($categoria['total'])->toBe(1);
});

test('cada palabra tiene que coincidir, en cualquiera de los tres campos', function () {
    searchScene();

    $unaPalabraEnNombre = searchPayload('q=camisa');
    $dosPalabras = searchPayload('q=camisa blanca');

    expect($unaPalabraEnNombre['total'])->toBe(3)
        ->and(array_column($dosPalabras['products'], 'name'))->toBe(['Camisa Blanca']);
});

test('los comodines de LIKE escritos se buscan como texto, no como comodines', function () {
    searchScene();

    $porcentaje = searchPayload('q=100%');
    $guionesBajos = searchPayload('q=__');

    expect(array_column($porcentaje['products'], 'name'))->toBe(['Remera 100%'])
        ->and(array_column($guionesBajos['products'], 'name'))->toBe(['Camisa Doble__Azul']);
});

test('sin coincidencias avisa y enlaza la tienda; con filtros que la vacían, no', function () {
    $escena = searchScene();

    get('/buscar?q=zzz')->assertOk()
        ->assertSee('No encontramos prendas para “zzz”.')
        ->assertSeeHtml('href="'.url('/tienda').'"')
        ->assertDontSee('Limpiar filtros');

    $vaciada = searchPayload('q=camisa&color[]='.$escena['negro']->getKey().'&talla[]=L');

    expect($vaciada['total'])->toBe(0)
        ->and($vaciada['products'])->toBe([]);

    get('/buscar?q=camisa&color[]='.$escena['negro']->getKey().'&talla[]=L')->assertOk()
        ->assertSee('No hay prendas con estos filtros')
        ->assertSee('Limpiar filtros')
        ->assertDontSee('No encontramos prendas para');
});

test('los filtros se aplican sobre la búsqueda y el texto va primero y se conserva', function () {
    $escena = searchScene();

    $filtrada = searchPayload('q=camisa&color[]='.$escena['negro']->getKey());

    expect($filtrada['total'])->toBe(1)
        ->and(array_column($filtrada['products'], 'name'))->toBe(['Camisa Alba'])
        ->and(array_column($filtrada['active'], 'label'))->toBe(['camisa', 'Negro'])
        ->and($filtrada['active'][0]['removeUrl'])->toBe(url('/buscar'))
        ->and($filtrada['active'][1]['removeUrl'])->toBe(url('/buscar').'?q=camisa')
        ->and($filtrada['section']['url'])->toBe(url('/buscar').'?q=camisa')
        ->and($filtrada['clearUrl'])->toBe(url('/buscar').'?q=camisa')
        ->and($filtrada['filters']['price']['min'])->toBe(50000)
        ->and($filtrada['filters']['price']['max'])->toBe(60000);

    get('/buscar?q=camisa&color[]='.$escena['negro']->getKey())->assertOk()
        ->assertSeeHtml('<input type="hidden" name="q" value="camisa">');
});

test('el texto sobrevive en el «Mostrar más» y en el enlace de la sección', function () {
    $escena = searchScene();
    $size = $escena['camisas']->sizes()->first();

    foreach (range(1, 13) as $n) {
        searchProduct($escena['camisas'], 'SCT-1'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays($n), [
            [$size, $escena['negro'], 3],
        ]);
    }

    $payload = searchPayload('q=camisa');

    expect($payload['total'])->toBe(16)
        ->and($payload['shown'])->toBe(12)
        ->and($payload['nextUrl'])->toBe(url('/buscar').'?q=camisa&mostrar=24');
});

test('con dieciséis coincidencias «Mostrar más» acumula y no ofrece siguiente', function () {
    $escena = searchScene();
    $size = $escena['camisas']->sizes()->first();

    foreach (range(1, 13) as $n) {
        searchProduct($escena['camisas'], 'SCT-1'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'Camisa Extra '.$n, 'camisa-extra-'.$n, 50000 + $n, now()->subDays($n), [
            [$size, $escena['negro'], 3],
        ]);
    }

    $payload = searchPayload('q=camisa&mostrar=24');

    expect($payload['total'])->toBe(16)
        ->and($payload['shown'])->toBe(16)
        ->and($payload['nextUrl'])->toBeNull();
});

test('el texto buscado sale escapado en el título, el chip y el encabezado', function () {
    searchScene();

    $malicioso = '<script>alert(1)</script>';
    $escapado = '&lt;script&gt;alert(1)&lt;/script&gt;';

    get('/buscar?q='.urlencode($malicioso))->assertOk()
        ->assertSeeHtml('<title>Resultados para “'.$escapado.'” · Feigler</title>')
        ->assertSeeHtml('aria-label="Quitar filtro '.$escapado.'"')
        ->assertSeeHtml('name="q" value="'.$escapado.'"')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('un texto muy largo se acota y uno de muchas palabras no da error', function () {
    searchScene();

    $larguisimo = str_repeat('a', 80);

    expect(SearchTerm::from($larguisimo)->text)->toBe(str_repeat('a', 60))
        ->and(SearchTerm::from('uno dos tres cuatro cinco seis siete')->words)
        ->toBe(['uno', 'dos', 'tres', 'cuatro', 'cinco']);

    get('/buscar?q='.$larguisimo)->assertOk()
        ->assertSee('No encontramos prendas para “'.str_repeat('a', 60).'”.');

    get('/buscar?q=uno+dos+tres+cuatro+cinco+seis+siete')->assertOk();
});

test('la talla y el color de la búsqueda se exigen sobre la misma variante', function () {
    $escena = searchScene();
    $m = $escena['camisas']->sizes()->where('name', 'M')->first();
    $l = $escena['camisas']->sizes()->where('name', 'L')->first();

    searchProduct($escena['camisas'], 'SCT-010', 'Camisa Mosaico', 'camisa-mosaico', 52000, now()->subDays(4), [
        [$m, $escena['negro'], 1],
        [$l, $escena['azul'], 1],
    ]);

    $combinada = searchPayload('q=camisa&talla[]=M&color[]='.$escena['azul']->getKey());

    expect($combinada['total'])->toBe(1)
        ->and(array_column($combinada['products'], 'name'))->toBe(['Camisa Doble__Azul'])
        ->and(array_column($combinada['products'], 'name'))->not->toContain('Camisa Mosaico');
});

test('los conteos y el precio se calculan sobre la búsqueda, no sobre todo el catálogo', function () {
    searchScene();

    $payload = searchPayload('q=camisa');

    expect($payload['total'])->toBe(3)
        ->and(collect($payload['filters']['sections'])->pluck('count', 'value')->all())
        ->toBe(['hombre' => 2, 'mujer' => 1, 'ninos' => 0])
        ->and(collect($payload['filters']['categories'])->pluck('count', 'label')->all())
        ->toBe(['Torso' => 2, 'Vestidos' => 1])
        ->and(collect($payload['filters']['materials'])->pluck('count', 'label')->all())
        ->toBe(['Algodón' => 1])
        ->and($payload['filters']['stock']['count'])->toBe(3)
        ->and($payload['filters']['price']['min'])->toBe(50000)
        ->and($payload['filters']['price']['max'])->toBe(60000);
});

test('las prendas que la tienda no vende no aparecen ni cuentan en la búsqueda', function () {
    $escena = searchScene();

    Product::factory()->for($escena['camisas'])->inactive()->create([
        'reference' => 'SCT-900',
        'name' => 'Camisa Apagada',
        'slug' => 'camisa-apagada',
        'base_price' => 50000,
    ]);

    $huerfana = Product::factory()->for($escena['camisas'])->create([
        'reference' => 'SCT-901',
        'name' => 'Camisa Huerfana',
        'slug' => 'camisa-huerfana',
        'status' => 'active',
        'base_price' => 50000,
    ]);
    ProductVariant::factory()->for($huerfana)->inSize($escena['camisas']->sizes()->where('name', 'M')->first())->inactive()->create([
        'color_id' => $escena['negro']->getKey(),
        'stock' => 5,
    ]);

    $payload = searchPayload('q=camisa');

    expect($payload['total'])->toBe(3)
        ->and(array_column($payload['products'], 'name'))->not->toContain('Camisa Apagada', 'Camisa Huerfana')
        ->and(collect($payload['filters']['categories'])->pluck('count', 'label')->all())
        ->toBe(['Torso' => 2, 'Vestidos' => 1]);

    get('/buscar?q=camisa')->assertOk()
        ->assertDontSee('Camisa Apagada')
        ->assertDontSee('Camisa Huerfana');
});

test('el número de consultas del buscador no depende del tamaño del catálogo', function () {
    $escena = searchScene();

    $combinada = 'q=camisa&color[]='.$escena['negro']->getKey().'&talla[]=M';

    $medir = function () use ($combinada): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        searchPayload($combinada);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $chico = $medir();

    $size = $escena['camisas']->sizes()->where('name', 'M')->first();

    foreach (range(1, 14) as $n) {
        searchProduct($escena['camisas'], 'SCT-2'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'Camisa Extra '.$n, 'camisa-extra-'.$n, 51000 + $n, now()->subDays(40 + $n), [
            [$size, $escena['negro'], 3],
        ]);
    }

    expect($medir())->toBe($chico);
});

test('ni la vista del buscador ni sus respuestas usan x-if', function () {
    searchScene();

    expect(file_get_contents(resource_path('views/storefront/section.blade.php')))->not->toContain('x-if');

    foreach (['/buscar', '/buscar?q=camisa', '/buscar?q=zzz'] as $uri) {
        $html = get($uri)->assertOk()->getContent();

        expect(preg_match('/(?<!template )x-if/', $html))->toBe(0);
    }
});

test('el formulario del buscador vive en el inicio, una sección y la ficha, escritorio y móvil', function () {
    $this->app->instance('env', 'local');

    searchScene();

    foreach (['/', '/hombre', '/producto/camisa-alba'] as $uri) {
        $html = get($uri)->assertOk()->getContent();

        expect(substr_count($html, 'action="'.url('/buscar').'"'))->toBe(2)
            ->and(substr_count($html, 'name="q"'))->toBe(2);
    }
});

test('el buscador no rompe las demás páginas de la tienda', function () {
    searchScene();

    foreach (['/hombre', '/mujer', '/ninos', '/novedades', '/tienda'] as $uri) {
        get($uri)->assertOk();
    }

    $this->refreshApplicationIn('local');

    get('/_vista/mujer')->assertOk();
});
