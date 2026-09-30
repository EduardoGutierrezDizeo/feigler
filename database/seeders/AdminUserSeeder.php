<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the administrator that accesses the admin panel.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Feigler Admin',
            'email' => 'admin@feigler.test',
        ])->assignRole('admin');
    }
}
