<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The file has more rows than the import is allowed to read.
 *
 * The rows are read to build a preview of the whole file, and the maximum is the
 * point where that preview stops being something an admin reads at a glance. The
 * reader stops as soon as the row past the maximum is seen, so an oversized file
 * is refused without being read to its end.
 */
class ImportRowLimitExceededException extends RuntimeException
{
    public static function forRows(int $maxRows): self
    {
        return new self("El archivo supera el máximo de {$maxRows} filas. Divídelo en varios archivos.");
    }
}
