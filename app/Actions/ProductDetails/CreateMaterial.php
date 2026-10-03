<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\NormalizesNames;
use App\Exceptions\DuplicateMaterialNameException;
use App\Models\Material;

/**
 * Add a material to the store.
 *
 * A material name is free across the whole store and not per category, because the
 * composition of a garment is written the same way whatever it is: cotton is cotton in
 * shirts and in trousers, and a second material called cotton would be a second thing
 * to keep straight for nothing.
 *
 * The new material is active and goes to the end of the list, so it can be given to a
 * product from the moment it is created without displacing the ones already there.
 */
class CreateMaterial
{
    use NormalizesNames;

    /**
     * Write the material at the end of the list, active.
     */
    public function __invoke(string $name): Material
    {
        $name = $this->cleanName($name);

        if ($this->nameIsTaken(Material::query()->pluck('name'), $name)) {
            throw DuplicateMaterialNameException::forName($name);
        }

        return Material::query()->create([
            'name' => $name,
            'order' => Material::nextOrder(),
            'is_active' => true,
        ]);
    }
}
