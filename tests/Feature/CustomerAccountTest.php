<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('un registro válido crea un cliente activo y sin verificar y lo lleva a Mi cuenta', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => '300 123 4567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('account.index'));

    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();

    expect($user->name)->toBe('Ana')
        ->and($user->last_name)->toBe('Gómez')
        ->and($user->phone)->toBe('3001234567')
        ->and($user->hasRole('cliente'))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->terms_accepted_at)->not->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('normaliza el teléfono a diez dígitos', function (string $phone) {
    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => $phone,
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertRedirect(route('account.index'));

    expect(User::query()->where('email', 'ana@example.com')->value('phone'))->toBe('3001234567');
})->with([
    'con espacios' => ['300 123 4567'],
    'con prefijo' => ['+57 300 123 4567'],
    'con paréntesis' => ['(300) 123-4567'],
    'sin formato' => ['3001234567'],
]);

test('rechaza los teléfonos que no son celulares colombianos', function (string $phone) {
    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => $phone,
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasErrors('phone');

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
    $this->assertGuest();
})->with([
    'no empieza por 3' => ['1234567890'],
    'tiene nueve dígitos' => ['300123456'],
    'fijo de Bogotá' => ['6011234567'],
    'texto' => ['texto'],
]);

test('sin autorizar los datos no se crea la cuenta', function () {
    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('terms');

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('ignora los campos extra que vengan en el registro', function () {
    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'role' => 'admin',
        'roles' => ['admin'],
        'is_active' => false,
        'email_verified_at' => now()->toDateTimeString(),
        'terms_accepted_at' => '2000-01-01 00:00:00',
    ])->assertRedirect(route('account.index'));

    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();

    expect($user->hasRole('cliente'))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->terms_accepted_at)->not->toBe('2000-01-01 00:00:00');
});

test('rechaza correo repetido, contraseña débil y confirmación distinta', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'Ana@Example.com',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasErrors('email');

    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'otra@example.com',
        'phone' => '3001234567',
        'password' => '123',
        'password_confirmation' => '123',
        'terms' => '1',
    ])->assertSessionHasErrors('password');

    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'tres@example.com',
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'otra-clave',
        'terms' => '1',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(1);
    $this->assertGuest();
});

test('el séptimo registro en un minuto recibe 429', function () {
    $payload = [
        'name' => '',
        'last_name' => '',
        'email' => 'no-es-correo',
        'phone' => 'x',
        'password' => 'x',
        'password_confirmation' => 'x',
    ];

    foreach (range(1, 6) as $attempt) {
        $this->post('/register', $payload)->assertStatus(302);
    }

    $this->post('/register', $payload)->assertStatus(429);
});

test('el login lleva a cada rol a su sitio', function (string $role, string $destination) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $user->assignRole($role);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route($destination));
})->with([
    'admin' => ['admin', 'admin.dashboard'],
    'vendedor' => ['vendedor', 'staff.placeholder'],
    'bodega' => ['bodega', 'staff.placeholder'],
    'contador' => ['contador', 'staff.placeholder'],
    'cliente' => ['cliente', 'account.index'],
]);

test('un usuario sin rol sigue yendo al dashboard', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
});

test('un cliente con una URL intended vuelve a esa URL', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $user->assignRole('cliente');

    $this->get(route('profile.edit'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('profile.edit'));
});

test('un cliente autenticado no vuelve al login, al registro ni al dashboard', function (string $path) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get($path)->assertRedirect(route('account.index'));
})->with(['/login', '/register', '/dashboard']);

test('un invitado no entra a Mi cuenta', function () {
    $this->get(route('account.index'))->assertRedirect(route('login'));
});

test('Mi cuenta solo muestra el aviso de verificación al cliente sin verificar', function () {
    $this->seed(RoleSeeder::class);

    $unverified = User::factory()->unverified()->create();
    $unverified->assignRole('cliente');

    $this->actingAs($unverified)->get(route('account.index'))
        ->assertOk()
        ->assertSee('Mi cuenta')
        ->assertSee('Verifica tu correo')
        ->assertSee('Reenviar correo de verificación')
        ->assertSeeHtml('action="'.route('verification.send').'"');

    $verified = User::factory()->create();
    $verified->assignRole('cliente');

    $this->actingAs($verified)->get(route('account.index'))
        ->assertOk()
        ->assertDontSee('Verifica tu correo')
        ->assertDontSee('Reenviar correo de verificación')
        ->assertDontSeeHtml('action="'.route('verification.send').'"');
});

test('el personal no entra a Mi cuenta', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $this->actingAs($vendedor)->get(route('account.index'))->assertForbidden();
});

test('el enlace firmado de verificación lleva al cliente a Mi cuenta', function () {
    $this->seed(RoleSeeder::class);
    Event::fake();

    $user = User::factory()->unverified()->create();
    $user->assignRole('cliente');

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('account.index'))
        ->assertSessionHas('status', 'verified');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('un cliente ya verificado que abre el enlace también va a Mi cuenta', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('account.index'))
        ->assertSessionHas('status', 'verified');
});

test('el reenvío de verificación del cliente vuelve a Mi cuenta', function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->post(route('verification.send'))
        ->assertRedirect(route('account.index'))
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('la vista de registro pide apellido, teléfono y autorización', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('Crea tu cuenta de Feigler')
        ->assertSeeHtml('name="last_name"')
        ->assertSeeHtml('name="phone"')
        ->assertSeeHtml('name="terms"')
        ->assertSee(config('tienda.datos_personales_texto'))
        ->assertDontSee('x-if', escape: false);
});

test('el personal sigue llegando a su panel', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $this->actingAs($vendedor)->get(route('staff.placeholder'))->assertOk();
});
