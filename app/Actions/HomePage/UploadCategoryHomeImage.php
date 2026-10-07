<?php

namespace App\Actions\HomePage;

use App\Actions\HomePage\Concerns\ReplacesCategoryHomeFiles;
use App\Actions\Products\UploadProductImages;
use App\Enums\HomeImageSource;
use App\Exceptions\InvalidProductImageException;
use App\Models\Category;
use App\Models\ProductImage;
use App\Services\ProductImageThumbnailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Give a category its own photo on the home page.
 *
 * A category photo is a picture of its own: the administrator uploads it, the
 * home page shows it until the decision changes, and it has nothing to do with
 * the products of the category. It is stored in the same disk and under the same
 * rules as the product pictures, so a file the store would refuse for a product
 * is refused here with the same message.
 *
 * The file is written before the row and the row is written inside a
 * transaction: a row that points at a file that was never stored is a picture
 * nobody can see, whereas a file left behind by a transaction that failed is a
 * picture with no row, which this action undoes on the way out. The thumbnail is
 * made between the two, and a thumbnail that cannot be made does not stop the
 * change: the row is stored without it and the home page shows the original.
 *
 * A photo that replaces another one erases the previous files only once the
 * change is confirmed, and a change that never lands leaves both the old row and
 * the old files untouched while discarding the new file.
 */
class UploadCategoryHomeImage
{
    use ReplacesCategoryHomeFiles;

    /**
     * The folder where the own photos of the categories live.
     */
    public const FOLDER = 'home/categories';

    public function __invoke(Category $category, UploadedFile $file): void
    {
        $this->guardFileIsAcceptable($file);

        $path = $file->storeAs(self::FOLDER, $file->hashName(), ['disk' => ProductImage::DISK]);
        $thumbnail = $this->makeThumbnail($path);

        try {
            $previous = DB::transaction(function () use ($category, $path, $thumbnail): array {
                $fresh = $category->newQuery()->findOrFail($category->getKey());

                $paths = $this->categoryHomeFiles($fresh);

                $fresh->update([
                    'home_image_source' => HomeImageSource::Upload,
                    'home_image_path' => $path,
                    'home_image_thumbnail_path' => $thumbnail,
                    'home_image_product_image_id' => null,
                ]);

                return $paths;
            });
        } catch (Throwable $exception) {
            $this->discardFile($path, $thumbnail);

            throw $exception;
        }

        $this->deleteCategoryHomeFiles($previous);
    }

    /**
     * Refuse a file that is too heavy or is not a picture at all.
     *
     * The rules are the product ones, read from the action that owns them: a
     * category photo and a product photo are the same kind of file.
     */
    private function guardFileIsAcceptable(UploadedFile $file): void
    {
        $size = $file->getSize();

        if ($size !== false && $size > UploadProductImages::MAX_SIZE_KB * 1024) {
            throw InvalidProductImageException::tooLarge($file, UploadProductImages::MAX_SIZE_KB);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, UploadProductImages::ALLOWED_EXTENSIONS, true)) {
            throw InvalidProductImageException::unsupportedFormat($file, UploadProductImages::ALLOWED_EXTENSIONS);
        }
    }

    /**
     * The small copy of the photo, when it can be made.
     *
     * The category has no product and no color to hang a thumbnail from, so
     * neither id is passed to the thumbnailer. A thumbnail that cannot be made
     * is not an error: it leaves the column empty and the home page shows the
     * original, like a product whose thumbnail generation failed.
     */
    private function makeThumbnail(string $path): ?string
    {
        $thumbnailer = app(ProductImageThumbnailer::class);

        return $thumbnailer($path, null, null);
    }

    /**
     * Erase a photo whose row never made it into the database.
     */
    private function discardFile(string $path, ?string $thumbnail): void
    {
        $disk = Storage::disk(ProductImage::DISK);

        foreach (array_filter([$path, $thumbnail]) as $file) {
            $disk->delete($file);
        }
    }
}
