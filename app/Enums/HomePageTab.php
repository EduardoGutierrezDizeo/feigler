<?php

namespace App\Enums;

/**
 * The pieces of the home page that the panel lets the administrator rearrange.
 *
 * Each piece is a case here, and the container page reads the list of tabs from
 * this enum: the tab bar, the URL values and the panel each child mounts are all
 * driven by it, so a new piece of the home page only needs its own case to be
 * reached through the bar.
 */
enum HomePageTab: string
{
    case Categorias = 'categorias';
    case MasNuevo = 'mas-nuevo';

    public function label(): string
    {
        return match ($this) {
            self::Categorias => 'Categorías',
            self::MasNuevo => 'Lo más nuevo',
        };
    }

    /**
     * The one-line purpose of the tab, for the sentence under the title of the page.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Categorias => 'Define qué foto muestra cada categoría en la portada.',
            self::MasNuevo => 'Elige a mano qué productos muestra el inicio en las novedades y en qué orden.',
        };
    }
}
