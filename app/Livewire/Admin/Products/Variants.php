<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Products\AdjustProductVariantStock;
use App\Actions\Products\CreateProductVariant;
use App\Actions\Products\DeleteProductVariant;
use App\Actions\Products\ToggleProductVariant;
use App\Actions\Products\UpdateProductVariant;
use App\Exceptions\DuplicateProductVariantException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Exceptions\InvalidVariantSizeException;
use App\Exceptions\ProductVariantNotDeletableException;
use App\Livewire\Concerns\Notifies;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Variants extends Component
{
    use Notifies;

    /**
     * The event the tab broadcasts after any change, so the product listing that
     * sits underneath can re-read the stock and the status it derives from it.
     */
    public const CHANGED_EVENT = 'product-variants-changed';

    public int $productId;

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * The size the form is writing, as the id of the row in `sizes`.
     *
     * It is an id and not a name because the names are not unique in the store: `S`
     * of a category of shirts is not the same size as `S` of a category of anything
     * else, and the size a variant carries has to be the one of its own category.
     */
    public ?int $sizeId = null;

    public ?int $colorId = null;

    /**
     * The stock a variant is born with. Only the creation form writes it: the
     * stock of a variant that already exists only moves through adjustments.
     *
     * It is empty while the form is open so a stock nobody wrote is not read as
     * if it were a real zero; the save turns the blank into a zero itself.
     */
    public string $initialStock = '';

    /**
     * The SKU of the variant being edited, kept as text so the form can show it as
     * read-only without reading the variant again on every keystroke.
     */
    public ?string $editingSku = null;

    public ?int $adjustingId = null;

    public string $stockDelta = '';

    public string $stockReason = '';

    /**
     * The page that mounts this tab already sits behind `role:admin`, but a
     * Livewire request is not that page's request: `/livewire/update` reopens the
     * component on its own, so every public method here is reachable by anyone
     * who reaches that endpoint, with any id they please. The rule of the route
     * is asked again here instead, on the mount and on every request after it,
     * which is the only place the guard can still stop the call.
     */
    public function booted(): void
    {
        abort_unless($this->user()?->hasRole('admin'), 403);
    }

    public function mount(int $productId): void
    {
        $this->productId = $productId;

        // A product that is not there is a page that should not be there either:
        // the tab is only mounted for the product being edited.
        $this->product();
    }

    /**
     * Open the creation form, in a blank state.
     */
    public function startCreating(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    /**
     * Open the creation form for a size and a color already in the tab, so that a
     * variation of an existing one is one selection away instead of from scratch.
     */
    public function startCreatingFrom(int $variantId): void
    {
        $variant = $this->variant($variantId);

        $this->resetForm();
        $this->sizeId = $variant->size_id;
        $this->colorId = $variant->color_id;
        $this->showForm = true;
    }

    public function startEditing(int $variantId): void
    {
        $variant = $this->variant($variantId);

        $this->resetForm();
        $this->editingId = $variant->getKey();
        $this->editingSku = $variant->sku;
        $this->sizeId = $variant->size_id;
        $this->colorId = $variant->color_id;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $product = $this->product();

        // Un stock sin escribir no es un stock inválido: la variante nueva nace
        // sin existencias y el cero se entiende antes de preguntar por el campo,
        // de modo que un formulario que simplemente quedó en blanco se guarda.
        if ($this->editingId === null && $this->initialStock === '') {
            $this->initialStock = '0';
        }

        // The sizes belong to a category, so the only sizes this form can offer are
        // the ones of the category of this product. A size id of another category
        // therefore never reaches the actions: the field is already refused here, with
        // a message under the select instead of a driver error about a foreign key.
        //
        // The color is refused the same way, but a color also stops being offered when
        // it is turned off, and a variant that is already in a turned-off color has to
        // stay savable without being moved. The rule is therefore any color the store
        // still sells, plus the one this variant already carries: anything else never
        // reaches the actions, and is reported under its own select.
        $currentColorId = $this->currentColorId();

        $validated = $this->validate([
            'sizeId' => [
                'required',
                'integer',
                Rule::exists('sizes', 'id')->where('category_id', $product->category_id),
            ],
            'colorId' => [
                'required',
                'integer',
                Rule::exists('colors', 'id')->where(
                    function (QueryBuilder $query) use ($currentColorId): void {
                        $query->where('is_active', true);

                        if ($currentColorId !== null) {
                            $query->orWhere('id', $currentColorId);
                        }
                    },
                ),
            ],
            'initialStock' => ['nullable', 'integer', 'min:0'],
        ], [
            'sizeId.required' => 'Selecciona una talla.',
            'sizeId.integer' => 'La talla seleccionada no es válida.',
            'sizeId.exists' => 'La talla seleccionada no pertenece a la categoría del producto.',
            'colorId.required' => 'Selecciona un color.',
            'colorId.integer' => 'El color seleccionado no es válido.',
            'colorId.exists' => 'El color seleccionado no está disponible.',
            'initialStock.integer' => 'El stock inicial debe ser un número entero.',
            'initialStock.min' => 'El stock inicial no puede ser negativo.',
        ]);

        $color = Color::query()->findOrFail($validated['colorId']);

        try {
            if ($this->editingId !== null) {
                (new UpdateProductVariant)(
                    $product,
                    $this->editingId,
                    (int) $validated['sizeId'],
                    $color,
                    // La tienda vende todas sus variantes al precio del producto,
                    // así que el panel no escribe `price_override`: una variante
                    // que ya tuviera uno lo conserva en vez de perderlo al
                    // cambiarle la talla o el color.
                    $this->variant($this->editingId)->price_override,
                );

                $message = 'Variante actualizada correctamente.';
            } else {
                (new CreateProductVariant)(
                    $product,
                    (int) $validated['sizeId'],
                    $color,
                    null,
                    (int) ($validated['initialStock'] ?? 0),
                    $this->user(),
                );

                $message = 'Variante creada correctamente.';
            }
        } catch (DuplicateProductVariantException|InvalidVariantSizeException|InactiveVariantSizeException $exception) {
            $this->addError('sizeId', $exception->getMessage());

            return;
        } catch (InactiveVariantColorException $exception) {
            // El color desactivado se avisa bajo su propio select y no junto a la
            // talla: son dos campos distintos con dos motivos distintos.
            $this->addError('colorId', $exception->getMessage());

            return;
        } catch (InvalidStockAdjustmentException $exception) {
            $this->addError('initialStock', $exception->getMessage());

            return;
        }

        $this->resetForm();
        $this->notifySuccess($message);
        $this->announceChange();
    }

    public function startAdjusting(int $variantId): void
    {
        $this->variant($variantId);

        $this->cancelAdjusting();
        $this->adjustingId = $variantId;
    }

    public function cancelAdjusting(): void
    {
        $this->adjustingId = null;
        $this->stockDelta = '';
        $this->stockReason = '';
        $this->resetValidation(['stockDelta', 'stockReason']);
    }

    public function adjustStock(): void
    {
        $validated = $this->validate([
            'stockDelta' => ['required', 'integer', 'not_in:0'],
            'stockReason' => ['required', 'string', 'max:'.AdjustProductVariantStock::MAX_REASON_LENGTH],
        ], [
            'stockDelta.required' => 'Indica cuántas unidades entran o salen.',
            'stockDelta.integer' => 'El ajuste debe ser un número entero.',
            'stockDelta.not_in' => 'El ajuste no puede ser cero.',
            'stockReason.required' => 'Indica el motivo del ajuste.',
            'stockReason.max' => 'El motivo no puede superar los '.AdjustProductVariantStock::MAX_REASON_LENGTH.' caracteres.',
        ]);

        try {
            (new AdjustProductVariantStock)(
                $this->product(),
                (int) $this->adjustingId,
                (int) $validated['stockDelta'],
                trim($validated['stockReason']),
                $this->user(),
            );
        } catch (InvalidStockAdjustmentException|InsufficientStockException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->cancelAdjusting();
        $this->notifySuccess('Stock ajustado correctamente.');
        $this->announceChange();
    }

    public function toggle(int $variantId): void
    {
        $variant = (new ToggleProductVariant)($this->product(), $variantId);

        $this->notifySuccess($variant->is_active
            ? "Variante «{$variant->sku}» activada correctamente."
            : "Variante «{$variant->sku}» desactivada correctamente.");

        $this->announceChange();
    }

    /**
     * The button is always offered, the way it is in the categories panel, and a
     * variant that cannot be deleted says why instead of the panel hiding it: the
     * rule lives in the action, and reading it here to grey the button out would
     * be a second copy of it that could go stale.
     */
    public function delete(int $variantId): void
    {
        try {
            (new DeleteProductVariant)($this->product(), $variantId);
        } catch (ProductVariantNotDeletableException $exception) {
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess('Variante eliminada correctamente.');
        $this->announceChange();
    }

    public function render()
    {
        $product = $this->product();

        // The variants come in a single query with their color and their size already
        // loaded, and the order is settled in memory over those loaded relations:
        // reading a color or a size per variant would be the N+1 this tab must not
        // have. `size` is read for the order of the rows and for their label.
        $variants = $product->variants()
            ->with(['color', 'size'])
            ->get();

        return view('livewire.admin.products.variants', [
            'product' => $product,
            'variants' => $this->sortVariants($variants),
            'colors' => $this->colorsForTheForm(),
            'sizes' => $this->sizesForTheForm($product),
        ]);
    }

    /**
     * The colors the form may write, which are the ones the store still sells.
     *
     * A variant being edited also sees the color it is already in, even when that color
     * is turned off: the variant has to be editable without being moved, and a select
     * that does not carry the value it is holding would silently write something else.
     * The actions are the ones that decide whether a color may be taken, so the select
     * is not where that rule lives; it only refuses ids that are not in the store, which
     * is what `colorId` is validated against.
     *
     * The order is the one the catalog reads colors in, so the form offers the same list
     * in the same order as the rest of the panel.
     *
     * @return Collection<int, Color>
     */
    private function colorsForTheForm(): Collection
    {
        $currentColorId = $this->currentColorId();

        if ($currentColorId === null) {
            return Color::listedActive();
        }

        return Color::query()
            ->where(
                fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->orWhere('id', $currentColorId)
            )
            ->ordered()
            ->get();
    }

    /**
     * The color the variant being edited already carries, or null while creating.
     *
     * It is read from the stored variant instead of from `$colorId`, because the value
     * the form is holding is exactly what an id typed by hand could be trying to
     * replace.
     */
    private function currentColorId(): ?int
    {
        return $this->editingId === null ? null : $this->variant($this->editingId)->color_id;
    }

    /**
     * The sizes the form may write, which are the ones the category of this product
     * still offers.
     *
     * A variant being edited also sees the size it is already in, even when that size
     * is turned off: the variant has to be editable without being moved, and a select
     * that does not carry the value it is holding would silently write something else.
     * The actions are the ones that decide whether a size may be taken, so the select
     * is not where that rule lives.
     *
     * @return Collection<int, Size>
     */
    private function sizesForTheForm(Product $product): Collection
    {
        $currentSizeId = $this->editingId === null ? null : $this->variant($this->editingId)->size_id;

        return $product->category->sizes()
            ->when(
                $currentSizeId === null,
                fn (Builder $query): Builder => $query->active(),
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->orWhere('id', $currentSizeId)
                ),
            )
            ->get();
    }

    /**
     * Variants in the order the catalog reads them: the sizes in the order the
     * category carries them instead of alphabetically, then the color by name, and
     * the id to break the tie between two rows of the same combination.
     *
     * The order is the `order` column of the size, which is what the panel lets the
     * store settle, and the id of the size breaks the tie between two sizes that
     * share it.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return Collection<int, ProductVariant>
     */
    private function sortVariants(Collection $variants): Collection
    {
        return $variants
            ->sortBy([
                fn (ProductVariant $a, ProductVariant $b): int => ($a->size->order ?? PHP_INT_MAX)
                    <=> ($b->size->order ?? PHP_INT_MAX),
                fn (ProductVariant $a, ProductVariant $b): int => ($a->size_id ?? 0)
                    <=> ($b->size_id ?? 0),
                fn (ProductVariant $a, ProductVariant $b): string => ($a->color->name ?? '')
                    <=> ($b->color->name ?? ''),
                fn (ProductVariant $a, ProductVariant $b): int => $a->getKey() <=> $b->getKey(),
            ])
            ->values();
    }

    /**
     * Tell the product listing that the stock or the status it shows may be out
     * of date. It is what the tab is mounted with, so it needs no product name.
     */
    private function announceChange(): void
    {
        $this->dispatch(self::CHANGED_EVENT, productId: $this->productId);
    }

    private function product(): Product
    {
        return Product::query()->findOrFail($this->productId);
    }

    private function variant(int $variantId): ProductVariant
    {
        return $this->product()->variants()->findOrFail($variantId);
    }

    /**
     * The admin behind the change, when there is one: the actions take it as
     * optional so that they stay usable from a command or a test.
     */
    private function user(): ?User
    {
        return auth()->user();
    }

    /**
     * A variant in this store is sold at the price of its product.
     *
     * The panel therefore never asks for a price, and `price_override` is a
     * column the actions still accept for the variants that carry one from
     * before: editing a variant leaves it as it was instead of quietly erasing
     * it.
     */
    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->editingSku = null;
        $this->sizeId = null;
        $this->colorId = null;
        $this->initialStock = '';
        $this->resetValidation(['sizeId', 'colorId', 'initialStock']);
    }
}
