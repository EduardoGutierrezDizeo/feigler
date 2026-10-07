<?php

namespace App\Services\Storefront;

use App\Enums\HomeCategoryImageState;
use App\Enums\HomeImageSource;
use App\Models\Category;
use App\Models\ProductImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The home photo of every category, resolved with one rule shared by the
 * storefront and the panel.
 *
 * The home page used to own this whole resolution, and the panel had to be
 * able to show exactly what the home page shows; moving it here lets both read
 * the same answer from the same code. It asks the database at most once per
 * call (for the product pictures that were chosen) and resolves everything
 * else over what the caller already loaded, so both callers keep the same
 * fixed number of queries no matter how many categories the store has.
 */
class HomeCategoryImages
{
    /**
     * The photo and its story, keyed by category id.
     *
     * The collection is keyed by the id on purpose: the home page hands over a
     * flat-mapped collection whose keys are `0..n-1`, and the panel reads one
     * category by id, so neither could trust the original keys.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}>
     */
    public function forCategories(Collection $categories): Collection
    {
        $chosen = $this->chosenImages($categories);

        return $categories->mapWithKeys(fn (Category $category): array => [
            $category->getKey() => $this->forCategory($category, $chosen),
        ]);
    }

    /**
     * The decision a category made, resolved into the photo the store paints.
     *
     * @param  Collection<int, ProductImage>  $chosenImages
     * @return array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}
     */
    private function forCategory(Category $category, Collection $chosenImages): array
    {
        return match ($this->sourceOf($category)) {
            HomeImageSource::Upload => $this->uploaded($category),
            HomeImageSource::Product => $this->chosen($category, $chosenImages),
            default => $this->automatic($category),
        };
    }

    /**
     * The photo the category uploaded, when it is still there to show.
     *
     * @return array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}
     */
    private function uploaded(Category $category): array
    {
        $path = $category->home_image_thumbnail_path ?? $category->home_image_path;

        if ($path === null) {
            return $this->broken($category);
        }

        return [
            'source' => HomeImageSource::Upload,
            'state' => HomeCategoryImageState::Chosen,
            'url' => Storage::disk(ProductImage::DISK)->url($path),
        ];
    }

    /**
     * The product picture a category chose, when the choice still holds.
     *
     * @param  Collection<int, ProductImage>  $chosenImages
     * @return array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}
     */
    private function chosen(Category $category, Collection $chosenImages): array
    {
        $image = $chosenImages->get($category->home_image_product_image_id);

        if ($image === null || $image->product === null) {
            return $this->broken($category);
        }

        if ((int) $image->product->category_id !== (int) $category->getKey()) {
            return $this->broken($category);
        }

        return [
            'source' => HomeImageSource::Product,
            'state' => HomeCategoryImageState::Chosen,
            'url' => $image->thumbnailUrl(),
        ];
    }

    /**
     * A decision that no longer holds: the photo is the automatic one, and the
     * panel says the choice broke instead of hiding the fact.
     *
     * @return array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}
     */
    private function broken(Category $category): array
    {
        $automatic = $this->automatic($category);

        return [
            'source' => $automatic['source'],
            'state' => HomeCategoryImageState::Broken,
            'url' => $automatic['url'],
        ];
    }

    /**
     * The rule the store was born with: the thumbnail of the most recent
     * visible product of the category.
     *
     * @return array{source: HomeImageSource|null, state: HomeCategoryImageState, url: string|null}
     */
    private function automatic(Category $category): array
    {
        return [
            'source' => HomeImageSource::Auto,
            'state' => HomeCategoryImageState::Automatic,
            'url' => $this->automaticUrl($category),
        ];
    }

    /**
     * The most recent visible product is read from the already-loaded relation,
     * so this costs nothing beyond what the caller already asked for.
     */
    private function automaticUrl(Category $category): ?string
    {
        $product = $category->products
            ->sortByDesc('created_at')
            ->sortByDesc('id')
            ->first();

        return $product?->coverImage?->thumbnailUrl();
    }

    /**
     * Which decision a category made, read as it is stored.
     *
     * It reads the raw value and never asks the enum to throw, so a value that
     * is not one of the three behaves like the automatic rule instead of
     * breaking the page.
     */
    private function sourceOf(Category $category): ?HomeImageSource
    {
        return HomeImageSource::tryFrom((string) $category->getRawOriginal('home_image_source'));
    }

    /**
     * The chosen product pictures of every category whose decision asks for
     * one, keyed by image id.
     *
     * The products are loaded with the visibility rule, so a picture whose
     * product stopped being visible comes back without its product and is
     * resolved as broken; a picture that was deleted is simply not among the
     * ids any more. The query is only asked when some category picked a
     * picture, so a read where nobody did keeps its count of queries.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, ProductImage>
     */
    private function chosenImages(Collection $categories): Collection
    {
        $ids = $categories
            ->filter(fn (Category $category): bool => $this->sourceOf($category) === HomeImageSource::Product)
            ->pluck('home_image_product_image_id')
            ->filter()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        return ProductImage::query()
            ->whereIn('id', $ids)
            ->with(['product' => function ($query) {
                $query->visible();
            }])
            ->get()
            ->keyBy('id');
    }
}
