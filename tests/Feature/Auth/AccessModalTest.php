<?php

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Modal de acceso de la tienda
|--------------------------------------------------------------------------
| El modal reúne las tres vistas de acceso (login, registro y recuperación)
| reutilizando los parciales de las páginas. Un invitado lo abre desde el
| «Cuenta» del encabezado; el servidor lo deja abierto en la vista correcta
| cuando un envío falla (bolsas login/register/forgot) o cuando la recuperación
| tiene éxito (marca access_modal). Las páginas de acceso no lo pintan.
*/

function fichaDeModal(): Product
{
    $category = numberedCategory('AM', 'Camisas Modal');
    $size = $category->sizes()->create(['name' => 'M', 'order' => 1, 'is_active' => true]);
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'code' => 'NEG', 'hex' => '#000000']);

    $product = Product::factory()->for($category)->create([
        'name' => 'Camisa Modal',
        'slug' => 'camisa-modal',
        'status' => 'active',
        'base_price' => 30000,
    ]);

    ProductVariant::factory()->for($product)->create([
        'color_id' => $color->getKey(),
        'size_id' => $size->getKey(),
        'is_active' => true,
        'stock' => 5,
    ]);

    return $product;
}

/**
 * Comprueba que el HTML trae el modal con las tres vistas y sus formularios.
 */
function assertModalDeAcceso(string $html): void
{
    expect($html)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('action="'.route('login').'"')
        ->toContain('action="'.route('register').'"')
        ->toContain('action="'.route('password.email').'"')
        ->toContain('<input type="hidden" name="access_modal" value="login">')
        ->toContain('<input type="hidden" name="access_modal" value="register">')
        ->toContain('<input type="hidden" name="access_modal" value="forgot">')
        ->toContain('name="return_to"');
}

function assertIdsUnicos(string $html): void
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);

    $ids = collect(iterator_to_array($dom->getElementsByTagName('*')))
        ->map(fn (DOMElement $el) => $el->getAttribute('id'))
        ->filter()
        ->values();

    expect($ids->count())->toBe($ids->unique()->count());
}

function htmlDelModal(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);

    $node = (new DOMXPath($dom))->query('//*[@role="dialog"]')->item(0);

    expect($node)->not->toBeNull();

    return $dom->saveHTML($node);
}

test('un invitado ve el acceso Cuenta y el modal en el inicio, una sección y la ficha', function () {
    $this->app->instance('env', 'local');

    $inicio = get('/')->assertOk()->getContent();
    expect($inicio)->toContain('href="'.route('login').'"');
    assertModalDeAcceso($inicio);
    assertIdsUnicos($inicio);

    $seccion = get('/hombre')->assertOk()->getContent();
    assertModalDeAcceso($seccion);
    assertIdsUnicos($seccion);

    $product = fichaDeModal();
    assertModalDeAcceso(get('/producto/'.$product->slug)->assertOk()->getContent());
});

test('un cliente autenticado no tiene el modal y conserva su desplegable', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/hombre')
        ->assertOk()
        ->assertDontSeeHtml('role="dialog"')
        ->assertDontSeeHtml('name="access_modal"')
        ->assertSee('Mi cuenta')
        ->assertSee('Cerrar sesión');
});

test('las páginas de acceso no incluyen el modal', function () {
    foreach (['/login', '/register', '/forgot-password', '/reset-password/token-de-prueba'] as $path) {
        $response = $this->get($path)->assertOk();

        $response->assertDontSeeHtml('role="dialog"')
            ->assertDontSeeHtml('name="access_modal"')
            ->assertSeeHtml('action="'.route('storefront.search').'"');

        assertIdsUnicos($response->getContent());
    }

    $user = User::factory()->unverified()->create();

    foreach (['/verify-email', '/confirm-password'] as $path) {
        $this->actingAs($user)->get($path)
            ->assertOk()
            ->assertDontSeeHtml('role="dialog"')
            ->assertDontSeeHtml('name="access_modal"');
    }
});

test('un login inválido desde el modal vuelve con la bolsa login y el modal abierto en login', function () {
    $this->from('/hombre')
        ->post('/login', [
            'email' => 'ana@example.com',
            'password' => 'clave-incorrecta',
            'access_modal' => 'login',
            'return_to' => '/hombre',
        ])
        ->assertSessionHasErrors('email', null, 'login');

    $this->get('/hombre')
        ->assertOk()
        ->assertSeeHtml("x-data=\"accessModal({ view: 'login', open: true })\"")
        ->assertSee('Estas credenciales no coinciden con nuestros registros.')
        ->assertSeeHtml('value="ana@example.com"');
});

