<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    /**
     * Register the colors the catalog starts with.
     *
     * Matched by code, so re-running the seeder updates the hex of a color that
     * was already there instead of failing on the unique name.
     *
     * @var list<array{name: string, hex: string, code: string}>
     */
    private const COLORS = [
        ['name' => 'Crema', 'hex' => '#EFE6D2', 'code' => 'CRE'],
        ['name' => 'Verde', 'hex' => '#0B5F36', 'code' => 'VER'],
        ['name' => 'Azul', 'hex' => '#2350A8', 'code' => 'AZU'],
        ['name' => 'Café', 'hex' => '#7A4A2A', 'code' => 'CAF'],
        ['name' => 'Menta', 'hex' => '#A8D8B9', 'code' => 'MEN'],
        ['name' => 'Amarillo', 'hex' => '#E8D27A', 'code' => 'AMA'],
        ['name' => 'Rojo', 'hex' => '#A8202A', 'code' => 'ROJ'],
        ['name' => 'Rosa', 'hex' => '#EFBFCB', 'code' => 'ROS'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::COLORS as $color) {
            Color::updateOrCreate(['code' => $color['code']], $color);
        }
    }
}
