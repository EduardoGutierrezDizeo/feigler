<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The file is heavier than the import is allowed to read.
 *
 * The whole file is read to build the preview, so its size is a limit on what
 * the server is asked to hold: past the maximum the reader stops before opening
 * it, instead of failing halfway through with the memory of the process.
 */
class ImportFileTooLargeException extends RuntimeException
{
    public static function forSize(int $bytes, int $maxKilobytes): self
    {
        $weight = number_format($bytes / 1024 / 1024, 2);
        $limit = number_format($maxKilobytes / 1024, 2);

        return new self("El archivo pesa {$weight} MB y supera el máximo de {$limit} MB.");
    }
}
