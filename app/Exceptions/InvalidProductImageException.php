<?php

namespace App\Exceptions;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class InvalidProductImageException extends RuntimeException
{
    /**
     * The file is bigger than the store accepts.
     *
     * The pictures are served as they are uploaded, and an admin adding a handful of
     * photos should not be able to fill the disk of the store with a single file.
     */
    public static function tooLarge(UploadedFile $file, int $maxKilobytes): self
    {
        return new self(
            "La imagen «{$file->getClientOriginalName()}» pesa más de {$maxKilobytes} KB."
        );
    }

    /**
     * The file is not one of the image formats the store accepts.
     *
     * Anything else would be stored and then offered to a browser that cannot show
     * it, and a photo dropped in by mistake (a PDF, a text file) is refused here
     * instead of by the browser after the upload is already done.
     *
     * @param  list<string>  $allowedExtensions
     */
    public static function unsupportedFormat(UploadedFile $file, array $allowedExtensions): self
    {
        return new self(
            "La imagen «{$file->getClientOriginalName()}» no tiene un formato admitido; "
            .'los formatos permitidos son: '.implode(', ', $allowedExtensions).'.'
        );
    }
}
