<?php

namespace App\Livewire\Admin\Products;

use App\Actions\ProductDetails\SyncProductMaterials;
use App\Actions\Products\CreateProduct;
use App\Enums\StoreSection;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\InvalidProductNameException;
use App\Exceptions\InvalidProductStatusException;
use App\Exceptions\MaterialNotFoundException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * The stored states the panel may write, which are the ones the column holds:
     * `out_of_stock` is deliberately absent because it is what
     * `Product::display_status` computes when the stock runs out, and a stored
     * copy would go stale on the next movement.
     *
     * The list lives in the action that creates a product, because that is where
     * the rule is enforced, and this form offers exactly what can be written.
     *
     * @var list<string>
     */
    public const WRITABLE_STATUSES = CreateProduct::WRITABLE_STATUSES;

    /**
     * The states the listing can be filtered by. Unlike the writable ones, these
     * include the two computed states, and each one is resolved in SQL.
     *
     * @var list<string>
     */
    public const STATUS_FILTERS = ['active', 'inactive', 'out_of_stock', 'no_variants'];

    /**
     * The brand the form opens with, which is the one a product with no brand of
     * its own ends up stored with.
     */
    private const DEFAULT_BRAND = CreateProduct::DEFAULT_BRAND;

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

    /**
     * The composition the form is writing: one row per material, with how much of the
     * garment each one is.
     *
     * It is a plain list of ids and percentages instead of models because a public
     * Livewire property has to survive the round trip to the browser as data. The
     * materials themselves are not held here on purpose: the panel shows a product
     * page of the catalog, not a product of a garment's materials, and keeping them
     * would mean a query per keystroke.
     *
     * @var list<array{material_id: string, percentage: string}>
     */
    public array $composition = [];

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
        // Los materiales se leen sólo del producto que se está editando: el listado
        // no los muestra, así que cargarlos aquí es una consulta para el producto
        // abierto en vez de una por fila de la página.
        $this->composition = $this->rowsFor($product->loadMissing('materials')->materials);
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

    /**
     * Add a row to the composition for the admin to fill in.
     *
     * The percentage it starts with is what the composition still has to reach: a
     * garment nobody has described yet is usually a garment made of one thing, so an
     * empty list gets a full row. A list that is already partly filled gets the rest,
     * because that is the only share of it this row can honestly be. A share has to be
     * at least one, so that is the floor when there is nothing left to reach.
     */
    public function addMaterialRow(): void
    {
        $this->composition[] = [
            'material_id' => '',
            'percentage' => (string) max(1, 100 - $this->compositionTotal()),
        ];
    }

    /**
     * Take a row out of the composition, keeping the remaining ones in order.
     *
     * The rows are renumbered because their positions are their names in the form:
     * `composition.0`, `composition.1`, and so on. A hole in the middle would leave the
     * rest of the form pointing at rows that are no longer there.
     */
    public function removeMaterialRow(int $index): void
    {
        if (! array_key_exists($index, $this->composition)) {
            return;
        }

        unset($this->composition[$index]);

        $this->composition = array_values($this->composition);
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

        // The composition is only asked to be well formed here: a whole number of
        // shares of a material the store has. Whether the shares add up to the whole
        // garment, whether one of them is repeated and whether a material is turned
        // off is not decided by the form: that is the action, which is also the only
        // place that knows a material the product already carries may be kept.
        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'categoryId' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('section', $section->value),
            ],
            'description' => ['nullable', 'max:2000'],
            'brand' => ['nullable', 'max:100'],
            'composition' => ['array'],
            'composition.*.material_id' => ['required', 'integer', Rule::exists('materials', 'id')],
            'composition.*.percentage' => ['required', 'integer', 'min:1', 'max:100'],
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
            'composition.*.material_id.required' => 'Selecciona un material.',
            'composition.*.material_id.integer' => 'El material seleccionado no es válido.',
            'composition.*.material_id.exists' => 'El material seleccionado no existe.',
            'composition.*.percentage.required' => 'El porcentaje es obligatorio.',
            'composition.*.percentage.integer' => 'El porcentaje debe ser un número entero.',
            'composition.*.percentage.min' => 'El porcentaje no puede ser menor que 1.',
            'composition.*.percentage.max' => 'El porcentaje no puede superar 100.',
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
            // Los materiales solo se piden con el formulario abierto: el listado no
            // los muestra, así que pedirlos en cada tecla del buscador sería una
            // consulta por tecla para nada.
            'materials' => $this->showForm ? $this->materialsForTheForm() : new Collection,
            'compositionSummary' => $this->compositionSummary(),
        ]);
    }

    /**
     * Only the general data of a product is edited here. Its slug and its reference
     * are left alone on purpose: the reference is part of every SKU already built
     * from it, and the slug is the address the storefront links to.
     *
     * The row and its composition are written in one transaction, so a composition the
     * action refuses leaves the product with the name it already had instead of with
     * a new name and the old materials.
     */
    private function update(Category $category, array $validated): void
    {
        $product = Product::query()->findOrFail($this->editingId);

        // A product that already sells something cannot be moved to another category:
        // its sizes belong to the category it is in, and a garment of one category
        // cannot be offered in the sizes of another. The form is refused whole, so the
        // category the admin just picked does not leave the variant list behind a
        // category the product is not in.
        if ($this->categoryChangeIsRefused($product, $category)) {
            $this->addError('categoryId', 'No puedes cambiar la categoría de un producto que ya tiene variantes: sus tallas pertenecen a la categoría actual');

            return;
        }

        try {
            DB::transaction(function () use ($product, $category, $validated): void {
                $product->update([
                    'name' => trim($validated['name']),
                    'category_id' => $category->getKey(),
                    'description' => $validated['description'] !== null ? trim($validated['description']) : null,
                    'brand' => $this->brandOrDefault($validated['brand']),
                    'base_price' => $validated['basePrice'],
                    'status' => $validated['status'],
                ]);

                (new SyncProductMaterials)($product, $this->compositionAsLines());
            });
        } catch (
            RepeatedProductMaterialException|
            InvalidMaterialPercentageException|
            MaterialNotFoundException|
            InactiveProductMaterialException|
            IncompleteMaterialCompositionException $exception
        ) {
            // Las reglas de la composición son las de la acción y sus motivos son
            // distintos entre sí, así que el motivo llega tal cual y no se reescribe
            // por cada campo: el admin lee por qué no se guardó.
            $this->addError('composition', $exception->getMessage());

            return;
        }

        $this->notifySuccess('Producto actualizado correctamente.');
        $this->resetForm();
    }

    /**
     * Whether this product cannot be moved to the category the form is asking for.
     */
    private function categoryChangeIsRefused(Product $product, Category $category): bool
    {
        return (int) $product->category_id !== (int) $category->getKey()
            && $product->variants()->exists();
    }

    /**
     * Create the product the open form is describing, and leave the modal editing
     * it.
     *
     * Unlike the update, the modal stays open and the panel switches to editing
     * the product that was just created: a product is born without variants, and
     * the tab that fills them is one click away only while the product exists.
     *
     * The row is written by the action, which is also where the reference is
     * reserved and where the name, the brand and the status are put in the form
     * the catalog keeps, and where the composition is written next to it. What it
     * refuses is shown under the field it belongs to, the same way the messages of
     * the form come out.
     */
    private function createProduct(Category $category, array $validated): void
    {
        try {
            $product = (new CreateProduct)($category, [
                'name' => $validated['name'],
                'description' => $validated['description'],
                'brand' => $validated['brand'],
                'base_price' => $validated['basePrice'],
                'status' => $validated['status'],
            ], $this->compositionAsLines());
        } catch (InvalidProductNameException $exception) {
            $this->addError('name', $exception->getMessage());

            return;
        } catch (InvalidProductStatusException $exception) {
            $this->addError('status', $exception->getMessage());

            return;
        } catch (
            RepeatedProductMaterialException|
            InvalidMaterialPercentageException|
            MaterialNotFoundException|
            InactiveProductMaterialException|
            IncompleteMaterialCompositionException $exception
        ) {
            $this->addError('composition', $exception->getMessage());

            return;
        }

        $this->editingId = $product->getKey();
        $this->editingReference = $product->reference;
        $this->showForm = true;

        $this->notifySuccess('Producto creado correctamente.');
        $this->dispatch(self::EVENT_OPEN_VARIANTS_TAB);
    }

    /**
     * The composition as the action reads it.
     *
     * A row of the form is `material_id` + `percentage` and a line the action reads is
     * `id` + `percentage`: the form names the field after what the select holds, and the
     * action after the column of the pivot. The percentages are handed over as they
     * arrived, as text, so the action is the one that asks whether they are whole
     * numbers instead of this quietly rounding a value the form never checked.
     *
     * @return list<array{id: int, percentage: string}>
     */
    private function compositionAsLines(): array
    {
        return array_values(array_map(
            fn (array $row): array => [
                'id' => (int) $row['material_id'],
                'percentage' => $row['percentage'],
            ],
            $this->composition,
        ));
    }

    /**
     * The rows of a composition that is already stored, in the order the catalog reads
     * materials in, so the form opens with them where the admin expects to find them.
     *
     * @param  Collection<int, Material>  $materials
     * @return list<array{material_id: string, percentage: string}>
     */
    private function rowsFor(Collection $materials): array
    {
        return $materials
            ->map(fn (Material $material): array => [
                'material_id' => (string) $material->getKey(),
                'percentage' => (string) $material->pivot->percentage,
            ])
            ->values()
            ->all();
    }

    /**
     * What the rows of the composition add up to right now.
     *
     * It is read the way the action reads a percentage: only whole numbers count and
     * anything else is left out. That is what keeps the indicator honest while a
     * percentage is being typed, instead of counting the `5` of a `50` that is on its
     * way and telling the admin the garment is already complete.
     */
    private function compositionTotal(): int
    {
        $total = 0;

        foreach ($this->composition as $row) {
            $wholeShare = filter_var($row['percentage'] ?? null, FILTER_VALIDATE_INT);

            if ($wholeShare !== false) {
                $total += $wholeShare;
            }
        }

        return $total;
    }

    /**
     * The composition as one line to show under its rows: how much of the garment it
     * accounts for, what is left to reach it, and whether it already does.
     *
     * A composition that adds up to more than a hundred is not one that is still
     * missing something, it is one that cannot be saved, so it is reported as going
     * over instead of as a negative remainder.
     *
     * @return array{total: int, detail: string, complete: bool, exceeds: bool}
     */
    private function compositionSummary(): array
    {
        $total = $this->compositionTotal();

        return [
            'total' => $total,
            'detail' => match (true) {
                $total > 100 => 'se pasa por '.($total - 100).' %',
                $total === 100 => 'completa',
                default => 'faltan '.(100 - $total).' %',
            },
            'complete' => $total === 100,
            'exceeds' => $total > 100,
        ];
    }

    /**
     * The materials the open form may write: the ones `Material::listedActive()` would
     * give, plus the ones this product already carries even when they are turned off.
     *
     * The query is spelled out instead of merged onto the collection that
     * `listedActive()` returns, because that call is the end of the line: it cannot be
     * widened afterwards, and ordering the two sets together has to happen in the
     * database for the rows to come back in catalog order.
     *
     * Turning a material off is how the store stops giving it to new products without
     * taking it away from the products that already say they are made of it, and a
     * select that does not carry the value it is holding would silently write
     * something else, so a product has to stay savable without changing its
     * composition. The action is the one that decides whether a material may be taken,
     * so this is not where that rule lives; it only refuses ids that are not in the
     * store, which is what the rows are validated against.
     *
     * @return Collection<int, Material>
     */
    private function materialsForTheForm(): Collection
    {
        $assignedIds = $this->assignedMaterialIds();

        return Material::query()
            ->when(
                $assignedIds === [],
                fn (Builder $query): Builder => $query->active(),
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->orWhereIn('id', $assignedIds)
                ),
            )
            ->ordered()
            ->get();
    }

    /**
     * The ids of the materials this product already carries.
     *
     * @return list<int>
     */
    private function assignedMaterialIds(): array
    {
        if ($this->editingId === null) {
            return [];
        }

        return Product::query()
            ->findOrFail($this->editingId)
            ->materials()
            ->pluck('materials.id')
            ->all();
    }

    /**
     * The blank the brand is allowed to be left in.
     *
     * It is the action that stores it, so both spellings of the same rule live
     * together: the form offers the brand of the store and the row is written
     * with it even when nobody typed anything.
     */
    private function brandOrDefault(?string $brand): string
    {
        $brand = $brand !== null ? trim($brand) : '';

        return $brand !== '' ? $brand : self::DEFAULT_BRAND;
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
        $this->composition = [];
        $this->basePrice = '';
        $this->status = 'active';
        $this->resetValidation();
    }
}
