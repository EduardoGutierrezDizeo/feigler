<?php

use App\Models\Material;
use Database\Seeders\MaterialSeeder;

test('material seeder creates the initial materials', function () {
    $this->seed(MaterialSeeder::class);

    expect(Material::orderBy('name')->pluck('name')->all())->toBe([
        'Algodón',
        'Elastano',
        'Lino',
        'Mezclilla',
        'Poliéster',
    ]);
});

test('material seeder runs twice without duplicating materials', function () {
    $this->seed(MaterialSeeder::class);
    $this->seed(MaterialSeeder::class);

    expect(Material::count())->toBe(5);
});

test('material seeder does not duplicate a material that already exists', function () {
    Material::factory()->create(['name' => 'Algodón']);

    $this->seed(MaterialSeeder::class);

    expect(Material::where('name', 'Algodón')->count())->toBe(1)
        ->and(Material::count())->toBe(5);
});
