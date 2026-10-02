<?php

namespace App\Livewire\Admin\Products;

use App\Enums\StoreSection;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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

    private const DEFAULT_BRAND = 'Feigler';

    public const PAGE_SIZE = 20;

    /**
     * The tab the modal lands on when it is opened from the listing, so it does
     * not stay on the tab of the product that was open before it.
     */
    public const EVENT_RESET_TAB = 'product-modal-open';

    /**
     * The tab the modal lands on right after a product is created: the reference
     * and the slug only exist from that moment, and so do the variants.
     */
    public const EVENT_OPEN_VARIANTS_TAB = 'open-product-variants-tab';

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

    /**
     * Switch the section being managed, from the tab bar.
     *
     * A section that does not exist falls back to `hombre` instead of leaving the
     * panel listing nothing, and the form is dismissed: it was opened for the
     * product of another section, and a product never moves between sections.
     * Every filter is cleared along with the page size, because a category from
     * the section being left would otherwise filter the new section down to
     * nothing.
     */
    public function setSection(string $section): void
    {
        $this->section = StoreSection::tryFrom($section)?->value ?? StoreSection::Hombre->value;

        $this->closeForm();
        $this->clearFilters();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->dispatch(self::EVENT_RESET_TAB);
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
        $this->dispatch(self::EVENT_RESET_TAB);
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

        // A product stays in the section it was created in, so the section to
        // accept is read from the product when editing and from the open tab when
        // creating. Either way the chosen category has to belong to it.
        $section = $this->sectionForTheForm();

        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'categoryId' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('section', $section->value),
            ],
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
            'categoryId.exists' => 'La categoría seleccionada no existe en esta sección.',
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
        $section = $this->activeSection();
        $search = mb_strtolower(trim($this->search));

        $query = Product::query()
            // La sección se resuelve en SQL, con un `where` sobre la categoría: el
            // listado entero sale de una consulta y leer más filas no cuesta más.
            ->whereHas('category', fn (Builder $query): Builder => $query->where('section', $section->value))
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
        // `images` is there for the same reason: `cover_image` reads the loaded
        // relation, and eager loading it is one query for the whole page instead of
        // one per product. Only the images of the products on this page are read.
        $products = $query
            ->with(['category', 'variants', 'images'])
            ->orderBy('reference')
            ->orderBy('id')
            ->limit($this->perPage)
            ->get();

        $categories = Category::query()
            ->inSection($section)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        // El formulario trabaja en la sección del producto cuando se edita, que
        // puede no ser la de la pestaña. Solo entonces hace falta una segunda
        // consulta; si es la misma, se reutiliza la del filtro.
        $formSection = $this->sectionForTheForm();
        $formCategories = $formSection === $section
            ? $categories
            : Category::query()
                ->inSection($formSection)
                ->orderBy('order')
                ->orderBy('id')
                ->get();

        return view('livewire.admin.products.index', [
            // Ver `Admin\Categories\Index::render()`: la clave evita ensombrecer
            // la propiedad pública `section`, que es un string.
            'activeSection' => $section,
            'products' => $products,
            'remaining' => max(0, $total - $products->count()),
            'categories' => $categories,
            'formSection' => $formSection,
            'formCategories' => $formCategories,
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
     *
     * Unlike the update, the modal stays open and the panel switches to editing
     * the product that was just created: a product is born without variants, and
     * the tab that fills them is one click away only while the product exists.
     */
    private function createProduct(Category $category, array $validated): void
    {
        $slug = $this->uniqueSlugFor($validated['name']);

        if ($slug === null) {
            return;
        }

        $product = DB::transaction(function () use ($category, $validated, $slug): Product {
            return Product::create([
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

        $this->editingId = $product->getKey();
        $this->editingReference = $product->reference;
        $this->showForm = true;

        $this->notifySuccess('Producto creado correctamente.');
        $this->dispatch(self::EVENT_OPEN_VARIANTS_TAB);
    }

    /**
     * The blank the brand is allowed to be left in: the column is not nullable and
     * the catalog is Feigler's own, so a product with no brand is a Feigler one.
     */
    private function brandOrDefault(?string $brand): string
    {
        $brand = $brand !== null ? trim($brand) : '';

        return $brand !== '' ? $brand : self::DEFAULT_BRAND;
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

    private function applyCategoryFilter(Builder $query): void
    {
        $query->where('category_id', (int) $this->categoryFilter);
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

    /**
     * The section being managed, resolved from the URL value.
     */
    private function activeSection(): StoreSection
    {
        return StoreSection::tryFrom($this->section) ?? StoreSection::Hombre;
    }

    /**
     * The section the open form works in: the product's own when editing, and the
     * open tab when creating.
     */
    private function sectionForTheForm(): StoreSection
    {
        if ($this->editingId === null) {
            return $this->activeSection();
        }

        return Product::query()->find($this->editingId)?->category?->section ?? $this->activeSection();
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
        $this->brand = self::DEFAULT_BRAND;
        $this->material = '';
        $this->basePrice = '';
        $this->status = 'active';
        $this->resetValidation();
    }
}
