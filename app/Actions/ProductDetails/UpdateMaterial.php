<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\NormalizesNames;
use App\Exceptions\DuplicateMaterialNameException;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

/**
 * Rename a material.
 *
 * A material name can change while products are made of it. It does not travel into a
 * SKU, which is built out of the category, the size and the color, so renaming a
 * material does not contradict the orders that were paid for: it changes what the
 * products it is written on read as, and nothing that was printed on them.
 *
 * Writing the name the material already has is not an edit, so it is accepted without
 * tripping the duplicate check against itself.
 */
class UpdateMaterial
{
    use NormalizesNames;

    public function __invoke(Material $material, string $name): Material
    {
        $name = $this->cleanName($name);

        return DB::transaction(function () use ($material, $name): Material {
            $locked = Material::query()->lockForUpdate()->findOrFail($material->getKey());

            if ($this->nameIsTheSame($locked->name, $name)) {
                return $locked;
            }

            if ($this->nameIsTaken(
                Material::query()->whereKeyNot($locked->getKey())->pluck('name'),
                $name
            )) {
                throw DuplicateMaterialNameException::forName($name);
            }

            $locked->update(['name' => $name]);

            return $locked;
        });
    }
}
