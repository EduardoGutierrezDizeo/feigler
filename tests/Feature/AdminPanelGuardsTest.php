<?php

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('only administrators can open the admin pages', function (?string $role) {
    $this->seed(RoleSeeder::class);
    Role::findOrCreate('cliente', 'web');

    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.products.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
})->with([
    'vendedor' => ['vendedor'],
    'cliente' => ['cliente'],
    'sin rol' => [null],
]);

test('the dashboard re-checks the admin role on every request', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $panel = Livewire::actingAs($admin)->test(Dashboard::class);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->refresh()->assertForbidden();
});

test('the products panel re-checks the admin role on every request', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $panel = Livewire::actingAs($admin)->test(ProductsIndex::class);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->refresh()->assertForbidden();
});

test('the users panel re-checks the admin role on every request', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $panel = Livewire::actingAs($admin)->test(UsersIndex::class);

    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->refresh()->assertForbidden();
});

test('the users panel treats customers and roleless users as missing', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    Role::findOrCreate('cliente', 'web');

    $admin = adminForPanel();

    $cliente = User::factory()->create(['is_active' => true]);
    $cliente->assignRole('cliente');

    $sinRol = User::factory()->create(['is_active' => true]);

    foreach ([$cliente, $sinRol] as $target) {
        $before = $target->only(['name', 'email', 'is_active']);
        $roles = $target->getRoleNames()->all();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('edit', $target->id)
            ->assertNotFound();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('toggleActive', $target->id)
            ->assertNotFound();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('resendInvitation', $target->id)
            ->assertNotFound();

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->set('editingId', $target->id)
            ->set('name', 'Intruso')
            ->set('email', 'intruso@feigler.test')
            ->set('role', 'admin')
            ->call('save')
            ->assertNotFound();

        $target->refresh();

        expect($target->only(['name', 'email', 'is_active']))->toBe($before)
            ->and($target->getRoleNames()->all())->toBe($roles);
    }

    Notification::assertNothingSent();
});

test('the users panel still edits, toggles and invites an internal user', function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $seller = User::factory()->create([
        'name' => 'Ana López',
        'email' => 'ana@feigler.test',
        'is_active' => true,
    ]);
    $seller->assignRole('vendedor');

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->call('edit', $seller->id)
        ->assertSet('editingId', $seller->id)
        ->set('name', 'Ana López Rojas')
        ->set('email', 'ana.rojas@feigler.test')
        ->set('role', 'bodega')
        ->call('save')
        ->assertHasNoErrors();

    $seller->refresh();

    expect($seller->name)->toBe('Ana López Rojas')
        ->and($seller->email)->toBe('ana.rojas@feigler.test')
        ->and($seller->hasRole('bodega'))->toBeTrue()
        ->and($seller->hasRole('vendedor'))->toBeFalse();

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->call('toggleActive', $seller->id)
        ->assertSet('noticeType', 'success');

    expect($seller->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->call('resendInvitation', $seller->id)
        ->assertSet('noticeType', 'success');

    Notification::assertSentTo($seller, ResetPassword::class);
});

test('the users panel refuses to write a role outside the internal list', function (string $role) {
    $this->seed(RoleSeeder::class);
    Role::findOrCreate('cliente', 'web');

    Livewire::actingAs(adminForPanel())->test(UsersIndex::class)
        ->call('create')
        ->set('name', 'Intruso')
        ->set('email', 'intruso@feigler.test')
        ->set('role', $role)
        ->call('save')
        ->assertHasErrors(['role']);

    expect(User::query()->where('email', 'intruso@feigler.test')->exists())->toBeFalse();
})->with([
    'cliente' => ['cliente'],
    'rol inexistente' => ['root'],
    'vacío' => [''],
]);
