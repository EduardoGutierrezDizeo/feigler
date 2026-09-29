<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Register the application's roles.
     */
    public function run(): void
    {
        $roles = ['admin', 'vendedor', 'bodega', 'contador'];

        foreach ($roles as $role) {
            Role::findOrCreate($role);
        }
    }
}
