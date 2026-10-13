<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The file is not one the import knows how to read.
 *
 * What decides how a file is read is its extension: a CSV is parsed with the
 * detection of encoding and separator, and an XLSX is opened as a workbook. A
 * file that is neither is refused before anything is read from it, so the admin
 * is told the file is the problem and not one of its rows.
 */
class UnsupportedImportFormatException extends RuntimeException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function forExtension(string $extension, array $allowed): self
    {
        $written = $extension === '' ? 'sin extensión' : '.'.$extension;
        $formats = implode(' y ', array_map(fn (string $format): string => strtoupper($format), $allowed));

        return new self("El archivo «{$written}» no se puede leer. Solo se aceptan archivos {$formats}.");
    }
}
