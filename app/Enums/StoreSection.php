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

    /**
     * Every value of the enum, in declaration order.
     *
     * It is what the gender selector is built from and what the form is validated
     * against, so the two cannot disagree about which genders exist.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
