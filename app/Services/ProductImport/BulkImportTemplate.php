<?php

namespace App\Services\ProductImport;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Size;
use Illuminate\Database\Eloquent\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The spreadsheet an admin fills in for a bulk load, offered as a download.
 *
 * The file the importer expects is not something anyone can guess: the columns have
 * to be named exactly, the composition has a shape, and the sizes, colors and
 * materials have to be the ones this store already has. This builds that file from
 * the catalog the store is holding, so the admin starts from a document that would
 * already be accepted and only has to change the values.
 *
 * **Two sheets.** `Plantilla` is the file itself: the header and two example rows,
 * one per variant of the same garment, so the "one row is one variant, rows sharing
 * a name are one article" rule is shown rather than explained. `Valores válidos` is
 * the reference: the categories of each gender with their active sizes, and the
 * active colors and materials, named as the importer resolves them.
 *
 * **Built from the live catalog.** The categories come per gender the way the
 * product form lists them, which includes the turned-off ones — a category that is
 * off is still there to receive a load. The sizes, colors and materials are the
 * active ones, because those are the only ones a new variant can be written with.
 * When the store has nothing to show, the examples fall back to text clearly marked
 * as a placeholder instead of breaking.
 *
 * **The composition is written name first.** `ProductDetailsCatalog` reads a share
 * as the last word of a segment (`Algodón 80%`), so the example writes the material
 * before its percentage; a segment written the other way round would be read as a
 * material named after the number.
 */
final class BulkImportTemplate
{
    /**
     * The name the browser is told the download has.
     */
    public const FILENAME = 'plantilla-carga-masiva.xlsx';

    /**
     * The sheet the admin fills in.
     */
    private const SHEET_LINES = 'Plantilla';

    /**
     * The sheet that lists what the catalog accepts.
     */
    private const SHEET_VALUES = 'Valores válidos';

    /**
     * The columns of the file, in the order they are written.
     *
     * @var list<string>
     */
    private const COLUMNS = ['genero', 'categoria', 'nombre', 'marca', 'precio', 'materiales', 'talla', 'color', 'stock'];

    /**
     * What a cell says when there is no real value to offer.
     */
    private const PLACEHOLDER = 'EJEMPLO';

    /**
     * The whole workbook as bytes, ready to be sent as a download.
     *
     * OpenSpout writes to a path and not to a string, so it writes to a temporary
     * file that is read back and removed. The file is never left behind, and the
     * bytes are the whole workbook, ready to travel the way `streamDownload()` sends
     * them.
     */
    public function xlsxContents(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plantilla-carga-masiva-');

        if ($path === false) {
            throw new RuntimeException('No se pudo preparar la plantilla de la carga masiva.');
        }

        $writer = new Writer;
        $writer->openToFile($path);

        $this->writeLinesSheet($writer);
        $this->writeValuesSheet($writer);

        $writer->close();

        $contents = file_get_contents($path);
        @unlink($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * The sheet the admin fills in: the header and two example rows.
     */
    private function writeLinesSheet(Writer $writer): void
    {
        $writer->getCurrentSheet()->setName(self::SHEET_LINES);

        $writer->addRow(Row::fromValues(self::COLUMNS));

        foreach ($this->exampleRows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }

    /**
     * The sheet that lists the categories per gender and the active values.
     */
    private function writeValuesSheet(Writer $writer): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName(self::SHEET_VALUES);

        $writer->addRow(Row::fromValues(['Género', 'Categoría', 'Tallas activas']));

        foreach (StoreSection::cases() as $section) {
            $this->writeSectionRows($writer, $section);
        }

        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues([
            'Colores activos',
            Color::listedActive()->pluck('name')->implode(', '),
        ]));

        $writer->addRow(Row::fromValues([
            'Materiales activos',
            Material::listedActive()->pluck('name')->implode(', '),
        ]));
    }

    /**
     * The rows of one gender: one per category, with its active sizes.
     *
     * The categories are read the way the product form reads them — all of them,
     * turned off included, in catalog order — so the template and the form never
     * disagree about which categories a gender has.
     */
    private function writeSectionRows(Writer $writer, StoreSection $section): void
    {
        $categories = Category::query()
            ->inSection($section)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            $writer->addRow(Row::fromValues([$section->label(), '(sin categorías)', '']));

            return;
        }

        foreach ($categories as $category) {
            $sizes = Size::listedActiveForCategory((int) $category->getKey())
                ->pluck('name')
                ->implode(', ');

            $writer->addRow(Row::fromValues([$section->label(), $category->name, $sizes]));
        }
    }

