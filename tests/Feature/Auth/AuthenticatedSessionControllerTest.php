<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

test('redirects admin users to the admin dashboard after login', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect(route('admin.dashboard'));
});

test('redirects staff users to their placeholder page after login', function (string $role) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('staff.placeholder'));
})->with([
    'vendedor' => 'vendedor',
    'bodega' => 'bodega',
    'contador' => 'contador',
]);

test('shows a spanish validation error for invalid credentials', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))
        ->toBe('Estas credenciales no coinciden con nuestros registros.');
});

test('renders a logout form in the admin sidebar', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Cerrar sesión');
});
