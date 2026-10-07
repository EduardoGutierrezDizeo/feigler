<?php

namespace App\Enums;

/**
 * The pieces of the home page that the panel lets the administrator rearrange.
 *
 * Only one tab exists today, the category pictures, but the container page
 * reads the list of tabs from this enum, so the next piece of the home page
 * only needs its own case here to be reached through the bar.
 */
enum HomePageTab: string
{
    case Categorias = 'categorias';

    public function label(): string
    {
        return match ($this) {
            self::Categorias => 'Categorías',
        };
    }

    /**
     * The one-line purpose of the tab, for the sentence under the title of the page.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Categorias => 'Define qué foto muestra cada categoría en la portada.',
        };
    }
}
