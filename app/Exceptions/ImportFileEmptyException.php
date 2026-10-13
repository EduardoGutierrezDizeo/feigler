<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The file has no header row at all.
 *
 * The columns of the file are named in its first row, so a file without one
 * cannot be read even in part: there is nothing that says which cell is the
 * price and which is the size. It is told apart from a file that names its
 * columns but leaves some out, which is `ImportHeadersMissingException`.
 */
class ImportFileEmptyException extends RuntimeException
{
    public static function withoutHeaders(): self
    {
        return new self('El archivo está vacío: le falta la primera fila con los nombres de las columnas.');
    }
}
