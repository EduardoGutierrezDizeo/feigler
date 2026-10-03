<?php

namespace App\Livewire\Admin\ProductDetails;

use App\Actions\ProductDetails\CreateSize;
use App\Actions\ProductDetails\DeleteSize;
use App\Actions\ProductDetails\MoveSize;
use App\Actions\ProductDetails\ToggleSize;
use App\Actions\ProductDetails\UpdateSize;
use App\Enums\StoreSection;
use App\Exceptions\DuplicateSizeNameException;
use App\Exceptions\SizeInUseException;
use App\Exceptions\SizeNameLockedException;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Size;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The sizes each category sells garments in.
 *
 * A size belongs to a category and not to the store, so this tab is read one category
 * at a time: the selector is part of the URL (`?cat=3`) because the sizes of one
 * category are a list of their own, worth sharing and worth coming back to. The names
 * are not unique across the store — the `S` of a category of shirts is not the same
 * size as the `S` of a category of anything else — so the panel always says which
 * category it is writing in instead of asking the admin to remember it.
 *
 * The rules themselves live in the actions: this component only asks for the values,
 * hands them over and shows what came back. Every refusal of an action is already
 * written as a sentence for the admin, so it is shown as it arrived.
 */
class Sizes extends Component
{
    use Notifies;

    /**
     * The category whose sizes are being managed, as the id of the row in `categories`.
     *
     * It is part of the URL and it is nullable on purpose: an absent or unknown `cat`
     * falls back to the first category of the store instead of leaving the tab with
     * nothing to read.
     */
    #[Url(as: 'cat')]
    public ?int $categoryId = null;

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
        $this->categoryId = $this->categoryId !== null && Category::query()->whereKey($this->categoryId)->exists()
            ? $this->categoryId
            : Category::query()->orderBy('id')->value('id');

        $this->resetForm();
    }

    /**
     * Read the sizes of another category from the selector.
     *
     * The search is dropped because it was written for a list this one no longer shows,
     * and the form is reset for the same reason the section tabs reset it in the
     * categories panel: it was opened for a size of another category, and a size is
     * written into the category that is selected. Resetting the fields (not just hiding
     * the modal) keeps a name typed for one category from reappearing over another.
     *
     * It hangs off `updatedCategoryId()` and not off a `setCategory()` of its own so
     * that the selector stays bound to the property: `#[Url]` then follows the value
     * without this method having to write it, and a `cat` hand-edited in the address
     * bar and a category picked in the selector arrive by the same door.
     */
    public function updatedCategoryId(): void
    {
        $this->resetForm();
        $this->search = '';
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $sizeId): void
    {
        $size = $this->findSize($sizeId);

        $this->resetForm();
        $this->editingId = $size->getKey();
        $this->name = $size->name;
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
            'name' => ['required', 'string', 'max:20'],
        ], [
            'name.required' => 'El nombre de la talla es obligatorio.',
            'name.max' => 'El nombre de la talla no puede superar los 20 caracteres.',
        ]);

        if ($this->editingId !== null) {
            $size = $this->findSize($this->editingId);

            try {
                (new UpdateSize)($size, $validated['name']);
            } catch (SizeNameLockedException|DuplicateSizeNameException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Talla actualizada correctamente.');
        } else {
            $category = $this->category();

            if ($category === null) {
                $this->notifyError('No hay ninguna categoría a la que añadir la talla.');

                return;
            }

            try {
                (new CreateSize)($category, $validated['name']);
            } catch (DuplicateSizeNameException $exception) {
                $this->notifyError($exception->getMessage());

                return;
            }

            $this->notifySuccess('Talla creada correctamente.');
        }

        $this->resetForm();
    }

    /**
     * Delete a size nobody is selling in.
     *
     * A size with variants keeps both the variants and itself, and the action says how
     * many variants are in the way and that turning the size off is the way out.
     */
    public function delete(int $sizeId): void
    {
        $size = $this->findSize($sizeId);

        try {
            (new DeleteSize)($size);
        } catch (SizeInUseException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess("Talla «{$size->name}» eliminada correctamente.");
    }

    /**
     * Both directions are allowed: turning a size off is how the store stops offering it
     * without losing the variants already sold in it.
     */
    public function toggleActive(int $sizeId): void
    {
        (new ToggleSize)($this->findSize($sizeId));
    }

    public function moveUp(int $sizeId): void
    {
        (new MoveSize)($this->findSize($sizeId), -1);
    }

    public function moveDown(int $sizeId): void
    {
        (new MoveSize)($this->findSize($sizeId), 1);
    }

    public function render()
    {
        $sections = $this->categoriesBySection();
        $category = $this->category($sections);

        $search = mb_strtolower(trim($this->search));
        $searching = $search !== '';

        // El contador de variantes llega en la misma consulta que la lista: la vista lo
        // pinta en cada fila, y pedirlo fila a fila serían ocho consultas más en una
        // categoría que ya trae las ocho tallas estándar.
        $sizes = $category === null
            ? collect()
            : Size::listedForCategory($category->getKey(), ['variants']);

        $visible = $searching
            ? $sizes->filter(fn (Size $size): bool => str_contains(mb_strtolower($size->name), $search))->values()
            : $sizes->values();

        $count = $visible->count();

        $rows = $visible->map(fn (Size $size, int $index): array => [
            'size' => $size,
            'isFirst' => $index === 0,
            'isLast' => $index === $count - 1,
        ]);

        return view('livewire.admin.product-details.sizes', [
            'sections' => $sections,
            'category' => $category,
            'rows' => $rows,
            'total' => $sizes->count(),
            'searching' => $searching,
        ]);
    }

    /**
     * The size being acted on, as long as it belongs to the category on screen.
     *
     * The tab reads one category at a time, so a size of another one is not part of the
     * list the admin is looking at: acting on it from here would rename or move a row
     * that is not on screen, and the sentence under the form would name the wrong
     * category. Such a call is a 404 rather than a silent success.
     */
    private function findSize(int $sizeId): Size
    {
        return Size::query()
            ->where('category_id', $this->categoryId)
            ->findOrFail($sizeId);
    }

    /**
     * The category whose sizes are being managed, or null when the store has none.
     *
     * It is read from the very collection the selector is built from rather than in a
     * query of its own, so the row being edited is the row that is on screen. That
     * collection is passed in by `render()`, which has already asked for it for the
     * selector, instead of asking for it a second time for the same page.
     *
     * @param  Collection<string, Collection<int, Category>>|null  $sections
     */
    private function category(?Collection $sections = null): ?Category
    {
        if ($this->categoryId === null) {
            return null;
        }

        return ($sections ?? $this->categoriesBySection())
            ->flatten()
            ->firstWhere('id', $this->categoryId);
    }

    /**
     * Every category of the store grouped by its section, and in the order the catalog
     * reads each of them.
     *
     * The grouping is done in PHP over `StoreSection::cases()` rather than with an
     * `ORDER BY section` so the sections come out in the order the panel shows them —
     * Hombre, Mujer, Niños — instead of in whatever order their strings sort, and so
     * the sections without categories are left out of a selector that would offer
     * nothing to pick.
     *
     * @return Collection<string, Collection<int, Category>>
     */
    private function categoriesBySection(): Collection
    {
        $bySection = Category::query()
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Category $category): string => $category->section->value);

        return collect(StoreSection::cases())
            ->mapWithKeys(fn (StoreSection $section): array => [
                $section->value => $bySection->get($section->value, collect()),
            ])
            ->filter(fn (Collection $categories): bool => $categories->isNotEmpty());
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->resetValidation();
    }
}
