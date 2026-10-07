<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Products\DeleteProductImage;
use App\Actions\Products\SetPrimaryProductImage;
use App\Actions\Products\SetProductCoverColor;
use App\Actions\Products\UploadProductImages;
use App\Exceptions\InvalidProductImageException;
use App\Exceptions\ProductCoverColorWithoutImagesException;
use App\Exceptions\ProductImageColorNotInProductException;
use App\Livewire\Concerns\Notifies;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class Images extends Component
{
    use Notifies;
    use WithFileUploads;

    /**
     * The event the tab broadcasts after any change, so the product listing that
     * sits underneath can re-read whatever it shows of the pictures.
     */
    public const CHANGED_EVENT = 'product-images-changed';

    /**
     * The event that takes the admin to the tab where the colors of a product are
     * written down, which is the only way a picture reaches this tab.
     */
    public const VARIANTS_TAB_EVENT = 'open-product-variants-tab';

    public int $productId;

    /**
     * The pictures picked for each color and still waiting to be uploaded.
     *
     * They are kept per color instead of in one pile so that the pictures chosen
     * for the blue are never stored as if they were taken in the red.
     *
     * @var array<int, array<int, mixed>>
     */
    public array $uploads = [];

    /**
     * The color of the cover as the selector holds it, which is the cover the
     * product has: an action that refuses the choice puts the real one back here.
     */
    public ?int $coverColorId = null;

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
        $product = $this->product();

        $this->coverColorId = $product->cover_color_id;
    }

    /**
     * Store the pictures picked for a color.
     *
     * What a picture is allowed to be lives in the action and not here: this only
     * checks that something was picked, and the answer of the action, which names
     * the file it is refusing, is what the admin reads under the picker.
     *
     * The name is not `upload` because that word is already taken in `$wire` by
     * Livewire's own file upload (`$wire.upload`), and a method of ours by that
     * name would never be reached from a `wire:click`: the JavaScript would win.
     */
    public function uploadImages(int $colorId): void
    {
        $validated = $this->validate([
            "uploads.{$colorId}" => ['required', 'array', 'min:1'],
        ], [
            "uploads.{$colorId}.required" => 'Selecciona al menos una imagen.',
            "uploads.{$colorId}.array" => 'La selección de imágenes no es válida.',
            "uploads.{$colorId}.min" => 'Selecciona al menos una imagen.',
        ]);

        // Lo que devuelve la validación viene anidado (`uploads` con el color
        // dentro), no con la clave con punto que se le pidió al validador.
        $files = data_get($validated, "uploads.{$colorId}");

        // El selector se vacía antes de subir y no después: una imagen que la
        // acción rechaza hay que volver a elegirla, y dejar su nombre en el
        // campo solo ofrecería el mismo rechazo otra vez.
        $this->uploads = [];

        $product = $this->product();

        try {
            $images = (new UploadProductImages)(
                $product,
                Color::query()->findOrFail($colorId),
                $files,
            );
        } catch (InvalidProductImageException|ProductImageColorNotInProductException $exception) {
            // El error queda bajo el selector del color y también como aviso: hay
            // negativas que llegan por un color al que la pestaña ni siquiera le
            // dibuja una ficha.
            $this->addError("uploads.{$colorId}", $exception->getMessage());
            $this->notifyError($exception->getMessage());

            return;
        }

        // El primer color con fotos es la portada del producto, y el selector de
        // arriba se pinta con el estado de esta propiedad: sin esta lectura
        // seguiría mostrando «Sin portada» sobre un producto que ya tiene una.
        $this->coverColorId = $product->refresh()->cover_color_id;

        $this->notifySuccess($images->count() === 1
            ? 'Imagen subida correctamente.'
            : $images->count().' imágenes subidas correctamente.');

        $this->announceChange();
    }

    public function makePrimary(int $imageId): void
    {
        (new SetPrimaryProductImage)($this->product(), $imageId);

        $this->notifySuccess('Imagen principal actualizada correctamente.');
        $this->announceChange();
    }

    public function delete(int $imageId): void
    {
        $product = $this->product();

        (new DeleteProductImage)($product, $imageId);

        // Borrar la última foto de la portada deja al producto sin ella, y el
        // selector tiene que volver a «Sin portada» con la misma razón.
        $this->coverColorId = $product->refresh()->cover_color_id;

        $this->notifySuccess('Imagen eliminada correctamente.');
        $this->announceChange();
    }

    /**
     * Say which color stands for the product in the catalog, or take the cover
     * away by passing null.
     *
     * Choosing the color that is already the cover changes nothing and says
     * nothing: that is also what stops the selector from answering its own
     * refusal, which puts the real cover back in place.
     */
    public function setCover(?int $colorId): void
    {
        $product = $this->product();

        if ($colorId === $product->cover_color_id) {
            return;
        }

        try {
            (new SetProductCoverColor)(
                $product,
                $colorId === null ? null : Color::query()->findOrFail($colorId),
            );
        } catch (ProductCoverColorWithoutImagesException $exception) {
            $this->coverColorId = $product->cover_color_id;
            $this->addError('coverColorId', $exception->getMessage());
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->coverColorId = $colorId;

        $this->notifySuccess('Color de portada actualizado correctamente.');
        $this->announceChange();
    }

    /**
     * The selector of the cover writes the property and the choice is applied from
     * here, so the same path serves the admin at the keyboard and a call by hand.
     */
    public function updatedCoverColorId(?int $colorId): void
    {
        $this->setCover($colorId);
    }

    /**
     * A variant was created, edited, toggled or deleted: the colors this product
     * is sold in may have moved, and the tab reads them again on its own next
     * render, which is the automatic one this listener brings.
     *
     * The id names the product that changed, and it is the only thing this tab
     * watches for: a color that stopped being sold is no longer where a cover
     * could sit, so the cover it had is dropped without a word.
     *
     * An event with no id at all may arrive from other places, and is treated as
     * if it were this product's own, in case it comes from a hand-built message.
     */
    #[On(Variants::CHANGED_EVENT)]
    public function refreshColors(?int $productId = null): void
    {
        if ($productId !== null && $productId !== $this->productId) {
            return;
        }

        if ($this->coverColorId === null) {
            return;
        }

        $soldColors = $this->product()->variants()->pluck('color_id');

        if (! $soldColors->contains($this->coverColorId)) {
            $this->coverColorId = null;
        }
    }

    /**
     * Take the admin to the tab where the colors of this product are written
     * down, which is where a product without variants has to start.
     */
    public function openVariantsTab(): void
    {
        $this->dispatch(self::VARIANTS_TAB_EVENT);
    }

    public function render()
    {
        $product = $this->product();

        // One trip to the database for the whole panel: the variants with their
        // color, the pictures with theirs and the color of the cover. The gallery
        // of each color is settled in memory over that loaded relation, because
        // asking `imagesForColor()` once per color is the N+1 this tab must not
        // have.
        $product->load(['variants.color', 'images.color', 'coverColor']);

        return view('livewire.admin.products.images', [
            'product' => $product,
            'colors' => $this->soldColors($product),
            'galleries' => $this->galleries($product),
        ]);
    }

    /**
     * The colors the product is sold in, by name.
     *
     * These are the same colors `Product::colors()` answers, taken from the
     * variants that came with the panel so the tab does not read them twice.
     *
     * @return Collection<int, Color>
     */
    private function soldColors(Product $product): Collection
    {
        return $product->variants
            ->pluck('color')
            ->filter()
            ->unique('id')
            ->sortBy([
                fn (Color $a, Color $b): string => $a->name <=> $b->name,
                fn (Color $a, Color $b): int => $a->getKey() <=> $b->getKey(),
            ])
            ->values();
    }

    /**
     * The pictures of each color, in the order they were uploaded.
     *
     * The order is the same one `Product::imagesForColor()` asks the database
     * for, and it is settled here in memory over a single read of all the
     * pictures of the product.
     *
     * @return Collection<int, Collection<int, ProductImage>>
     */
    private function galleries(Product $product): Collection
    {
        return $product->images
            ->sortBy([
                fn (ProductImage $a, ProductImage $b): int => $a->order <=> $b->order,
                fn (ProductImage $a, ProductImage $b): int => $a->getKey() <=> $b->getKey(),
            ])
            ->groupBy('color_id');
    }

    /**
     * Tell the product listing that whatever it shows of the pictures may be out
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

    /**
     * The admin behind the change, when there is one.
     */
    private function user(): ?User
    {
        return auth()->user();
    }
}
