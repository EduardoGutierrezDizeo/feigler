<?php

namespace App\Livewire\Admin\Categories;

use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Categorías')]
class Index extends Component
{
    use Notifies;

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public ?int $parentId = null;

    /** @var list<int> */
    public array $expanded = [];

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function createSubcategory(Category $category): void
    {
        if ($category->parent_id !== null) {
            return;
        }

        $this->resetForm();
        $this->parentId = $category->id;
        $this->showForm = true;
    }

    public function edit(Category $category): void
    {
        $this->resetForm();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->parentId = $category->parent_id;
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
        $rootIds = Category::query()
            ->whereNull('parent_id')
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->pluck('id');

        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'parentId' => ['nullable', 'integer', Rule::in($rootIds->all())],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'parentId.integer' => 'La categoría padre seleccionada no es válida.',
            'parentId.in' => 'Solo puedes elegir una categoría raíz como categoría padre.',
        ]);

        $name = trim($validated['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            $this->addError('name', 'El nombre debe contener letras o números.');

            return;
        }

        $duplicateSlug = Category::query()
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
                'parent_id' => $validated['parentId'],
            ]);

            $this->notifySuccess('Categoría actualizada correctamente.');
        } else {
            Category::create([
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $validated['parentId'],
                'order' => Category::nextOrderFor($validated['parentId']),
            ]);

            $this->notifySuccess('Categoría creada correctamente.');
        }

        $this->resetForm();
    }

    public function toggleActive(Category $category): void
    {
        $category->update(['is_active' => ! $category->is_active]);
    }

    public function delete(Category $category): void
    {
        if ($category->products()->exists()) {
            $this->notifyError("No se puede eliminar «{$category->name}» porque tiene productos asociados. Primero mueve esos productos a otra categoría o elimínalos.");

            return;
        }

        if ($category->children()->exists()) {
            $this->notifyError("No se puede eliminar «{$category->name}» porque tiene subcategorías. Primero elimina sus subcategorías.");

            return;
        }

        $category->delete();

        $this->notifySuccess("Categoría «{$category->name}» eliminada correctamente.");
    }

    public function toggleExpanded(int $categoryId): void
    {
        $this->expanded = in_array($categoryId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$categoryId]))
            : [...$this->expanded, $categoryId];
    }

    public function moveUp(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $previous = Category::query()
            ->where('parent_id', $category->parent_id)
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
            Category::moveWithinSiblings($category, -1);
            $this->dispatch('category-moved', id: $category->id);
        }
    }

    public function moveDown(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);

        $next = Category::query()
            ->where('parent_id', $category->parent_id)
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
            Category::moveWithinSiblings($category, 1);
            $this->dispatch('category-moved', id: $category->id);
        }
    }

    public function render()
    {
        $search = mb_strtolower(trim($this->search));
        $searching = $search !== '';
        $matches = fn (Category $category): bool => str_contains(mb_strtolower($category->name), $search);

        $categories = Category::query()->orderBy('order')->orderBy('id')->get();

        $roots = $categories->whereNull('parent_id')->values();
        $rootCount = $roots->count();

        $nodes = $roots->map(function (Category $root, int $rootIndex) use ($categories, $searching, $matches, $rootCount) {
            $children = $categories->where('parent_id', $root->id)->values();
            $childCount = $children->count();

            $childrenNodes = $children->map(function (Category $child, int $childIndex) use ($childCount, $matches) {
                return [
                    'category' => $child,
                    'isFirst' => $childIndex === 0,
                    'isLast' => $childIndex === $childCount - 1,
                    'matches' => $matches($child),
                ];
            });

            $rootMatches = $matches($root);
            $matchingCount = $childrenNodes->filter(fn (array $childNode) => $childNode['matches'])->count();

            return [
                'category' => $root,
                'isFirst' => $rootIndex === 0,
                'isLast' => $rootIndex === $rootCount - 1,
                'expanded' => in_array($root->id, $this->expanded, true)
                    || ($searching && ($rootMatches || $matchingCount > 0)),
                'searching' => $searching,
                'rootMatches' => $rootMatches,
                'hasMatch' => $rootMatches || $matchingCount > 0,
                'visible' => ! $searching || $rootMatches || $matchingCount > 0,
                'children' => $childrenNodes,
            ];
        })
            ->filter(fn (array $node) => $node['visible'])
            ->values();

        return view('livewire.admin.categories.index', [
            'nodes' => $nodes,
            'parentOptions' => $this->showForm
                ? Category::query()
                    ->whereNull('parent_id')
                    ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
                    ->orderBy('order')
                    ->orderBy('id')
                    ->get()
                : collect(),
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->parentId = null;
        $this->resetValidation();
    }
}
