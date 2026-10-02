<?php

namespace App\Enums;

enum StoreSection: string
{
    case Hombre = 'hombre';
    case Mujer = 'mujer';
    case Ninos = 'ninos';

    public function label(): string
    {
        return match ($this) {
            self::Hombre => 'Hombre',
            self::Mujer => 'Mujer',
            self::Ninos => 'Niños',
        };
    }
}
