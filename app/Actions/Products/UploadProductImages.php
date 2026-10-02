<?php

namespace App\Actions\Products;

use App\Exceptions\InvalidProductImageException;
use App\Exceptions\ProductImageColorNotInProductException;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadProductImages
{
    /**
     * The heaviest a picture can be, in kilobytes.
     */
    public const MAX_SIZE_KB = 4096;

    /**
     * The formats the store accepts for a product image.
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Add pictures of a color to a product.
     *
     * The files are written before the rows, and the rows are written inside a
     * transaction: a row that points at a file that was never stored is an image
     * nobody can see, whereas a file left behind by a transaction that failed is
     * a picture with no row, which this action undoes on the way out.
     *
     * The name of a file is a hash and the folder is the product and the color, so
     * two uploads of the same photo of two products never collide, and the folder
     * of a product and a color can be emptied in one go.
     *
     * The first picture that reaches a color is its main one, so a color never
     * sits in the catalog without a picture to show. The rest is stored as it comes
     * and each one takes the next `order` of its color, which is the order they
     * were uploaded in: there is no manual order to keep in step with.
     *
     * A product without a cover color is given this one, because a product whose
     * first pictures arrive is a product that needs a cover, and this is the color
     * they came in. A product that already has one keeps it.
     *
     * @param  array<array-key, UploadedFile>  $files
     * @return Collection<int, ProductImage>
     */
    public function __invoke(Product $product, Color $color, array $files): Collection
    {
        $files = array_values($files);

        $this->guardColorIsSoldIn($product, $color);

        foreach ($files as $file) {
            $this->guardFileIsAcceptable($file);
        }

        $paths = $this->storeFiles($product, $color, $files);

        try {
            return DB::transaction(function () use ($product, $color, $paths): Collection {
                $fresh = $product->newQuery()->lockForUpdate()->findOrFail($product->getKey());

                // Read inside the lock: whether this color already has a picture and
                // what its next order is are both questions two admins uploading at
                // the same time would answer differently.
                $galeria = $fresh->imagesForColor($color)->get(['id', 'order']);

                $isFirstOfItsColor = $galeria->isEmpty();
                $nextOrder = (int) $galeria->max('order') + 1;

                $images = new Collection;

                foreach ($paths as $position => $path) {
                    $images->push($fresh->images()->create([
                        'color_id' => $color->getKey(),
                        'path' => $path,
                        'order' => $nextOrder + $position,
                        'is_primary' => $isFirstOfItsColor && $position === 0,
                    ]));
                }

                if ($fresh->cover_color_id === null) {
                    $fresh->update(['cover_color_id' => $color->getKey()]);
                }

                return $images;
            });
        } catch (Throwable $exception) {
            $this->discardFiles($paths);

            throw $exception;
        }
    }

    /**
     * Refuse a color the product is not sold in.
     *
     * A gallery is only reachable from a variant of that color, so pictures of a
     * color with no variant would be stored where nobody would ever see them.
     */
    private function guardColorIsSoldIn(Product $product, Color $color): void
    {
        if (! $product->colors()->contains('id', $color->getKey())) {
            throw ProductImageColorNotInProductException::forProduct($product, $color);
        }
    }

    /**
     * Refuse a file that is too heavy or is not a picture at all.
     */
    private function guardFileIsAcceptable(UploadedFile $file): void
    {
        $size = $file->getSize();

        if ($size !== false && $size > self::MAX_SIZE_KB * 1024) {
            throw InvalidProductImageException::tooLarge($file, self::MAX_SIZE_KB);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw InvalidProductImageException::unsupportedFormat($file, self::ALLOWED_EXTENSIONS);
        }
    }

    /**
     * Where the pictures of a color live: `products/{product}/{color}`.
     *
     * @param  array<array-key, UploadedFile>  $files
     * @return list<string>
     */
    private function storeFiles(Product $product, Color $color, array $files): array
    {
        $directory = sprintf('products/%s/%s', $product->getKey(), $color->getKey());

        return array_map(
            fn (UploadedFile $file): string => $file->storeAs(
                $directory,
                $file->hashName(),
                ['disk' => ProductImage::DISK],
            ),
            $files,
        );
    }

    /**
     * Erase the files of an upload whose rows never made it into the database.
     *
     * @param  list<string>  $paths
     */
    private function discardFiles(array $paths): void
    {
        $disk = Storage::disk(ProductImage::DISK);

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }
}
