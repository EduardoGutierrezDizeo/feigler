<?php

use App\Livewire\Admin\Users\Index;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('renders the users page for users with the admin role', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Usuarios')
        ->assertSee('Nuevo usuario');
});

test('returns 403 for authenticated users without the admin role', function () {
    $this->seed(RoleSeeder::class);

    $seller = User::factory()->create();
    $seller->assignRole('vendedor');

    $this->actingAs($seller)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('guests are redirected to the login screen from the users page', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('creates a vendedor with a random password and sends the password reset email', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Ana López')
        ->set('email', 'ana@feigler.test')
        ->set('role', 'vendedor')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('notice', 'Usuario creado correctamente. Se envió un correo para que defina su contraseña.');

    $user = User::query()->where('email', 'ana@feigler.test')->sole();

    expect($user->name)->toBe('Ana López')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('vendedor'))->toBeTrue()
        ->and(empty($user->getAuthPassword()))->toBeFalse()
        ->and($user->getAuthPassword())->not->toBe('password');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('validates name, email and role when creating a user', function () {
    $this->seed(RoleSeeder::class);

    Livewire::test(Index::class)
        ->call('create')
        ->call('save')
        ->assertHasErrors(['name', 'email', 'role']);
});

test('rejects an email already in use', function () {
    $this->seed(RoleSeeder::class);

    User::factory()->create(['email' => 'ana@feigler.test']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Ana López')
        ->set('email', 'ana@feigler.test')
        ->set('role', 'vendedor')
        ->call('save')
        ->assertHasErrors(['email'])
        ->assertSee('Ya existe un usuario con ese correo electrónico.');
});

test('updates a user and replaces the role without accumulating', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['name' => 'Ana López']);
    $user->assignRole('vendedor');

    Livewire::test(Index::class)
        ->call('edit', $user->id)
        ->assertSet('editingId', $user->id)
        ->set('name', 'Ana López Rojas')
        ->set('email', 'ana.rojas@feigler.test')
        ->set('role', 'bodega')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Usuario actualizado correctamente.');

    $user->refresh();

    expect($user->name)->toBe('Ana López Rojas')
        ->and($user->email)->toBe('ana.rojas@feigler.test')
        ->and($user->hasRole('bodega'))->toBeTrue()
        ->and($user->hasRole('vendedor'))->toBeFalse()
        ->and($user->getRoleNames()->count())->toBe(1);
});

test('deactivates and reactivates a user account', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('vendedor');

    Livewire::test(Index::class)
        ->call('toggleActive', $user->id)
        ->assertSet('noticeType', 'success');

    expect($user->refresh()->is_active)->toBeFalse();

    Livewire::test(Index::class)
        ->call('toggleActive', $user->id)
        ->assertSet('noticeType', 'success');

    expect($user->refresh()->is_active)->toBeTrue();
});

test('blocks an admin from deactivating their own account', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->call('toggleActive', $admin->id)
        ->assertSet('noticeType', 'error')
        ->assertSee('No puedes desactivar tu propia cuenta de administrador.');

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('resends the password reset email for an existing user', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['name' => 'Ana López', 'email' => 'ana@feigler.test']);
    $user->assignRole('vendedor');

    Livewire::test(Index::class)
        ->call('resendInvitation', $user->id)
        ->assertSet('noticeType', 'success')
        ->assertSee('Se reenvió el correo de definición de contraseña');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('an inactive user cannot log in with the correct password', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password', 'is_active' => false]);
    $user->assignRole('vendedor');

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an active user can log in with the correct password', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password', 'is_active' => true]);
    $user->assignRole('vendedor');

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('staff.placeholder'));

    $this->assertAuthenticatedAs($user);
});

test('lists only internal users and excludes customers without roles', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create(['name' => 'Carlos Admin', 'email' => 'carlos@feigler.test']);
    $admin->assignRole('admin');

    $seller = User::factory()->create(['name' => 'Ana Vend', 'email' => 'ana@feigler.test']);
    $seller->assignRole('vendedor');

    User::factory()->create(['name' => 'Cliente Común', 'email' => 'cliente@example.test']);

    Livewire::test(Index::class)
        ->assertSee('Carlos Admin')
        ->assertSee('Ana Vend')
        ->assertDontSee('Cliente Común')
        ->assertDontSee('cliente@example.test');
});

test('filters internal users by role', function () {
    $this->seed(RoleSeeder::class);

    $seller = User::factory()->create(['name' => 'Ana Vend', 'email' => 'ana@feigler.test']);
    $seller->assignRole('vendedor');

    $warehouse = User::factory()->create(['name' => 'Luis Bodega', 'email' => 'luis@feigler.test']);
    $warehouse->assignRole('bodega');

    Livewire::test(Index::class)
        ->set('roleFilter', 'vendedor')
        ->assertSee('Ana Vend')
        ->assertDontSee('Luis Bodega');
});

test('searches internal users by name or email', function () {
    $this->seed(RoleSeeder::class);

    $seller = User::factory()->create(['name' => 'Ana Vend', 'email' => 'ana@feigler.test']);
    $seller->assignRole('vendedor');

    $warehouse = User::factory()->create(['name' => 'Luis Bodega', 'email' => 'luis@feigler.test']);
    $warehouse->assignRole('bodega');

    Livewire::test(Index::class)
        ->set('search', 'ana@')
        ->assertSee('Ana Vend')
        ->assertDontSee('Luis Bodega');

    Livewire::test(Index::class)
        ->set('search', 'Luis')
        ->assertSee('Luis Bodega')
        ->assertDontSee('Ana Vend');
});
