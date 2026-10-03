<?php

namespace App\Enums;

/**
 * The pieces of a product that the panel edits together.
 *
 * They live in one screen because they are one catalog: a size only means something
 * inside a category, a color is what a SKU is told apart by and a material is what a
 * garment is made of, so the four lists are read, written and reordered side by side
 * instead of spread over four different places in the admin.
 *
 * The value is part of the URL (`?tab=colores`), so a tab can be shared, bookmarked or
 * reached with the back button, and it is reset to `categorias` whenever it holds a
 * value this enum does not know.
 */
enum ProductDetailsTab: string
{
    case Categorias = 'categorias';
    case Tallas = 'tallas';
    case Colores = 'colores';
    case Materiales = 'materiales';

    public function label(): string
    {
        return match ($this) {
            self::Categorias => 'Categorías',
            self::Tallas => 'Tallas',
            self::Colores => 'Colores',
            self::Materiales => 'Materiales',
        };
    }

    /**
     * The one-line purpose of the tab, for the sentence under the title of the page.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Categorias => 'Organiza el catálogo por secciones y define el prefijo de cada categoría.',
            self::Tallas => 'Define qué tallas se venden en cada categoría y en qué orden.',
            self::Colores => 'Los colores con su código, su hexadecimal y las fotos que los ilustran.',
            self::Materiales => 'Las prendas que puede usar el catálogo y sus composiciones.',
        };
    }
}
