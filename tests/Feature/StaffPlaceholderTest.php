<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

test('staff users can access their placeholder page', function (string $role, string $module) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('staff.placeholder'))
        ->assertOk()
        ->assertSee('Bienvenido, '.$user->name)
        ->assertSee($module);
})->with([
    'vendedor' => ['vendedor', 'Ventas'],
    'bodega' => ['bodega', 'Inventario'],
    'contador' => ['contador', 'Reportes'],
]);

test('forbids admin users from the staff area', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('staff.placeholder'))
        ->assertForbidden();
});

test('forbids users without an internal role from the staff area', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('staff.placeholder'))
        ->assertForbidden();
});

test('guests are redirected to the login screen from the staff area', function () {
    $this->get(route('staff.placeholder'))
        ->assertRedirect(route('login'));
});

test('renders a logout form on the staff placeholder page', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('vendedor');

    $this->actingAs($user)
        ->get(route('staff.placeholder'))
        ->assertOk()
        ->assertSee(route('logout'), false);
});
