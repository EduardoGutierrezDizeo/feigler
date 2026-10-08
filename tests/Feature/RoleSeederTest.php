<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

test('role seeder creates the five application roles', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())->toBe([
        'admin',
        'bodega',
        'cliente',
        'contador',
        'vendedor',
    ]);
});

test('a user can be assigned one of the seeded roles', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();

    $user->assignRole('vendedor');

    expect($user->hasRole('vendedor'))->toBeTrue()
        ->and($user->roles()->count())->toBe(1);
});
