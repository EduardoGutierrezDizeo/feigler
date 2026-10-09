<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Pestaña Perfil: actualización del cliente
|--------------------------------------------------------------------------
| El cliente solo edita nombre, apellido y teléfono vía PATCH
| /cuenta/perfil. El correo, el rol y el estado quedan fuera incluso si
| llegan en el envío, y /profile queda cerrado para el cliente.
*/

test('el cliente actualiza su perfil y guarda el teléfono normalizado', function (string $phone) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'phone' => '3000000000',
    ]);
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => '  Ana María ',
            'last_name' => '  Pérez ',
            'phone' => $phone,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.index', ['tab' => 'perfil']))
        ->assertSessionHas('status', 'profile-updated');

    $user->refresh();

    expect($user->name)->toBe('Ana María')
        ->and($user->last_name)->toBe('Pérez')
        ->and($user->phone)->toBe('3001234567');
})->with([
    'con espacios' => ['300 123 4567'],
    'con prefijo' => ['+57 300 123 4567'],
]);

test('al guardar el perfil redirige a la pestaña Perfil con el mensaje', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'phone' => '3001234567',
        ])
        ->assertRedirect(route('account.index', ['tab' => 'perfil']));

    $this->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSee('Tus datos se actualizaron.');
});

test('un perfil inválido no actualiza nada y se queda en la pestaña Perfil', function (array $invalid, string $field) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'phone' => '3001234567',
    ]);
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=perfil')
        ->patch(route('account.profile.update'), array_merge([
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'phone' => '3001234567',
        ], $invalid))
        ->assertRedirect('/cuenta?tab=perfil')
        ->assertSessionHasErrors($field);

    $user->refresh();

    expect($user->name)->toBe('Ana')
        ->and($user->last_name)->toBe('Gómez')
        ->and($user->phone)->toBe('3001234567');
})->with([
    'teléfono inválido' => [['phone' => '1234567890'], 'phone'],
    'nombre vacío' => [['name' => ''], 'name'],
    'apellido vacío' => [['last_name' => ''], 'last_name'],
]);

test('los errores vuelven con old() y los mensajes en español', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'phone' => '3001234567',
    ]);
    $user->assignRole('cliente');

    $this->actingAs($user)
        ->from('/cuenta?tab=perfil')
        ->patch(route('account.profile.update'), [
            'name' => '',
            'last_name' => 'Gómez',
            'phone' => '700',
        ])
        ->assertRedirect('/cuenta?tab=perfil')
        ->assertSessionHasErrors(['name', 'phone']);

    $this->get('/cuenta?tab=perfil')
        ->assertOk()
        ->assertSee('El nombre es obligatorio.')
        ->assertSee('Escribe un celular colombiano de 10 dígitos que empiece por 3.')
        ->assertSeeHtml('value="700"');
});

test('la actualización ignora el correo, el rol y el estado aunque lleguen', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->unverified()->create();
    $user->assignRole('cliente');

    $email = $user->email;
    $password = $user->password;

    $this->actingAs($user)
        ->patch(route('account.profile.update'), [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'phone' => '3001234567',
            'email' => 'otro@example.com',
            'role' => 'admin',
            'roles' => ['admin'],
            'is_active' => false,
            'email_verified_at' => now()->toDateTimeString(),
            'terms_accepted_at' => '2000-01-01 00:00:00',
            'password' => 'nueva-clave',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe($email)
        ->and($user->hasRole('cliente'))->toBeTrue()
        ->and($user->hasRole('admin'))->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->terms_accepted_at)->toBeNull()
        ->and($user->password)->toBe($password);
});

test('un invitado no actualiza el perfil', function () {
    $this->patch(route('account.profile.update'), [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'phone' => '3001234567',
    ])->assertRedirect(route('login'));
});

test('el personal recibe 403 en Mi cuenta y en su actualización', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $this->actingAs($vendedor)->get(route('account.index'))->assertForbidden();

    $this->actingAs($vendedor)
        ->patch(route('account.profile.update'), [
            'name' => 'Ana',
            'last_name' => 'Gómez',
            'phone' => '3001234567',
        ])
        ->assertForbidden();
});

test('un cliente no abre /profile y sus mutaciones dan 403 sin tocar la cuenta', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertRedirect(route('account.index'));

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Hack', 'email' => 'otro@example.com'])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    $user->refresh();

    expect($user->email)->not->toBe('otro@example.com')
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('el personal sigue usando /profile', function (string $role) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'test@example.com'])
        ->assertRedirect('/profile');
})->with([
    'admin' => ['admin'],
    'vendedor' => ['vendedor'],
]);
