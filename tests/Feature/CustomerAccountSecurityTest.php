<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Mi cuenta: pestaña Seguridad (cambio de contraseña)
|--------------------------------------------------------------------------
| La pestaña reutiliza la ruta password.update sin tocar PasswordController:
| el formulario la apunta con PUT y @csrf, lee la bolsa updatePassword y el
| back() del controlador vuelve a /cuenta?tab=seguridad por el Referer.
*/

test('/cuenta?tab=seguridad muestra la pestaña Seguridad activa y las dos pestañas en orden', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertSeeInOrder(['Perfil', 'Seguridad'])
        ->assertSeeHtml('href="'.route('account.index', ['tab' => 'perfil']).'"')
        ->assertSeeHtml('href="'.route('account.index', ['tab' => 'seguridad']).'"')
        ->assertSeeHtml('aria-current="page"');
});

test('el formulario de la pestaña Seguridad apunta a password.update con PUT y sus tres campos', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertSeeHtml('action="'.route('password.update').'"')
        ->assertSeeHtml('name="_method" value="PUT"')
        ->assertSeeHtml('type="password"')
        ->assertSeeInOrder([
            'name="current_password"',
            'autocomplete="current-password"',
            'name="password"',
            'autocomplete="new-password"',
            'name="password_confirmation"',
            'autocomplete="new-password"',
        ]);
});

test('un cliente cambia su contraseña desde la pestaña Seguridad y entra con la nueva', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=seguridad')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'nueva-clave',
            'password_confirmation' => 'nueva-clave',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/cuenta?tab=seguridad')
        ->assertSessionHas('status', 'password-updated');

    expect(Hash::check('nueva-clave', $user->refresh()->password))->toBeTrue();

    $this->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertSee('Tu contraseña se actualizó.');

    Auth::logout();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'nueva-clave',
    ])
        ->assertRedirect(route('account.index'));

    $this->assertAuthenticatedAs($user);
});

test('con la contraseña actual errónea no cambia el hash y se ve el error', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=seguridad')
        ->put('/password', [
            'current_password' => 'incorrecta',
            'password' => 'nueva-clave',
            'password_confirmation' => 'nueva-clave',
        ])
        ->assertRedirect('/cuenta?tab=seguridad')
        ->assertSessionHasErrorsIn('updatePassword', 'current_password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

    $this->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertSeeHtml('La contraseña es incorrecta.');
});

test('una contraseña nueva débil o que no coincide no cambia nada y muestra su error', function (array $payload, string $message) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=seguridad')
        ->put('/password', $payload)
        ->assertRedirect('/cuenta?tab=seguridad')
        ->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

    $this->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertSeeHtml($message);
})->with([
    'débil' => [
        [
            'current_password' => 'password',
            'password' => '123',
            'password_confirmation' => '123',
        ],
        'El campo contraseña debe contener al menos 8 caracteres.',
    ],
    'confirmación distinta' => [
        [
            'current_password' => 'password',
            'password' => 'nueva-clave',
            'password_confirmation' => 'otra-clave',
        ],
        'La confirmación de contraseña no coincide.',
    ],
]);

test('los campos de contraseña no repiten su valor tras un error', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=seguridad')
        ->put('/password', [
            'current_password' => 'clave-equivocada',
            'password' => 'clave-super-secreta',
            'password_confirmation' => 'clave-super-secreta',
        ])
        ->assertRedirect('/cuenta?tab=seguridad');

    $this->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertDontSee('clave-super-secreta')
        ->assertDontSee('clave-equivocada');
});

test('un invitado que envía PUT /password va al login', function () {
    $this->put('/password', [
        'current_password' => 'password',
        'password' => 'nueva-clave',
        'password_confirmation' => 'nueva-clave',
    ])->assertRedirect(route('login'));
});

test('el personal no ve la pestaña Seguridad de Mi cuenta', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $this->actingAs($vendedor)->get('/cuenta?tab=seguridad')->assertForbidden();
});

test('ni la vista ni las respuestas de Seguridad usan x-if', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get('/cuenta?tab=seguridad')
        ->assertOk()
        ->assertDontSee('x-if', escape: false);
});

test('la pestaña Seguridad cuesta las mismas consultas que la pestaña Perfil', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $medir = function (string $url) use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get($url)->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // La primera visita calienta el caché de roles de spatie; se miden en caliente.
    $medir('/cuenta?tab=perfil');

    expect($medir('/cuenta?tab=seguridad'))->toBe($medir('/cuenta?tab=perfil'));
});
