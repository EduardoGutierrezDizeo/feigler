<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProductNameException extends RuntimeException
{
    /**
     * A name made only of signs and punctuation leaves nothing to build a slug
     * out of, and the slug is the address the storefront links to: the product
     * would be stored with an empty address that nothing can reach.
     *
     * It is caught before the insert so the admin is told what is wrong with the
     * name instead of reading a driver error about the unique index on an empty
     * string.
     */
    public static function withoutLettersOrNumbers(): self
    {
        return new self(
            'El nombre debe contener letras o números.'
        );
    }
}
