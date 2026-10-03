<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;

class MaterialSeeder extends Seeder
{
    /**
     * The materials the catalog starts with.
     *
     * @var list<array{name: string}>
     */
    private const MATERIALS = [
        ['name' => 'Algodón'],
        ['name' => 'Poliéster'],
        ['name' => 'Lino'],
        ['name' => 'Mezclilla'],
        ['name' => 'Elastano'],
    ];

    /**
     * Register the materials the catalog starts with.
     *
     * Matched by name, so re-running the seeder updates a material that was already
     * there instead of failing on the unique name.
     */
    public function run(): void
    {
        foreach (self::MATERIALS as $material) {
            Material::updateOrCreate(['name' => $material['name']], $material);
        }
    }
}
