<?php

namespace App\Livewire\Admin\Products;

use App\Exceptions\MissingSkuPrefixException;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Productos')]
class Index extends Component
{
    use Notifies;

    /**
     * The stored states the panel may write. `out_of_stock` is deliberately absent:
     * it is what `Product::display_status` computes when the stock runs out, and a
     * stored copy would go stale on the next movement.
     *
     * @var list<string>
     */
    public const WRITABLE_STATUSES = ['active', 'inactive'];

    /**
     * The states the listing can be filtered by. Unlike the writable ones, these
     * include the two computed states, and each one is resolved in SQL.
     *
     * @var list<string>
     */
    public const STATUS_FILTERS = ['active', 'inactive', 'out_of_stock', 'no_variants'];

    public const PAGE_SIZE = 20;

    public string $search = '';

    public string $categoryFilter = '';

    public string $statusFilter = '';

    public int $perPage = self::PAGE_SIZE;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public ?int $categoryId = null;

    public string $description = '';

    public string $brand = '';

    public string $material = '';

    public string $basePrice = '';

    public string $status = 'active';

    /**
     * The reference of the product being edited, shown as read-only text: it is
     * assigned once, when the product is created, and its SKUs are built from it.
     */
    public ?string $editingReference = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(Product $product): void
    {
        $this->resetForm();
        $this->editingId = $product->id;
        $this->editingReference = $product->reference;
        $this->name = $product->name;
        $this->categoryId = $product->category_id;
        $this->description = $product->description ?? '';
        $this->brand = $product->brand ?? '';
        $this->material = $product->material ?? '';
        $this->basePrice = (string) $product->base_price;
        $this->status = $product->status === 'inactive' ? 'inactive' : 'active';
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
        if ($this->editingId !== null) {
            $this->editingId = (int) $this->editingId;
        }

        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'categoryId' => ['required', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'max:2000'],
            'brand' => ['nullable', 'max:100'],
            'material' => ['nullable', 'max:100'],
            'basePrice' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'status' => ['required', Rule::in(self::WRITABLE_STATUSES)],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'categoryId.required' => 'Selecciona una categoría.',
            'categoryId.integer' => 'La categoría seleccionada no es válida.',
            'categoryId.exists' => 'La categoría seleccionada no existe.',
            'description.max' => 'La descripción no puede superar los 2000 caracteres.',
            'brand.max' => 'La marca no puede superar los 100 caracteres.',
            'material.max' => 'El material no puede superar los 100 caracteres.',
            'basePrice.required' => 'El precio base es obligatorio.',
            'basePrice.numeric' => 'El precio base debe ser un número.',
            'basePrice.min' => 'El precio base no puede ser negativo.',
            'basePrice.max' => 'El precio base no puede superar los 99.999.999,99.',
            'status.required' => 'Selecciona un estado.',
            'status.in' => 'El estado seleccionado no es válido.',
        ]);

        $category = Category::query()->findOrFail($validated['categoryId']);

        if ($this->editingId !== null) {
            $this->update($category, $validated);

            return;
        }

        $this->createProduct($category, $validated);
    }

    public function toggleActive(Product $product): void
    {
        $turningOn = $product->status === 'inactive';

        $product->update(['status' => $turningOn ? 'active' : 'inactive']);

        $this->notifySuccess($turningOn
            ? "Producto «{$product->name}» activado correctamente."
            : "Producto «{$product->name}» desactivado correctamente.");
    }

    public function loadMore(): void
    {
        $this->perPage += self::PAGE_SIZE;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->categoryFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $search = mb_strtolower(trim($this->search));

        $query = Product::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$search}%"]));
            })
            ->when($this->categoryFilter !== '', fn (Builder $query) => $this->applyCategoryFilter($query))
            ->when($this->statusFilter !== '', fn (Builder $query) => $this->applyStatusFilter($query));

        $total = (clone $query)->count();

        // `variants` is eager loaded because `stock_total` and `display_status` read
        // it, and both read it from the loaded relation instead of querying per row.
        $products = $query
            ->with(['category', 'variants'])
            ->orderBy('reference')
            ->orderBy('id')
            ->limit($this->perPage)
            ->get();

        $categories = Category::query()->orderBy('order')->orderBy('id')->get();
        $roots = $categories->whereNull('parent_id')->values();

        return view('livewire.admin.products.index', [
            'products' => $products,
            'remaining' => max(0, $total - $products->count()),
            'categoryGroups' => $roots->map(fn (Category $root): array => [
                'root' => $root,
                'children' => $categories->where('parent_id', $root->id)->values(),
                'numbered' => filled($root->sku_prefix),
            ]),
            'statusFilters' => self::STATUS_FILTERS,
        ]);
    }

    /**
     * Only the general data of a product is edited here. Its slug and its reference
     * are left alone on purpose: the reference is part of every SKU already built
     * from it, and the slug is the address the storefront links to.
     */
    private function update(Category $category, array $validated): void
    {
        $product = Product::query()->findOrFail($this->editingId);

        // A product that stays in its category does not have to answer the prefix
        // question again: it already got a reference from that very category.
        if ($product->category_id !== $category->getKey()) {
            try {
                $this->ensureCategoryIsNumberable($category);
            } catch (MissingSkuPrefixException $exception) {
                $this->addError('categoryId', $exception->getMessage());

                return;
            }
        }

        $product->update([
            'name' => trim($validated['name']),
            'category_id' => $category->getKey(),
            'description' => $validated['description'] !== null ? trim($validated['description']) : null,
            'brand' => $this->brandOrDefault($validated['brand']),
            'material' => $validated['material'] !== null ? trim($validated['material']) : null,
            'base_price' => $validated['basePrice'],
            'status' => $validated['status'],
        ]);

        $this->notifySuccess('Producto actualizado correctamente.');
        $this->resetForm();
    }

    /**
     * The insert happens inside the transaction that `nextReferenceFor` opens, so
     * the lock on the category row is still held when the row is written. Outside
     * of it, two products created at the same time could both read the same
     * counter and collide on the unique reference.
     */
    private function createProduct(Category $category, array $validated): void
    {
        $slug = $this->uniqueSlugFor($validated['name']);

        if ($slug === null) {
            return;
        }

        try {
            DB::transaction(function () use ($category, $validated, $slug): void {
                Product::create([
                    'category_id' => $category->getKey(),
                    'name' => trim($validated['name']),
                    'slug' => $slug,
                    'description' => $validated['description'] !== null ? trim($validated['description']) : null,
                    'brand' => $this->brandOrDefault($validated['brand']),
                    'material' => $validated['material'] !== null ? trim($validated['material']) : null,
                    'base_price' => $validated['basePrice'],
                    'status' => $validated['status'],
                ]);
            });
        } catch (MissingSkuPrefixException $exception) {
            $this->addError('categoryId', $exception->getMessage());

            return;
        }

        $this->notifySuccess('Producto creado correctamente.');
        $this->resetForm();
    }

    /**
     * The blank the brand is allowed to be left in: the column is not nullable and
     * the catalog is Feigler's own, so a product with no brand is a Feigler one.
     */
    private function brandOrDefault(?string $brand): string
    {
        $brand = $brand !== null ? trim($brand) : '';

        return $brand !== '' ? $brand : 'Feigler';
    }

    /**
     * The slug of a product name, with a numeric suffix when the name is already
     * taken. Two products may well share a name, and `products.slug` is unique, so
     * the second «Polo básico» becomes `polo-basico-2` instead of failing to save.
     */
    private function uniqueSlugFor(string $name): ?string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $this->addError('name', 'El nombre debe contener letras o números.');

            return null;
        }

        $slug = $base;
        $suffix = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * The category (or its root) has to be able to number a product, which it can
     * only do if the root carries a SKU prefix.
     *
     * @throws MissingSkuPrefixException
     */
    private function ensureCategoryIsNumberable(Category $category): void
    {
        $root = $category->parent_id !== null ? $category->parent : $category;

        if (blank($root?->sku_prefix)) {
            throw MissingSkuPrefixException::forCategory($root ?? $category);
        }
    }

    /**
     * Choosing a root also brings in its subcategories: they are numbered in the
     * same series, so from the store's point of view they are the same family of
     * products and filtering by the family should not hide half of it.
     */
    private function applyCategoryFilter(Builder $query): void
    {
        $category = Category::query()->find((int) $this->categoryFilter);

        if ($category === null) {
            return;
        }

        $ids = $category->parent_id === null
            ? $category->children()->pluck('id')->push($category->getKey())
            : collect([$category->getKey()]);

        $query->whereIn('category_id', $ids->all());
    }

    /**
     * The filter mirrors `Product::display_status`, but in SQL instead of in PHP.
     *
     * An inactive product is `inactive` whatever its stock says. The other three are
     * decided by the variants: `no_variants` has none, `out_of_stock` has some and
     * their active stock adds up to zero or less, `active` has some and adds up to
     * more. `case when is_active` keeps the inactive variants in the sum as zeros,
     * which is the same thing the accessor does, and it is plain SQL that MySQL and
     * SQLite read the same way.
     */
    private function applyStatusFilter(Builder $query): void
    {
        $activeStock = fn (Builder $variants): Builder => $variants
            ->selectRaw('coalesce(sum(case when is_active = 1 then stock else 0 end), 0) as stock_total');

        match ($this->statusFilter) {
            'inactive' => $query->where('status', 'inactive'),
            'no_variants' => $query
                ->whereNot('status', 'inactive')
                ->whereDoesntHave('variants'),
            'out_of_stock' => $query
                ->whereNot('status', 'inactive')
                ->whereHas('variants')
                ->whereHas('variants', fn (Builder $variants): Builder => $activeStock($variants)->havingRaw('stock_total <= 0')),
            'active' => $query
                ->whereNot('status', 'inactive')
                ->whereHas('variants', fn (Builder $variants): Builder => $activeStock($variants)->havingRaw('stock_total > 0')),
            default => null,
        };
    }

    private function resetPage(): void
    {
        $this->perPage = self::PAGE_SIZE;
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->editingReference = null;
        $this->name = '';
        $this->categoryId = null;
        $this->description = '';
        $this->brand = '';
        $this->material = '';
        $this->basePrice = '';
        $this->status = 'active';
        $this->resetValidation();
    }
}