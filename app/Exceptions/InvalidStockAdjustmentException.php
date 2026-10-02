<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidStockAdjustmentException extends RuntimeException
{
    /**
     * An adjustment of zero units is a no-op that would still leave a movement
     * behind, which is a movement that explains nothing.
     */
    public static function zeroDelta(): self
    {
        return new self(
            'El ajuste tiene que mover al menos una unidad: la cantidad no puede ser cero.'
        );
    }

    /**
     * The note is the only record of why the stock moved, so a movement without
     * one cannot be told apart from a typo later on.
     */
    public static function missingReason(): self
    {
        return new self(
            'El ajuste de stock necesita un motivo, por ejemplo «Recepción de mercancía» o «Merma por rotura».'
        );
    }

    /**
     * The note column is a varchar(255) and MySQL would cut the reason off in
     * strict mode instead of saying so.
     */
    public static function reasonTooLong(int $max): self
    {
        return new self("El motivo del ajuste de stock no puede pasar de {$max} caracteres.");
    }

    /**
     * Stock a variant is born with cannot be negative: a variant with stock is
     * created with zero and then given stock through a movement, which is the
     * only way stock ever changes and the reason it can be told apart later.
     */
    public static function negativeStock(): self
    {
        return new self(
            'El stock inicial no puede ser negativo; deja el stock en 0 y ajústalo después si la mercancía entra más adelante.'
        );
    }
}
