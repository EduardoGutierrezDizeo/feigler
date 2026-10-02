<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Makes the small copy of a picture that the catalog shows.
 *
 * The original is never replaced: it is the file an admin downloads and the one
 * a future crop would be made from, so what is written next to it is a WebP of
 * at most 480 px on its longest side and quality 80, which is what makes a
 * listing of dozens of pictures weigh tens of kilobytes instead of megabytes. A
 * picture smaller than that is copied at its own size and never enlarged,
 * because a blown-up 200 px picture is heavier without being sharper.
 *
 * A thumbnail that cannot be made is not a failure: the answer is null, the
 * reason is written to the log with the product and the color it belongs to, and
 * the catalog falls back to the original. Losing a thumbnail costs a slower
 * page; losing the picture over it would cost a sale.
 *
 * The name of the thumbnail is the name of the original with `.webp`, inside a
 * `thumbs` folder of the same product and color, which is what makes it
 * predictable: the same original always lands on the same address, so a rebuild
 * overwrites the copy instead of filling the folder with near-duplicates, and
 * the folder of a product and a color can be emptied in one go.
 */
class ProductImageThumbnailer
{
    /**
     * The longest side a thumbnail may have, in pixels.
     */
    public const MAX_EDGE_PX = 480;

    /**
     * The WebP quality a thumbnail is encoded with.
     */
    public const QUALITY = 80;

    /**
     * How many pixels a picture may have before it is left without a thumbnail.
     *
     * Decoding a picture costs about four bytes per pixel, so twenty million
     * pixels are already eighty megabytes of memory for a single picture: one
     * bigger is a decompression bomb wearing a `.jpg` extension, and a store has
     * no reason to spend a request on it. The original stays, unthumbnailed.
     *
     * It is a constructor argument and not only a constant so that a test can
     * bring the ceiling within reach of a picture of a few pixels instead of
     * having to build one of twenty megapixels.
     */
    public const MAX_PIXELS = 20_000_000;

    /**
     * @param  int  $maxPixels  How many pixels a picture may have to be thumbnailed.
     */
    public function __construct(
        private readonly ImageManager $images,
        private readonly int $maxPixels = self::MAX_PIXELS,
    ) {
        //
    }

    /**
     * Make the thumbnail of an original and write it to the disk.
     *
     * @param  string  $originalPath  Where the original was stored.
     * @param  string|int|null  $productId  Only used to name the failure in the log.
     * @param  string|int|null  $colorId  Only used to name the failure in the log.
     * @return string|null The address of the thumbnail, or null when there is none.
     */
    public function __invoke(string $originalPath, string|int|null $productId = null, string|int|null $colorId = null): ?string
    {
        try {
            $contents = Storage::disk(ProductImage::DISK)->get($originalPath);

            $pixels = $this->pixelsOf($contents);

            if ($pixels === null) {
                $this->reportFailure($originalPath, $productId, $colorId, 'el archivo no es una imagen que se pueda leer');

                return null;
            }

            if ($pixels > $this->maxPixels) {
                $this->reportFailure(
                    $originalPath,
                    $productId,
                    $colorId,
                    sprintf('tiene %d píxeles, más de los %d permitidos', $pixels, $this->maxPixels),
                );

                return null;
            }

            $thumbnail = $this->images->read($contents)
                ->scaleDown(self::MAX_EDGE_PX, self::MAX_EDGE_PX)
                ->encode(new WebpEncoder(quality: self::QUALITY));

            $thumbnailPath = $this->thumbnailPathFor($originalPath);

            Storage::disk(ProductImage::DISK)->put($thumbnailPath, $thumbnail);

            return $thumbnailPath;
        } catch (Throwable $exception) {
            $this->reportFailure($originalPath, $productId, $colorId, $exception->getMessage());

            return null;
        }
    }

    /**
     * How many pixels a picture has, read from its header alone.
     *
     * The size is asked of the header and not of the decoder on purpose: the
     * whole point of the ceiling is to be checked before the picture is decoded,
     * since decoding it is exactly what costs the memory.
     *
     * @return int|null Null when the file has no readable header, which is a file that is not a picture.
     */
    private function pixelsOf(string $contents): ?int
    {
        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            return null;
        }

        return (int) $size[0] * (int) $size[1];
    }

    /**
     * Where the thumbnail of an original belongs.
     *
     * `products/{product}/{color}/camisa.jpg` is thumbnailed to
     * `products/{product}/{color}/thumbs/camisa.webp`, which is the only folder
     * of the gallery that is not a picture in itself.
     */
    private function thumbnailPathFor(string $originalPath): string
    {
        return sprintf(
            '%s/thumbs/%s.webp',
            trim(dirname($originalPath), '/'),
            pathinfo($originalPath, PATHINFO_FILENAME),
        );
    }

    /**
     * Say in the log which picture was left without a thumbnail and why.
     */
    private function reportFailure(string $originalPath, string|int|null $productId, string|int|null $colorId, string $reason): void
    {
        Log::warning(sprintf(
            'No se pudo generar la miniatura de "%s" (producto %s, color %s): %s',
            $originalPath,
            $productId ?? 'desconocido',
            $colorId ?? 'desconocido',
            $reason,
        ));
    }
}