test('un registro inválido desde el modal vuelve con la bolsa register y el modal abierto en registro', function () {
    $this->from('/hombre')
        ->post('/register', [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'email' => 'no-es-correo',
            'phone' => '3001234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => '1',
            'access_modal' => 'register',
        ])
        ->assertSessionHasErrors('email', null, 'register');

    $this->get('/hombre')
        ->assertOk()
        ->assertSeeHtml("x-data=\"accessModal({ view: 'register', open: true })\"")
        ->assertSeeHtml('value="Ana"')
        ->assertSee('Escribe un correo electrónico válido.');
});

test('la recuperación desde el modal usa la bolsa forgot y reabre esa vista', function () {
    $this->from('/hombre')
        ->post('/forgot-password', [
            'email' => 'no-es-correo',
            'access_modal' => 'forgot',
        ])
        ->assertSessionHasErrors('email', null, 'forgot');

    $this->get('/hombre')
        ->assertOk()
        ->assertSeeHtml("x-data=\"accessModal({ view: 'forgot', open: true })\"");
});

test('la recuperación correcta desde el modal envía el correo y reabre la vista con su estado', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->from('/hombre')
        ->post('/forgot-password', [
            'email' => $user->email,
            'access_modal' => 'forgot',
        ])
        ->assertRedirect('/hombre')
        ->assertSessionHas('status')
        ->assertSessionHas('access_modal', 'forgot');

    Notification::assertSentTo($user, ResetPassword::class);

    $this->get('/hombre')
        ->assertOk()
        ->assertSeeHtml("x-data=\"accessModal({ view: 'forgot', open: true })\"")
        ->assertSee('Le hemos enviado por correo electrónico el enlace para restablecer su contraseña.');
});

test('los envíos sin access_modal siguen usando la bolsa por defecto', function () {
    $this->from('/login')
        ->post('/login', ['email' => 'ana@example.com', 'password' => 'mala'])
        ->assertSessionHasErrors('email', null, 'default');
});

test('un cliente con return_to válido vuelve a esa página', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $user->assignRole('cliente');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'access_modal' => 'login',
        'return_to' => '/tienda?talla[]=M',
    ])->assertRedirect('/tienda?talla[]=M');
});

test('un cliente con return_to inseguro cae en Mi cuenta', function (string $returnTo) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $user->assignRole('cliente');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'access_modal' => 'login',
        'return_to' => $returnTo,
    ])->assertRedirect(route('account.index', absolute: false));
})->with([
    'url absoluta' => ['https://evil.com'],
    'protocolo relativo' => ['//evil.com'],
    'barra invertida' => ['/\\evil.com'],
    'pantalla de acceso' => ['/login'],
    'salto de línea' => ["/tienda\nmalo"],
]);

test('el personal y los usuarios sin rol ignoran return_to', function (?string $role, string $destination) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);

    if ($role !== null) {
        $user->assignRole($role);
    }

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'access_modal' => 'login',
        'return_to' => '/hombre',
    ])->assertRedirect(route($destination));
})->with([
    'admin' => ['admin', 'admin.dashboard'],
    'vendedor' => ['vendedor', 'staff.placeholder'],
    'bodega' => ['bodega', 'staff.placeholder'],
    'contador' => ['contador', 'staff.placeholder'],
    'sin rol' => [null, 'dashboard'],
]);

test('un registro válido desde el modal sigue llevando a Mi cuenta', function () {
    $response = $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'access_modal' => 'register',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('account.index'));
});

test('el modal no usa x-if y el Cuenta de invitado está en escritorio y móvil', function () {
    $html = $this->get('/hombre')->assertOk()->getContent();

    expect(htmlDelModal($html))->not->toContain('x-if')
        ->and(substr_count($html, 'open-access'))->toBeGreaterThanOrEqual(2);
});

test('el modal no agrega consultas por producto a la sección', function () {
    $category = numberedCategory('AQ', 'Abrigos Modal');
    $size = $category->sizes()->create(['name' => 'M', 'order' => 1, 'is_active' => true]);
    $color = Color::factory()->create(['is_active' => true]);

    $crear = function (int $n) use ($category, $size, $color): void {
        $product = Product::factory()->for($category)->create([
            'name' => 'Prenda Modal '.$n,
            'slug' => 'prenda-modal-'.$n,
            'status' => 'active',
            'base_price' => 20000 + $n,
        ]);

        ProductVariant::factory()->for($product)->create([
            'color_id' => $color->getKey(),
            'size_id' => $size->getKey(),
            'is_active' => true,
            'stock' => 3,
        ]);
    };

    $crear(1);

    $medir = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        get('/hombre')->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $conPocos = $medir();

    foreach (range(2, 15) as $n) {
        $crear($n);
    }

    $conMuchos = $medir();

    expect($conMuchos)->toBe($conPocos);
});
