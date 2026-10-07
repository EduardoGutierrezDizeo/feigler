<?php

namespace App\Livewire\Admin\HomePage;

use App\Actions\HomePage\SetCategoryHomeImageAuto;
use App\Actions\HomePage\SetCategoryHomeImageProduct;
use App\Actions\HomePage\UploadCategoryHomeImage;
use App\Enums\HomeImageSource;
use App\Enums\StoreSection;
use App\Exceptions\HomeImageProductException;
use App\Exceptions\InvalidProductImageException;
use App\Livewire\Concerns\Notifies;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Storefront\HomeCategoryImages;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Categories extends Component
{
    use Notifies;
    use WithFileUploads;

    /**
     * The most recent photos the picker offers; anything beyond this limit is
     * told apart in the wording under the grid.
     */
    private const PICKER_LIMIT = 48;

    /**
     * The section of the store being managed: `hombre`, `mujer` or `ninos`.
     *
     * It is part of the URL so that the section can be shared, bookmarked or
     * reached with the back button, and it is reset to `hombre` whenever it
     * holds a value the enum does not know.
     */
    #[Url(as: 'seccion')]
    public string $section = StoreSection::Hombre->value;

    public bool $showForm = false;

    public ?int $editingCategoryId = null;

    /**
     * Which of the three sources the photo is coming from, as the radio group
     * holds it: `auto`, `upload` or `product`.
     */
    public string $photoOption = HomeImageSource::Auto->value;

    public $imageUpload;

    public ?int $selectedImageId = null;

    /**
     * Whether the picker had to cut the list off at the newest photos.
     */
    public bool $pickerTruncated = false;

    /**
     * The tab lives under `role:admin`, but a Livewire request is not that
     * page's request: `/livewire/update` reopens the component on its own, so
     * every public method here is reachable by anyone who reaches that
     * endpoint, with any id they please. The rule of the route is asked again
     * here, on the mount and on every request after it.
     */
    public function booted(): void
    {
        abort_unless($this->user()?->hasRole('admin'), 403);
    }

    public function mount(): void
    {
        $this->resetForm();
    }

    /**
     * Switch the section being managed, from the tab bar.
     *
     * A section that does not exist falls back to `hombre` instead of leaving
     * the panel listing nothing, and the form is reset: it was opened for the
     * category of another section.
     */
    public function setSection(string $section): void
    {
        $this->section = StoreSection::tryFrom($section)?->value ?? StoreSection::Hombre->value;

        $this->resetForm();
    }

    /**
     * Open the photo form for a category, showing the source it is already on.
     */
    public function changePhoto(Category $category): void
    {
        $this->resetForm();
        $this->editingCategoryId = $category->getKey();
        $this->photoOption = $this->currentOptionOf($category);
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    /**
     * Put the category back on the automatic rule.
     */
    public function useAutomatic(): void
    {
        $category = $this->editingCategory();

        (new SetCategoryHomeImageAuto)($category);

        $this->notifySuccess('La categoría vuelve a usar su foto automática.');
        $this->resetForm();
    }

    /**
     * Store the photo the administrator uploaded for the category.
     *
     * What a file is allowed to be lives in the action, not here: this only
     * checks that something was picked, and the answer of the action, which
     * names the file it is refusing, is what the admin reads under the picker.
     */
    public function saveImage(): void
    {
        $validated = $this->validate([
            'imageUpload' => ['required'],
        ], [
            'imageUpload.required' => 'Selecciona una imagen para subir.',
        ]);

        $category = $this->editingCategory();

        try {
            (new UploadCategoryHomeImage)($category, $validated['imageUpload']);
        } catch (InvalidProductImageException $exception) {
            $this->addError('imageUpload', $exception->getMessage());
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess('Foto de portada guardada correctamente.');
        $this->resetForm();
    }

    /**
     * Show the photo of one of its products as the home picture of the
     * category.
     *
     * The fairness rules live in the action; here the picture is only checked
     * to still exist, which is what a picker that was open for a while may not
     * guarantee any more.
     */
    public function useProductPhoto(int $imageId): void
    {
        $image = ProductImage::query()->find($imageId);

        if ($image === null) {
            $message = 'La foto elegida ya no está disponible.';

            $this->addError('selectedImageId', $message);
            $this->notifyError($message);

            return;
        }

        $category = $this->editingCategory();

        try {
            (new SetCategoryHomeImageProduct)($category, $image);
        } catch (HomeImageProductException $exception) {
            $this->addError('selectedImageId', $exception->getMessage());
            $this->notifyError($exception->getMessage());

            return;
        }

        $this->notifySuccess('Foto de portada guardada correctamente.');
        $this->resetForm();
    }

    public function render()
    {
        $section = $this->activeSection();

        $categories = $this->categoriesFor($section);

        $homeImages = app(HomeCategoryImages::class)->forCategories($categories);

        [$pickerImages, $pickerTruncated] = $this->pickerFor();

        $this->pickerTruncated = $pickerTruncated;

        $editingCategory = $this->showForm && $this->editingCategoryId !== null
            ? $categories->firstWhere('id', $this->editingCategoryId)
            : null;

        return view('livewire.admin.home-page.categories', [
            'activeSection' => $section,
            'categories' => $categories,
            'homeImages' => $homeImages,
            'pickerImages' => $pickerImages,
            'pickerTruncated' => $pickerTruncated,
            'editingCategory' => $editingCategory,
            'editingPhoto' => $homeImages->get($this->editingCategoryId),
        ]);
    }

    /**
     * The categories of the section, with their visible products and pictures,
     * so the photo each one shows is settled over what was already read.
     *
     * Every category is listed, even one with nothing visible yet: the row
     * warns that it is not on the home page instead of hiding it.
     *
     * @return Collection<int, Category>
     */
    private function categoriesFor(StoreSection $section): Collection
    {
        return Category::query()
            ->inSection($section)
            ->with(['products' => function ($query) {
                $query->visible()
                    ->select('id', 'category_id', 'created_at', 'cover_color_id', 'slug', 'name', 'base_price', 'status')
                    ->with(['images']);
            }])
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The most recent photos of the visible products of the category, for the
     * picker, bound to the newest ones.
     *
     * It is only read while the picker is on screen, which is what keeps the
     * panel at its fixed count the rest of the time.
     *
     * @return array{0: Collection<int, ProductImage>, 1: bool}
     */
    private function pickerFor(): array
    {
        if (! $this->pickerShouldLoad()) {
            return [new Collection, false];
        }

        $products = Product::query()
            ->visible()
            ->where('category_id', $this->editingCategoryId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::PICKER_LIMIT)
            ->with(['images' => function ($query) {
                $query->where('is_primary', true)
                    ->orderBy('order')
                    ->orderBy('id')
                    ->with('color');
            }])
            ->get();

        $images = $products
            ->flatMap(fn (Product $product): Collection => $product->images)
            ->take(self::PICKER_LIMIT)
            ->values();

        return [$images, $products->count() >= self::PICKER_LIMIT || $images->count() >= self::PICKER_LIMIT];
    }

    private function pickerShouldLoad(): bool
    {
        return $this->showForm
            && $this->photoOption === HomeImageSource::Product->value
            && $this->editingCategoryId !== null;
    }

    /**
     * Which source the category already answers to, read as it is stored.
     */
    private function currentOptionOf(Category $category): string
    {
        return HomeImageSource::tryFrom((string) $category->getRawOriginal('home_image_source'))?->value
            ?? HomeImageSource::Auto->value;
    }

    /**
     * The category the form was opened for, which must still be there.
     */
    private function editingCategory(): Category
    {
        return Category::query()->findOrFail($this->editingCategoryId);
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
        $this->editingCategoryId = null;
        $this->photoOption = HomeImageSource::Auto->value;
        $this->imageUpload = null;
        $this->selectedImageId = null;
        $this->resetValidation();
    }
}
