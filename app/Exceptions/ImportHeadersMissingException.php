<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The file names its columns but leaves some of the required ones out.
 *
 * Every column the import reads is required, because a file without a price or
 * without a size does not describe a garment that can be written: the whole file
 * is refused here and not row by row, since the missing column is one and the
 * same for every row and fixing it is rewriting the header.
 */
class ImportHeadersMissingException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function forHeaders(array $missing): self
    {
        $columns = implode(', ', array_map(fn (string $column): string => "«{$column}»", $missing));

        return new self("Al archivo le faltan columnas obligatorias: {$columns}.");
    }
}
