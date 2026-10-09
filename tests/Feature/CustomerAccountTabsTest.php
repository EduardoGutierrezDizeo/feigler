<?php

use App\Models\Address;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Mi cuenta: pestañas y pestaña Perfil
|--------------------------------------------------------------------------
| La lista de pestañas vive en App\Enums\AccountTab y se renderiza en el
| servidor según `?tab=`. Con un solo case por ahora (Perfil), un valor ausente
| o inválido cae en la primera pestaña y la pestaña activa lleva
| aria-current="page".
*/

test('/cuenta muestra la pestaña Perfil con o sin tab y con un tab inválido', function (string $path) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get($path)
        ->assertOk()
        ->assertSee('Perfil')
        ->assertSeeHtml('href="'.route('account.index', ['tab' => 'perfil']).'"')
        ->assertSee('Guardar cambios');
})->with([
    'sin tab' => ['/cuenta'],
    'con tab perfil' => ['/cuenta?tab=perfil'],
    'con tab inválido' => ['/cuenta?tab=xyz'],
]);

test('la pestaña activa lleva aria-current page', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSeeHtml('aria-current="page"');
});

test('el saludo y el aviso de verificación siguen apareciendo con las pestañas', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->unverified()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSee('Hola, '.$user->name.'.')
        ->assertSee('Verifica tu correo')
        ->assertSee('Reenviar correo de verificación');
});

test('la pestaña Perfil muestra el correo como texto y el aviso, sin campo email', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSee('ana@example.com')
        ->assertDontSeeHtml('name="email"')
        ->assertSee('Para cambiar tu correo');
});

test('con un WhatsApp válido la pestaña Perfil ofrece el enlace wa.me', function (string $whatsapp) {
    config(['tienda.whatsapp' => $whatsapp]);

    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSeeHtml('href="https://wa.me/573001234567"');
})->with([
    'con espacios' => ['300 123 4567'],
    'con prefijo' => ['+57 300 123 4567'],
]);

test('sin WhatsApp válido no hay enlace wa.me', function (string $whatsapp) {
    config(['tienda.whatsapp' => $whatsapp]);

    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSee('Para cambiar tu correo')
        ->assertDontSeeHtml('href="https://wa.me/');
})->with([
    'marcador' => ['#'],
    'vacío' => [''],
    'letras' => ['abc'],
    'número corto' => ['300123456'],
]);

test('ni la vista ni las respuestas usan x-if', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertDontSee('x-if', escape: false);
});

test('/cuenta hace un número constante de consultas', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $medir = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('account.index'))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // La primera visita calienta el caché de roles de spatie; las dos medidas
    // se toman en caliente para comparar solo la petición.
    $medir();

    $conPocos = $medir();

    Address::factory()->count(10)->for($user)->create();

    $conMuchos = $medir();

    expect($conMuchos)->toBe($conPocos);
});