    /**
     * Two example rows written with the catalog the store is holding now.
     *
     * @return list<list<string>>
     */
    private function exampleRows(): array
    {
        [$section, $category] = $this->firstSectionWithCategory();

        if ($section instanceof StoreSection && $category instanceof Category) {
            $sizes = Size::listedActiveForCategory((int) $category->getKey());
            $colors = Color::listedActive();

            if ($sizes->isNotEmpty() && $colors->isNotEmpty()) {
                return $this->realisticRows($section, $category, $sizes, $colors, Material::listedActive());
            }
        }

        return $this->placeholderRows();
    }

    /**
     * The first gender the store has a category for, and that category.
     *
     * @return array{0: StoreSection|null, 1: Category|null}
     */
    private function firstSectionWithCategory(): array
    {
        foreach (StoreSection::cases() as $section) {
            $category = Category::query()
                ->inSection($section)
                ->orderBy('order')
                ->orderBy('id')
                ->first();

            if ($category instanceof Category) {
                return [$section, $category];
            }
        }

        return [null, null];
    }

    /**
     * Two variants of one garment, written with the real sizes and colors.
     *
     * The second row differs from the first in its size, or in its color when the
     * category sells only one size: two rows sharing both would be the same variant
     * twice, which the importer refuses. When neither differs, the two rows are two
     * different articles instead.
     *
     * @param  Collection<int, Size>  $sizes
     * @param  Collection<int, Color>  $colors
     * @param  Collection<int, Material>  $materials
     * @return list<list<string>>
     */
    private function realisticRows(
        StoreSection $section,
        Category $category,
        Collection $sizes,
        Collection $colors,
        Collection $materials,
    ): array {
        $sizes = $sizes->values();
        $colors = $colors->values();

        $base = [
            'genero' => $section->label(),
            'categoria' => $category->name,
            'nombre' => 'Camiseta de ejemplo',
            'marca' => 'Feigler',
            'precio' => '85000',
            'materiales' => $this->compositionExample($materials),
        ];

        $firstSize = (string) $sizes->get(0)?->name;
        $firstColor = (string) $colors->get(0)?->name;

        if ($sizes->get(1) !== null) {
            return [
                $this->asCells(array_merge($base, ['talla' => $firstSize, 'color' => $firstColor, 'stock' => '5'])),
                $this->asCells(array_merge($base, ['talla' => (string) $sizes->get(1)?->name, 'color' => $firstColor, 'stock' => '3'])),
            ];
        }

        if ($colors->get(1) !== null) {
            return [
                $this->asCells(array_merge($base, ['talla' => $firstSize, 'color' => $firstColor, 'stock' => '5'])),
                $this->asCells(array_merge($base, ['talla' => $firstSize, 'color' => (string) $colors->get(1)?->name, 'stock' => '3'])),
            ];
        }

        return [
            $this->asCells(array_merge($base, ['nombre' => 'Camiseta de ejemplo 1', 'talla' => $firstSize, 'color' => $firstColor, 'stock' => '5'])),
            $this->asCells(array_merge($base, ['nombre' => 'Camiseta de ejemplo 2', 'talla' => $firstSize, 'color' => $firstColor, 'stock' => '3'])),
        ];
    }

    /**
     * The composition an example row carries, written the way the importer reads it.
     *
     * The share goes after the material, because that is where
     * `ProductDetailsCatalog` looks for it. One material holds the whole garment; two
     * of them split it. With none to offer, the cell is left empty, which the
     * importer reads as a garment with no composition yet.
     *
     * @param  Collection<int, Material>  $materials
     */
    private function compositionExample(Collection $materials): string
    {
        $materials = $materials->values();
        $first = $materials->get(0);
        $second = $materials->get(1);

        if (! $first instanceof Material) {
            return '';
        }

        if (! $second instanceof Material) {
            return $first->name.' 100%';
        }

        return $first->name.' 80% | '.$second->name.' 20%';
    }

    /**
     * The examples when the store has nothing to build them from.
     *
     * @return list<list<string>>
     */
    private function placeholderRows(): array
    {
        $base = [
            'genero' => StoreSection::Hombre->label(),
            'categoria' => self::PLACEHOLDER.' categoría',
            'marca' => self::PLACEHOLDER,
            'precio' => self::PLACEHOLDER,
            'materiales' => self::PLACEHOLDER.' material 100%',
            'talla' => self::PLACEHOLDER.' talla',
            'color' => self::PLACEHOLDER.' color',
            'stock' => self::PLACEHOLDER,
        ];

        return [
            $this->asCells(array_merge($base, ['nombre' => self::PLACEHOLDER.' artículo 1'])),
            $this->asCells(array_merge($base, ['nombre' => self::PLACEHOLDER.' artículo 2'])),
        ];
    }

    /**
     * One row of the file, as the cells of the columns, in order.
     *
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function asCells(array $row): array
    {
        return array_map(fn (string $column): string => $row[$column], self::COLUMNS);
    }
}
