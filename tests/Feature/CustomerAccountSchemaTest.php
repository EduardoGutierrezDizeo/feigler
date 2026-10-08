<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

test('the users table has nullable last_name and phone columns', function () {
    expect(Schema::hasColumns('users', ['last_name', 'phone']))->toBeTrue();

    $user = User::factory()->create();

    expect($user->last_name)->toBeNull()
        ->and($user->phone)->toBeNull();
});

test('last_name and phone are mass assignable', function () {
    $user = User::factory()->create([
        'last_name' => 'Pérez',
        'phone' => '+57 300 1234567',
    ]);

    $user->refresh();

    expect($user->last_name)->toBe('Pérez')
        ->and($user->phone)->toBe('+57 300 1234567');
});

test('the role seeder creates the customer role idempotently with the same guard', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    $guards = Role::query()->pluck('guard_name', 'name');

    expect($guards['cliente'])->toBe($guards['admin'])
        ->and(Role::query()->where('name', 'cliente')->count())->toBe(1)
        ->and(Role::query()->count())->toBe(5);
});

test('the user model implements the MustVerifyEmail contract', function () {
    expect(User::factory()->make())->toBeInstanceOf(MustVerifyEmail::class);
});

test('the verified middleware blocks an unverified user and lets a verified one pass', function () {
    Route::middleware(['auth', 'verified'])
        ->get('/_test/verified-only', fn () => 'acceso')
        ->name('_test.verified-only');

    $this->actingAs(User::factory()->unverified()->create())
        ->get('/_test/verified-only')
        ->assertRedirect(route('verification.notice'));

    $this->actingAs(User::factory()->create())
        ->get('/_test/verified-only')
        ->assertOk()
        ->assertSee('acceso');
});

test('internal staff reach their destination after login without a verified email', function (string $role, string $destination) {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->unverified()->create(['password' => 'password']);
    $user->assignRole($role);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route($destination));

    $this->get(route($destination))->assertOk();
})->with([
    'admin' => ['admin', 'admin.dashboard'],
    'vendedor' => ['vendedor', 'staff.placeholder'],
    'bodega' => ['bodega', 'staff.placeholder'],
    'contador' => ['contador', 'staff.placeholder'],
]);

test('a customer cannot open the admin panel or the staff portal', function () {
    $this->seed(RoleSeeder::class);

    $customer = User::factory()->create();
    $customer->assignRole('cliente');

    $this->actingAs($customer)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.products.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('staff.placeholder'))->assertForbidden();
});
