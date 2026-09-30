<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

test('guests are redirected to the login screen from the admin dashboard', function () {
    $response = $this->get('/admin/dashboard');

    $response->assertRedirect(route('login'));
});

test('returns 403 for authenticated users without the admin role', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('vendedor');

    $response = $this->actingAs($user)->get('/admin/dashboard');

    $response->assertForbidden();
});

test('renders the dashboard for users with the admin role', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/admin/dashboard');

    $response
        ->assertOk()
        ->assertSee('Bienvenido al panel de Feigler')
        ->assertSee('Dashboard');
});
