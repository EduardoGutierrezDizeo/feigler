<?php

namespace App\Livewire\Admin\ProductDetails;

use App\Actions\ProductDetails\CreateMaterial;
use App\Actions\ProductDetails\DeleteMaterial;
use App\Actions\ProductDetails\MoveMaterial;
use App\Actions\ProductDetails\ToggleMaterial;
use App\Actions\ProductDetails\UpdateMaterial;
use App\Exceptions\DuplicateMaterialNameException;
use App\Exceptions\MaterialInUseException;
use App\Livewire\Concerns\Notifies;
use App\Models\Material;
use Livewire\Component;

/**
 * The materials a garment can be made of.
 *
 * A material name is free across the whole store and not per category, because the
 * composition of a garment is written the same way whatever it is: cotton is cotton in
 * shirts and in trousers, and a second material called cotton would be a second thing
 * to keep straight for nothing.
 *
 * The material itself is never more than a name, which is why this tab is the shortest
 * of the four. What it shows of each row is how many garments describe their
 * composition with it, because that is the one number that decides whether it can be
 * deleted at all.
 *
 * The rules themselves live in the actions: this component only asks for the values,
 * hands them over and shows what came back.
 */
class Materials extends Component
{
    use Notifies;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    /**
     * The page this component renders sits behind `role:admin`, but a Livewire request
     * is not that page's request: `/livewire/update` reopens the component on its own,
     * so every public method here is reachable by anyone who reaches that endpoint,
     * with any id they please. The rule of the route is asked again here, on the mount
     * and on every request after it, which is the only place the guard can still stop
     * the call.
     */
    public function booted(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }

    public function mount(): void
    {
        $this->resetForm();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $materialId): void
    {
        $material = Material::query()->findOrFail($materialId);

        $this->resetForm();
        $this->editingId = $material->getKey();
        $this->name = $material->name;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => 'El nombre del material es obligatorio.',
            'name.max' => 'El nombre del material no puede superar los 100 caracteres.',
        ]);

        if ($this->editingId !== null) {
            $material = Material::query()->findOrFail($this->editingId);

            try {
                (new UpdateMaterial)($material, $validated['name']);
            } catch (DuplicateMaterialNameException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Material actualizado correctamente.');
        } else {
            try {
                (new CreateMaterial)($validated['name']);
            } catch (DuplicateMaterialNameException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Material creado correctamente.');
        }

        $this->resetForm();
    }

    /**
     * Delete a material no product is made of.
     *
     * The products that describe their composition with it keep both the product and the
     * material, and the action says how many garments are in the way and that turning
     * the material off is the way out.
     */
    public function delete(int $materialId): void
    {
        $material = Material::query()->findOrFail($materialId);

        try {
            (new DeleteMaterial)($material);
        } catch (MaterialInUseException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess("Material «{$material->name}» eliminado correctamente.");
    }

    /**
     * Both directions are allowed: turning a material off is how the store stops giving
     * it to new garments without taking it away from the ones that already carry it.
     */
    public function toggleActive(int $materialId): void
    {
        (new ToggleMaterial)(Material::query()->findOrFail($materialId));
    }

    public function moveUp(int $materialId): void
    {
        (new MoveMaterial)(Material::query()->findOrFail($materialId), -1);
    }

    public function moveDown(int $materialId): void
    {
        (new MoveMaterial)(Material::query()->findOrFail($materialId), 1);
    }

    public function render()
    {
        $search = mb_strtolower(trim($this->search));
        $searching = $search !== '';

        // El contador llega en la misma consulta que la lista: la vista lo pinta en
        // cada fila, y pedirlo fila a fila sería una consulta por material sobre la
        // lista completa de la tienda.
        $materials = Material::listed(['products']);

        $visible = $searching
            ? $materials->filter(fn (Material $material): bool => str_contains(mb_strtolower($material->name), $search))->values()
            : $materials->values();

        $count = $visible->count();

        $rows = $visible->map(fn (Material $material, int $index): array => [
            'material' => $material,
            'isFirst' => $index === 0,
            'isLast' => $index === $count - 1,
        ]);

        return view('livewire.admin.product-details.materials', [
            'rows' => $rows,
            'total' => $materials->count(),
            'searching' => $searching,
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->resetValidation();
    }
}
