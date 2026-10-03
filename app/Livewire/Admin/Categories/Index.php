<?php

namespace App\Livewire\Admin\Categories;

use App\Actions\ProductDetails\DeleteCategory;
use App\Enums\StoreSection;
use App\Exceptions\CategoryInUseException;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Size;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Categorías')]
class Index extends Component
{
    use Notifies;

    /**
     * The section of the catalog being managed: `hombre`, `mujer` or `ninos`.
     *
     * It is part of the URL so that a tab can be shared, bookmarked or reached
     * with the back button, and it is reset to `hombre` whenever it holds a value
     * the enum does not know.
     */
    #[Url(as: 'seccion')]
    public string $section = 'hombre';

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $skuPrefix = '';

    /**
     * The tab that contains this panel sits behind `role:admin`, but a Livewire request
     * is not that page's request: `/livewire/update` reopens the component on its own,
     * so every public method here is reachable by anyone who reaches that endpoint,
     * with any id they please. The rule of the route is asked again here, on the mount
     * and on every request after it, which is the only place the guard can still stop
     * the call.
     */
    public function booted(): void
    {
        abort_unless($this->user()?->hasRole('admin'), 403);
    }

    /**
     * Switch the section being managed, from the tab bar.
     *
     * A section that does not exist falls back to `hombre` instead of leaving the
     * panel listing nothing, and the form is reset: it was opened for the
     * category of another section, and the section a category belongs to never
     * changes once it is created. Resetting the fields (not just hiding the
     * modal) keeps a name typed in one section from reappearing in another.
     */
    public function setSection(string $section): void
    {
        $this->section = StoreSection::tryFrom($section)?->value ?? StoreSection::Hombre->value;

        $this->resetForm();
        $this->search = '';
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(Category $category): void
    {
        $this->resetForm();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->skuPrefix = $category->sku_prefix ?? '';
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function mount(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->skuPrefix = mb_strtoupper(trim($this->skuPrefix));

        $section = $this->activeSection();

        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'skuPrefix' => ['required', 'regex:/^[A-Z0-9]{2,4}$/', Rule::unique('categories', 'sku_prefix')->ignore($this->editingId)],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'skuPrefix.required' => 'El prefijo de SKU es obligatorio.',
            'skuPrefix.regex' => 'Usa de 2 a 4 letras o números, sin espacios.',
            'skuPrefix.unique' => 'Ya existe otra categoría con ese prefijo.',
        ]);

        $name = trim($validated['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            $this->addError('name', 'El nombre debe contener letras o números.');

            return;
        }

        $duplicateName = Category::query()
            ->where('section', $section->value)
            ->where('name', $name)
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicateName) {
            $this->addError('name', 'Ya existe una categoría con ese nombre en esta sección.');

            return;
        }

        $duplicateSlug = Category::query()
            ->where('section', $section->value)
            ->where('slug', $slug)
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicateSlug) {
            $this->addError('name', 'Ya existe una categoría con ese nombre.');

            return;
        }

        if ($this->editingId !== null) {
            Category::query()->findOrFail($this->editingId)->update([
                'name' => $name,
                'slug' => $slug,
                'sku_prefix' => $this->skuPrefix,
            ]);

            $this->notifySuccess('Categoría actualizada correctamente.');
        } else {
            $this->createCategoryWithItsSizes($name, $slug, $section);

            $this->notifySuccess('Categoría creada correctamente.');
        }

        $this->resetForm();
    }

    /**
     * Write a new category and the sizes it sells garments in.
     *
     * The sizes are written with the category and not after it: a category that is
     * born without them cannot be sold in, because the variant form has nothing to
     * offer and no variant can be created until somebody adds the sizes by hand.
     * Both writes are one transaction, so a category is never left in the store
     * without the sizes that belong to it.
     */
    private function createCategoryWithItsSizes(string $name, string $slug, StoreSection $section): void
    {
        DB::transaction(function () use ($name, $slug, $section): void {
            $category = Category::create([
                'name' => $name,
                'slug' => $slug,
                'section' => $section,
                'sku_prefix' => $this->skuPrefix,
                'order' => Category::nextOrderFor($section),
            ]);

            Size::seedStandardSizesFor($category);
        });
    }

    public function toggleActive(Category $category): void
    {
        $category->update(['is_active' => ! $category->is_active]);
    }

    /**
     * Delete a category that sells nothing, together with the sizes of its own.
     *
     * The rule itself lives in the action, not here: a category that still has
     * products keeps both the products and its sizes, and the sizes are what makes
     * the delete possible at all, so the two go together in one unit of work.
     */
    public function delete(Category $category): void
    {
        try {
            (new DeleteCategory)($category);
        } catch (CategoryInUseException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess("Categoría «{$category->name}» eliminada correctamente.");
    }

    public function moveUp(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $previous = Category::query()
            ->inSection($category->section)
            ->where(function ($query) use ($category) {
                $query->where('order', '<', $category->order)
                    ->orWhere(function ($query) use ($category) {
                        $query->where('order', $category->order)->where('id', '<', $category->id);
                    });
            })
            ->orderByDesc('order')
            ->orderByDesc('id')
            ->first();

        if ($previous !== null) {
            Category::moveWithinSection($category, -1);
            $this->dispatch('category-moved', id: $category->id);
        }
    }

    public function moveDown(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $next = Category::query()
            ->inSection($category->section)
            ->where(function ($query) use ($category) {
                $query->where('order', '>', $category->order)
                    ->orWhere(function ($query) use ($category) {
                        $query->where('order', $category->order)->where('id', '>', $category->id);
                    });
            })
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if ($next !== null) {
            Category::moveWithinSection($category, 1);
            $this->dispatch('category-moved', id: $category->id);
        }
    }

    public function render()
    {
        $section = $this->activeSection();

        $search = mb_strtolower(trim($this->search));
        $searching = $search !== '';

        $categories = Category::query()
            ->inSection($section)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $visible = $searching
            ? $categories->filter(fn (Category $category): bool => str_contains(mb_strtolower($category->name), $search))->values()
            : $categories->values();

        $count = $visible->count();

        $rows = $visible->map(fn (Category $category, int $index): array => [
            'category' => $category,
            'isFirst' => $index === 0,
            'isLast' => $index === $count - 1,
        ]);

        return view('livewire.admin.categories.index', [
            // La clave no se llama `section` a propósito: ese es el nombre de la
            // propiedad pública (un string), y ensombrecerla con el enum aquí
            // haría que la vista reciba el string en vez de la sección resuelta.
            'activeSection' => $section,
            'rows' => $rows,
        ]);
    }

    /**
     * The section being managed, resolved from the URL value.
     */
    private function activeSection(): StoreSection
    {
        return StoreSection::tryFrom($this->section) ?? StoreSection::Hombre;
    }

    private function user(): ?User
    {
        return auth()->user();
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->skuPrefix = '';
        $this->resetValidation();
    }
}
