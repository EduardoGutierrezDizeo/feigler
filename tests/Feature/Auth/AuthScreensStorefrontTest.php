<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Pantallas de acceso con el estilo de la tienda
|--------------------------------------------------------------------------
| Las seis pantallas de acceso se pintan dentro de x-store.layout, sin el
| layout de invitado de Breeze, conservando action, método, CSRF, nombres de
| campo, old(), mensajes de error y estado.
*/

test('las pantallas públicas de acceso se pintan dentro del layout de la tienda', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSeeHtml('action="'.route('storefront.search').'"')
        ->assertDontSeeHtml('Ropa con carácter');
})->with([
    'login' => ['/login'],
    'register' => ['/register'],
    'forgot-password' => ['/forgot-password'],
    'reset-password' => ['/reset-password/token-de-prueba'],
]);

test('las pantallas de acceso para autenticados se pintan dentro del layout de la tienda', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/verify-email')
        ->assertOk()
        ->assertSeeHtml('action="'.route('storefront.search').'"')
        ->assertDontSeeHtml('Ropa con carácter');

    $this->actingAs($user)->get('/confirm-password')
        ->assertOk()
        ->assertSeeHtml('action="'.route('storefront.search').'"')
        ->assertDontSeeHtml('Ropa con carácter');
});

test('el login conserva su formulario, su método, su CSRF y sus campos', function () {
    $this->get('/login')
        ->assertSeeHtml('action="'.route('login').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"')
        ->assertSeeHtml('name="email"')
        ->assertSeeHtml('name="password"')
        ->assertSeeHtml('name="remember"');
});

test('el registro conserva su formulario, su método, su CSRF y sus campos', function () {
    $this->get('/register')
        ->assertSeeHtml('action="'.route('register').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"')
        ->assertSeeHtml('name="name"')
        ->assertSeeHtml('name="last_name"')
        ->assertSeeHtml('name="email"')
        ->assertSeeHtml('name="password"')
        ->assertSeeHtml('name="password_confirmation"')
        ->assertSeeHtml('name="phone"')
        ->assertSeeHtml('name="terms"');
});

test('la recuperación de contraseña conserva su formulario y su campo', function () {
    $this->get('/forgot-password')
        ->assertSeeHtml('action="'.route('password.email').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"')
        ->assertSeeHtml('name="email"');
});

test('el restablecimiento de contraseña conserva su formulario y sus campos', function () {
    $this->get('/reset-password/token-de-prueba')
        ->assertSeeHtml('action="'.route('password.store').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"')
        ->assertSeeHtml('name="token"')
        ->assertSeeHtml('value="token-de-prueba"')
        ->assertSeeHtml('name="email"')
        ->assertSeeHtml('name="password"')
        ->assertSeeHtml('name="password_confirmation"');
});

test('la verificación de correo conserva el reenvío y el cierre de sesión', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/verify-email')
        ->assertSeeHtml('action="'.route('verification.send').'"')
        ->assertSeeHtml('action="'.route('logout').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"');
});

test('la confirmación de contraseña conserva su formulario y su campo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/confirm-password')
        ->assertSeeHtml('action="'.route('password.confirm').'"')
        ->assertSeeHtml('method="POST"')
        ->assertSeeHtml('name="_token"')
        ->assertSeeHtml('name="password"');
});

test('el registro muestra la autorización de datos y los autocomplete correctos', function () {
    $this->get('/register')
        ->assertSeeHtml('name="terms"')
        ->assertSeeHtml('value="1"')
        ->assertSee(config('tienda.datos_personales_texto'))
        ->assertSeeHtml('autocomplete="given-name"')
        ->assertSeeHtml('autocomplete="family-name"')
        ->assertSeeHtml('autocomplete="email"')
        ->assertSeeHtml('autocomplete="new-password"')
        ->assertSeeHtml('autocomplete="tel"')
        ->assertSeeHtml('type="tel"')
        ->assertSeeHtml('inputmode="tel"');
});

test('un login inválido conserva el correo y muestra el error', function () {
    $this->from('/login')
        ->post('/login', ['email' => 'ana@example.com', 'password' => 'clave-incorrecta'])
        ->assertSessionHasErrors('email');

    $this->get('/login')
        ->assertSeeHtml('value="ana@example.com"')
        ->assertSee('Estas credenciales no coinciden con nuestros registros.');
});

test('un registro inválido conserva los datos y muestra el error', function () {
    $this->from('/register')
        ->post('/register', [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'email' => 'no-es-correo',
            'phone' => '3001234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => '1',
        ])
        ->assertSessionHasErrors('email');

    $this->get('/register')
        ->assertSeeHtml('value="Ana"')
        ->assertSeeHtml('value="Gómez"')
        ->assertSeeHtml('value="no-es-correo"')
        ->assertSee('Escribe un correo electrónico válido.');
});

test('el perfil y el panel de usuarios siguen con su propio layout', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('profile.edit'))
        ->assertOk()
        ->assertSeeHtml('href="'.route('dashboard').'"')
        ->assertDontSeeHtml('action="'.route('storefront.search').'"');

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Administración')
        ->assertDontSeeHtml('action="'.route('storefront.search').'"');
});

test('ninguna vista de acceso usa x-if', function () {
    $views = [
        'auth/login.blade.php',
        'auth/register.blade.php',
        'auth/forgot-password.blade.php',
        'auth/reset-password.blade.php',
        'auth/verify-email.blade.php',
        'auth/confirm-password.blade.php',
    ];

    foreach ($views as $view) {
        expect(file_get_contents(resource_path('views/'.$view)))->not->toContain('x-if');
    }
});
