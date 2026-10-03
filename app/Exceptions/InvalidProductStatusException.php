<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProductStatusException extends RuntimeException
{
    /**
     * Only the two states the catalog is browsed by live in the column.
     *
     * `out_of_stock` is what the stock of the variants computes, so writing it
     * down would freeze a status that goes stale the moment a movement is
     * recorded. The wording does not list the valid states as a rule does: it
     * says what the value is not allowed to be, and it is what the caller shows.
     */
    public static function notWritable(string $status): self
    {
        return new self(
            "El estado «{$status}» no se guarda: un producto se almacena como «active» o «inactive», y los demás se calculan a partir de sus variantes."
        );
    }
}
