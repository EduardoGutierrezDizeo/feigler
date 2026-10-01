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

test('hides the top bar on desktop but keeps the sidebar toggle below lg', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/admin/dashboard');

    // En escritorio el sidebar fijo ya ocupa la columna izquierda, asi que la
    // barra solo dejaria una franja vacia sobre el contenido. Se oculta a partir
    // de `lg`, el mismo breakpoint con el que aparece el sidebar de escritorio,
    // y no antes: el boton que abre el sidebar colapsable vive en esa barra y
    // ese sidebar no existe por debajo de `lg`.
    $response
        ->assertOk()
        ->assertSee('border-b border-sand bg-parchment px-4 sm:px-6 lg:hidden', false)
        ->assertSee('sidebarOpen = ! sidebarOpen', false)
        ->assertSee('hidden w-64 flex-col border-e border-sand bg-cream lg:flex', false)
        ->assertSee('flex w-64 flex-col border-e border-sand bg-cream lg:hidden', false);
});
