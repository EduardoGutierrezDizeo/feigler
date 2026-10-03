<?php

namespace App\Livewire\Admin\ProductDetails;

use App\Actions\ProductDetails\CreateColor;
use App\Actions\ProductDetails\DeleteColor;
use App\Actions\ProductDetails\MoveColor;
use App\Actions\ProductDetails\ToggleColor;
use App\Actions\ProductDetails\UpdateColor;
use App\Exceptions\ColorCodeLockedException;
use App\Exceptions\ColorCodeUnavailableException;
use App\Exceptions\ColorInUseException;
use App\Exceptions\DuplicateColorCodeException;
use App\Exceptions\DuplicateColorNameException;
use App\Exceptions\InvalidColorHexException;
use App\Livewire\Concerns\Notifies;
use App\Models\Color;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The colors of the store, with the code and the hexadecimal they are sold under.
 *
 * Colors are one list for the whole store and not per category: they are told apart
 * inside a SKU, and a SKU does not start by saying which category it is from. That is
 * why this tab has no selector and why the name is free across every color — the
 * actions enforce that, including with the accents and the case that a MySQL collation
 * would ignore and SQLite would not.
 *
 * The code and the name are not editable with the same freedom: the code is part of
 * the SKU of the variants sold in the color, so it cannot change while there are
 * variants, while the name and the hexadecimal always can. The panel therefore shows
 * the code as read-only when the color is in use, instead of letting the admin write
 * something that is going to be refused.
 *
 * The rules themselves live in the actions: this component only asks for the values,
 * hands them over and shows what came back.
 */
class Colors extends Component
{
    use Notifies;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $hex = '';

    public string $code = '';

    /**
     * Whether the code of the color being edited is already part of a SKU.
     *
     * It is read once, when the form is opened, so the panel can show the code as
     * read-only instead of letting the admin write something that is going to be
     * refused. The lock itself does not live here: the action asks again on save, so a
     * variant created in another window while this form was open is still refused.
     */
    public bool $codeIsLocked = false;

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

    public function edit(int $colorId): void
    {
        $color = Color::query()->withCount('variants')->findOrFail($colorId);

        $this->resetForm();
        $this->editingId = $color->getKey();
        $this->name = $color->name;
        $this->hex = $color->hex;
        $this->code = $color->code ?? '';
        $this->codeIsLocked = $color->variants_count > 0;
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
            'hex' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'code' => [
                'nullable',
                'string',
                // Un código en blanco no es un código: es la señal de que el catálogo
                // lo deriva del nombre, que es como se escriben la mayoría de los
                // colores. La forma exacta se le pide sólo a lo que de verdad se
                // escribió, porque `DerivesColorCodes` completa en silencio lo que
                // falta (`RR` se guardaría como `RRX`) y el admin nunca vería ese
                // cambio. Sin tildes ni espacios: el código es lo que distingue dos
                // colores dentro de un SKU, y ahí viaja pegado a otras letras.
                Rule::when(filled($this->code), ['regex:/^[A-Za-z0-9]{3}$/']),
            ],
        ], [
            'name.required' => 'El nombre del color es obligatorio.',
            'name.max' => 'El nombre del color no puede superar los 100 caracteres.',
            'hex.required' => 'El hexadecimal es obligatorio.',
            'hex.regex' => 'Usa un # seguido de seis dígitos hexadecimales, como #1A2B3C.',
            'code.regex' => 'El código debe tener exactamente 3 letras o números.',
        ]);

        if ($this->editingId !== null) {
            $color = Color::query()->findOrFail($this->editingId);

            try {
                (new UpdateColor)($color, $validated['name'], $validated['hex'], $validated['code']);
            } catch (ColorCodeLockedException|DuplicateColorCodeException|DuplicateColorNameException|InvalidColorHexException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Color actualizado correctamente.');
        } else {
            try {
                (new CreateColor)($validated['name'], $validated['hex'], $validated['code']);
            } catch (ColorCodeUnavailableException|DuplicateColorCodeException|DuplicateColorNameException|InvalidColorHexException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Color creado correctamente.');
        }

        $this->resetForm();
    }

    /**
     * Delete a color that nothing points at.
     *
     * Variants and pictures both hold a color, and either one keeps it in the store: the
     * action says how many of each are in the way and that turning the color off is the
     * way out.
     */
    public function delete(int $colorId): void
    {
        $color = Color::query()->findOrFail($colorId);

        try {
            (new DeleteColor)($color);
        } catch (ColorInUseException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess("Color «{$color->name}» eliminado correctamente.");
    }

    /**
     * Both directions are allowed: turning a color off is how the store stops offering it
     * without losing the variants sold in it or the pictures taken in it.
     */
    public function toggleActive(int $colorId): void
    {
        (new ToggleColor)(Color::query()->findOrFail($colorId));
    }

    public function moveUp(int $colorId): void
    {
        (new MoveColor)(Color::query()->findOrFail($colorId), -1);
    }

    public function moveDown(int $colorId): void
    {
        (new MoveColor)(Color::query()->findOrFail($colorId), 1);
    }

    public function render()
    {
        $search = mb_strtolower(trim($this->search));
        $searching = $search !== '';

        // Los dos contadores llegan en la misma consulta que la lista: la vista los
        // pinta en cada fila, y pedirlos fila a fila serían dos consultas por color
        // sobre la lista completa de la tienda.
        $colors = Color::listed(['variants', 'images']);

        $visible = $searching
            ? $colors->filter(fn (Color $color): bool => str_contains(mb_strtolower($color->name), $search))->values()
            : $colors->values();

        $count = $visible->count();

        $rows = $visible->map(fn (Color $color, int $index): array => [
            'color' => $color,
            'isFirst' => $index === 0,
            'isLast' => $index === $count - 1,
        ]);

        return view('livewire.admin.product-details.colors', [
            'rows' => $rows,
            'total' => $colors->count(),
            'searching' => $searching,
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->hex = '';
        $this->code = '';
        $this->codeIsLocked = false;
        $this->resetValidation();
    }
}
