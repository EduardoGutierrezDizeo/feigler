<?php

namespace App\Services\ProductImport;

use App\Actions\Products\CreateProduct;
use App\Enums\StoreSection;
use App\Exceptions\ImportHeadersMissingException;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\InvalidProductPriceException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Exceptions\UnknownColorException;
use App\Exceptions\UnknownMaterialException;
use App\Exceptions\UnknownSizeException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Size;
use App\Services\CatalogSnapshot;
use App\Services\ProductDetailsCatalog;
use App\Support\Concerns\NormalizesNames;
use App\Support\PriceParser;
use Illuminate\Support\Str;

/**
 * The file the admin uploads, read into the articles it describes and the rows that cannot be saved.
 *
 * This is the reading half of the bulk load: it opens a CSV or an XLSX, checks
 * every article and every row against the same catalog the panel uses, and hands
 * back a preview. It writes nothing — the product, its composition and its
 * variants are written later, over a document this one does not build and does
 * not touch — so a file can be read, shown and corrected as many times as needed
 * before anything reaches the database.
 *
 * **What an article is.** One row is one variant, and the rows that share a
 * `género`, a `categoría` and a `nombre` are the same garment written once per
 * size and color: the price, the brand and the materials of that garment are the
 * data of the article and are read off its first row, and every other row of the
 * article having the same ones is part of being the same article. A garment with
 * one wrong row is refused whole, because half an article is not a product.
 *
 * **On the checks.** They are the checks of the panel, in the same order and
 * with the same words: a size that is not in the category, a color or a
 * material that does not exist, one that has been turned off, a composition that
 * does not add up — the messages come from the same exceptions the form shows,
 * so a file and a form never disagree about why a garment cannot be saved. What
 * is new is the price, which a file writes by hand and the panel's field writes
 * for the admin, and the name of the article, which in a file can belong to a
 * product that is already there.
 *
 * **On the cost.** A file of hundreds of rows asks the database not once per
 * row: the catalog is read once into a `CatalogSnapshot`, and the
 * `ProductDetailsCatalog` resolves the names against it. Reading five hundred
 * rows and reading five cost the same few queries.
 */
final class BulkProductImportValidator
{
    use NormalizesNames;

    /**
     * The columns a file has to name for its rows to describe a garment.
     *
     * `marca` is not here: a brand left out is the brand of the store, the same
     * blank `CreateProduct` fills.
     *
     * @var list<string>
     */
    private const REQUIRED_COLUMNS = ['genero', 'categoria', 'nombre', 'precio', 'materiales', 'talla', 'color', 'stock'];

    /**
     * The columns of an article that every row of it has to write the same way.
     *
     * The `género`, the `categoría` and the `nombre` are not here because they are
     * what tells one article from another in the first place: two rows that
     * disagree about them are two articles, not one with a mistake. The price is
     * not here because it is compared as the amount it means: two rows writing
     * `85.000` and `85000` are the same price and not a mistake.
     *
     * @var list<string>
     */
    private const ARTICLE_COLUMNS = ['marca', 'materiales'];

    /**
     * The one example of a composition the two hints share, so neither can drift
     * away from what the parser accepts.
     *
     * The parser reads the elements of a composition as the name first and the
     * percentage last — `ProductDetailsCatalog::splitShare` takes the final word of
     * an element as its share — so this is the order the example writes, and the
     * order every hint has to teach.
     */
    private const MATERIAL_EXAMPLE = '«Algodón 80% | Poliéster 20%»';

    /**
     * What a composition that was written with commas is told to write instead.
     *
     * The materials of a garment are separated by `|` or `/`, and never by a comma:
     * a comma is what a Spanish CSV closes a field with, so a cell that holds one is
     * read as a single, long material name and refused. The refusal names that name;
     * this names the separator that was meant, and only reaches a cell that wrote a
     * comma at all.
     */
    private const COMMA_HINT = 'Separa los materiales con | o / (ejemplo: '.self::MATERIAL_EXAMPLE.').';

    /**
     * What a composition that wrote the percentage before the name is told instead.
     *
     * The parser takes the last word of an element as the share and the words before
     * it as the material, so `Algodón 80%` is a material with a share and `80%
     * Algodón` is read as a material named `80` and refused. This names the order
     * that was meant, with the same example the comma hint teaches.
     */
    private const ORDER_HINT = 'Escribe primero el nombre y después el porcentaje (ejemplo: '.self::MATERIAL_EXAMPLE.').';

    /**
     * Read a file into the articles that are ready to be saved and the ones that are not.
     *
     * @return array{
     *     ready: list<array{
     *         row: int,
     *         section: string,
     *         section_label: string,
     *         category_id: int,
     *         category: string,
     *         name: string,
     *         brand: string,
     *         price: string,
     *         materials: list<array{id: int, name: string, percentage: int}>,
     *         variants: list<array{row: int, size_id: int, size: string, color_id: int, color: string, stock: int}>
     *     }>,
     *     rejected: list<array{
     *         name: string,
     *         rows: list<int>,
     *         errors: list<array{row: int, column: string, message: string}>
     *     }>,
     *     warnings: list<array{section_label: string, category: string, articles: int}>,
     *     totals: array{ready_articles: int, ready_variants: int, rejected_articles: int, rejected_rows: int}
     * }
     */
    public function validate(string $path): array
    {
        $read = (new SpreadsheetReader(
            $path,
            $this->maxFileSizeInBytes(),
            $this->maxRows(),
            $this->allowedExtensions(),
        ))->read();

        $columns = $this->columnIndexes($read['headers']);

        $snapshot = CatalogSnapshot::load();
        $catalog = (new ProductDetailsCatalog)->withSnapshot($snapshot);

        $ready = [];
        $rejected = [];

        foreach ($this->groupByArticle($read['rows'], $columns) as $rows) {
            $article = $this->validateArticle($rows, $columns, $catalog, $snapshot);

            if ($article['ready'] !== null) {
                $ready[] = $article['ready'];

                continue;
            }

            $rejected[] = $article['rejected'];
        }

        return [
            'ready' => $ready,
            'rejected' => $rejected,
            'warnings' => $this->categoryWarnings($ready, $snapshot),
            'totals' => $this->totals($ready, $rejected),
        ];
    }

    /**
     * The categories the ready articles use that the store has turned off.
     *
     * A file is read against the whole catalog, deactivated categories included,
     * the same way the panel's form offers them, so the article is still created.
     * What this warns about is that it will be written into a category whose
     * articles the storefront does not show, and stays hidden until the category
     * is turned back on. One entry per category and not per article, so a warning
     * about a long file stays as small as a warning about a short one.
     *
     * @param  list<array<string, mixed>>  $ready
     * @return list<array{section_label: string, category: string, articles: int}>
     */
    private function categoryWarnings(array $ready, CatalogSnapshot $snapshot): array
    {
        $warnings = [];

        foreach ($ready as $article) {
            $categoryId = (int) $article['category_id'];
            $category = $snapshot->categoryById($categoryId);

            if (! $category instanceof Category || $category->is_active) {
                continue;
            }

            $warnings[$categoryId] ??= [
                'section_label' => $article['section_label'],
                'category' => $article['category'],
                'articles' => 0,
            ];

            $warnings[$categoryId]['articles']++;
        }

        return array_values($warnings);
    }

    /**
     * The articles the rows describe, each one with its rows, keyed by how it was written.
     *
     * The key is the written `género`, `categoría` and `nombre`, and not the
     * category and the product they end up being: a row whose category is a typo
     * has to land with the rest of its article so the article is refused whole
     * with one message, and that is only possible while the name is still what
     * was typed. A row with no name cannot belong to an article at all, so it is
     * its own, refused on its own.
     *
     * @param  list<array{row: int, values: array<int, int|float|string|null>}>  $rows
     * @param  array<string, int>  $columns
     * @return array<string, list<array{row: int, values: array<int, int|float|string|null>}>>
     */
    private function groupByArticle(array $rows, array $columns): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $name = $this->nameKey($this->cleanName($this->text($row['values'][$columns['nombre']] ?? null)));

            $groups[$name === ''
                ? 'row:'.$row['row']
                : $this->writtenIdentity($row['values'], $columns)][] = $row;
        }

        return $groups;
    }

    /**
     * The article of a group of rows, or the reasons it cannot be saved.
     *
     * @param  list<array{row: int, values: array<int, int|float|string|null>}>  $rows
     * @param  array<string, int>  $columns
     * @return array{
     *     ready: array<string, mixed>|null,
     *     rejected: array<string, mixed>|null
     * }
     */
    private function validateArticle(array $rows, array $columns, ProductDetailsCatalog $catalog, CatalogSnapshot $snapshot): array
    {
        $reference = $rows[0];
        $referenceRow = $reference['row'];
        $referenceValues = $reference['values'];

        $name = $this->text($referenceValues[$columns['nombre']] ?? null);

        $errors = $this->consistencyErrors($rows, $columns);

        $section = $this->resolveSection($this->text($referenceValues[$columns['genero']] ?? null), $referenceRow, $errors);
        $category = $this->resolveCategory($snapshot, $section, $this->text($referenceValues[$columns['categoria']] ?? null), $referenceRow, $errors);

        $this->guardName($name, $referenceRow, $errors);
        $brand = $this->brand($this->valueOf($referenceValues, $columns, 'marca'), $referenceRow, $errors);
        $price = $this->price($rows, $columns, $referenceRow, $errors);
        $materials = $this->materials($this->text($referenceValues[$columns['materiales']] ?? null), $catalog, $snapshot, $referenceRow, $errors);

        $variants = $category instanceof Category
            ? $this->variants($rows, $columns, $catalog, $category, $errors)
            : [];

        $this->guardNameIsAvailable($snapshot, $category, $name, $referenceRow, $errors);

        if ($errors !== []) {
            return [
                'ready' => null,
                'rejected' => [
                    'name' => $name === '' ? '(sin nombre)' : $name,
                    'rows' => array_map(fn (array $row): int => $row['row'], $rows),
                    'errors' => $errors,
                ],
            ];
        }

        return [
            'ready' => [
                'row' => $referenceRow,
                'section' => $section->value,
                'section_label' => $section->label(),
                'category_id' => (int) $category->getKey(),
                'category' => $category->name,
                'name' => $name,
                'brand' => $brand,
                'price' => $price,
                'materials' => $materials,
                'variants' => $variants,
            ],
            'rejected' => null,
        ];
    }

    /**
     * The errors of a row that does not describe the same article as the first one.
     *
     * @param  list<array{row: int, values: array<int, int|float|string|null>}>  $rows
     * @param  array<string, int>  $columns
     * @return list<array{row: int, column: string, message: string}>
     */
    private function consistencyErrors(array $rows, array $columns): array
    {
        $reference = $rows[0];
        $referenceRow = $reference['row'];
        $errors = [];

        foreach (array_slice($rows, 1) as $row) {
            foreach (self::ARTICLE_COLUMNS as $column) {
                $expected = $this->comparisonValue($column, $this->valueOf($reference['values'], $columns, $column));
                $written = $this->comparisonValue($column, $this->valueOf($row['values'], $columns, $column));

                if ($expected !== $written) {
                    $errors[] = $this->error(
                        $row['row'],
                        $column,
                        "Este dato no coincide con la primera fila de la misma prenda (fila {$referenceRow}).",
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * A value as it is compared between the rows of one article.
     *
     * The brand is compared with the blank already filled, so a row that leaves it
     * out and one that writes the brand of the store say the same thing; the
     * materials are compared by name, ignoring case and accents.
     */
    private function comparisonValue(string $column, mixed $value): string
    {
        if ($column === 'marca') {
            return $this->text($value) ?: CreateProduct::DEFAULT_BRAND;
        }

        return $this->nameKey($this->text($value));
    }

    /**
     * The section a written gender belongs to.
     */
    private function resolveSection(string $written, int $row, array &$errors): ?StoreSection
    {
        if ($written === '') {
            $errors[] = $this->error($row, 'genero', 'El género es obligatorio.');

            return null;
        }

        $key = $this->nameKey($written);

        foreach (StoreSection::cases() as $section) {
            if ($key === $section->value || $key === $this->nameKey($section->label())) {
                return $section;
            }
        }

        $errors[] = $this->error($row, 'genero', "El género «{$written}» no es válido. Usa Hombre, Mujer o Niños.");

        return null;
    }

    /**
     * The category a written name belongs to inside a section.
     */
    private function resolveCategory(CatalogSnapshot $snapshot, ?StoreSection $section, string $written, int $row, array &$errors): ?Category
    {
        if (! $section instanceof StoreSection) {
            return null;
        }

        if ($written === '') {
            $errors[] = $this->error($row, 'categoria', 'La categoría es obligatoria.');

            return null;
        }

        $category = $snapshot->categoryFor($section, $written);

        if (! $category instanceof Category) {
            $errors[] = $this->error($row, 'categoria', "La categoría «{$written}» no existe en el género «{$section->label()}».");
        }

        return $category;
    }

    /**
     * Refuse a name that cannot become a product of the store.
     */
    private function guardName(string $name, int $row, array &$errors): void
    {
        if ($name === '') {
            $errors[] = $this->error($row, 'nombre', 'El nombre es obligatorio.');

            return;
        }

        if (mb_strlen($name) > 255) {
            $errors[] = $this->error($row, 'nombre', 'El nombre no puede superar los 255 caracteres.');

            return;
        }

        if (Str::slug($name) === '') {
            $errors[] = $this->error($row, 'nombre', 'El nombre debe tener al menos una letra o un número.');
        }
    }

    /**
     * The brand of the article, which is the brand of the store when it is left blank.
     */
    private function brand(mixed $value, int $row, array &$errors): string
    {
        $brand = $this->text($value);

        if ($brand === '') {
            return CreateProduct::DEFAULT_BRAND;
        }

        if (mb_strlen($brand) > 100) {
            $errors[] = $this->error($row, 'marca', 'La marca no puede superar los 100 caracteres.');
        }

        return $brand;
    }

    /**
     * The amount the article sells for, the same one in every row of it.
     */
    private function price(array $rows, array $columns, int $referenceRow, array &$errors): ?string
    {
        $amounts = [];

        foreach ($rows as $row) {
            try {
                $amounts[] = PriceParser::parse($row['values'][$columns['precio']] ?? null);
            } catch (InvalidProductPriceException $exception) {
                $errors[] = $this->error($row['row'], 'precio', $exception->getMessage());
            }
        }

        $amounts = array_values(array_unique($amounts));

        if (count($amounts) > 1) {
            $errors[] = $this->error($referenceRow, 'precio', 'El precio no coincide entre las filas del mismo artículo.');
        }

        return $amounts[0] ?? null;
    }

    /**
     * The composition of the article, read the way the panel reads it.
     *
     * The text is split by the catalog and then judged here by the same rules the
     * panel's action judges it by, reusing the same exceptions: a share that is
     * not a whole percent, a material listed twice, a list that does not add up
     * to the whole garment. The hundred is checked over the shares that were
     * valid, so a list with a wrong one is not also accused of not adding up.
     *
     * @return list<array{id: int, name: string, percentage: int}>
     */
    private function materials(string $text, ProductDetailsCatalog $catalog, CatalogSnapshot $snapshot, int $row, array &$errors): array
    {
        if ($text === '') {
            return [];
        }

        try {
            $lines = $catalog->resolveComposition($text);
        } catch (UnknownMaterialException|InactiveProductMaterialException $exception) {
            $this->materialError($row, $text, $exception->getMessage(), $errors);

            return [];
        }

        $composition = [];
        $seen = [];
        $total = 0;
        $compositionIsSound = true;

        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $material = $snapshot->materialById($id);

            if (! $material instanceof Material) {
                $this->materialError($row, $text, "El material {$id} no existe.", $errors);
                $compositionIsSound = false;

                continue;
            }

            if (isset($seen[$id])) {
                $this->materialError($row, $text, RepeatedProductMaterialException::forMaterial($material)->getMessage(), $errors);
                $compositionIsSound = false;

                continue;
            }

            $seen[$id] = true;

            $percentage = filter_var($line['percentage'], FILTER_VALIDATE_INT);

            if ($percentage === false || $percentage < 1 || $percentage > 100) {
                $this->materialError($row, $text, InvalidMaterialPercentageException::forPercentage($material, $line['percentage'])->getMessage(), $errors);
                $compositionIsSound = false;

                continue;
            }

            $total += $percentage;
            $composition[] = ['id' => $id, 'name' => $material->name, 'percentage' => $percentage];
        }

        if ($compositionIsSound && $composition !== [] && $total !== 100) {
            $this->materialError($row, $text, IncompleteMaterialCompositionException::forTotal($total)->getMessage(), $errors);
        }

        return $composition;
    }

    /**
     * One error of the composition column, with the hint the text deserves.
     *
     * A cell that holds a comma was written with the separator a Spanish CSV uses to
     * close a field and not the one a composition uses between its materials, so it is
     * told to write them with `|` or `/`. A cell that wrote the percentage first is
     * telling the truth about a composition and in the wrong order, so it is told the
     * order the parser reads. Both hints carry the same example, so whichever one a
     * person gets, the example they copy is one the parser accepts.
     *
     * @param  list<array{row: int, column: string, message: string}>  $errors
     */
    private function materialError(int $row, string $text, string $message, array &$errors): void
    {
        if (str_contains($text, ',')) {
            $message .= ' '.self::COMMA_HINT;
        } elseif ($this->looksPercentageFirst($text)) {
            $message .= ' '.self::ORDER_HINT;
        }

        $errors[] = $this->error($row, 'materiales', $message);
    }

    /**
     * Whether an element of the composition wrote its percentage before its material.
     *
     * The parser reads the last word of an element as the share, so an element with
     * more than one word whose first word is a number is exactly the order that gets
     * read backwards and refused as a material named after the number. A material
     * whose own name starts with a number would be read the same way, which is why
     * the parser cannot accept it either.
     */
    private function looksPercentageFirst(string $text): bool
    {
        $elements = preg_split('/\s*[|\/]\s*/u', str_replace('%', '', $text)) ?: [];

        foreach ($elements as $element) {
            $words = preg_split('/\s+/u', $this->cleanName($element)) ?: [];

            if (count($words) > 1 && preg_match('/^\d+(?:[.,]\d+)?$/', $words[0]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variants of the article, one per row that resolves to a size and a color.
     *
     * @param  list<array{row: int, values: array<int, int|float|string|null>}>  $rows
     * @param  array<string, int>  $columns
     * @return list<array{row: int, size_id: int, size: string, color_id: int, color: string, stock: int}>
     */
    private function variants(array $rows, array $columns, ProductDetailsCatalog $catalog, Category $category, array &$errors): array
    {
        $variants = [];
        $seen = [];

        foreach ($rows as $row) {
            $rowNumber = $row['row'];

            $size = $this->resolveSize($catalog, $category, $this->text($row['values'][$columns['talla']] ?? null), $rowNumber, $errors);
            $color = $this->resolveColor($catalog, $this->text($row['values'][$columns['color']] ?? null), $rowNumber, $errors);
            $stock = $this->stock($row['values'][$columns['stock']] ?? null, $rowNumber, $errors);

            if (! $size instanceof Size || ! $color instanceof Color || $stock === null) {
                continue;
            }

            $combination = $size->getKey().'|'.$color->getKey();

            if (isset($seen[$combination])) {
                $errors[] = $this->error($rowNumber, 'color', "La talla «{$size->name}» con el color «{$color->name}» está repetida en este artículo.");

                continue;
            }

            $seen[$combination] = true;

            $variants[] = [
                'row' => $rowNumber,
                'size_id' => (int) $size->getKey(),
                'size' => $size->name,
                'color_id' => (int) $color->getKey(),
                'color' => $color->name,
                'stock' => $stock,
            ];
        }

        return $variants;
    }

    private function resolveSize(ProductDetailsCatalog $catalog, Category $category, string $written, int $row, array &$errors): ?Size
    {
        try {
            return $catalog->resolveSize($category, $written);
        } catch (UnknownSizeException|InactiveVariantSizeException $exception) {
            $errors[] = $this->error($row, 'talla', $exception->getMessage());
        }

        return null;
    }

    private function resolveColor(ProductDetailsCatalog $catalog, string $written, int $row, array &$errors): ?Color
    {
        try {
            return $catalog->resolveColor($written);
        } catch (UnknownColorException|InactiveVariantColorException $exception) {
            $errors[] = $this->error($row, 'color', $exception->getMessage());
        }

        return null;
    }

    /**
     * The stock of a row: a whole number of units, never negative.
     */
    private function stock(mixed $value, int $row, array &$errors): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $errors[] = $this->error($row, 'stock', 'El stock es obligatorio.');

            return null;
        }

        $whole = filter_var($value, FILTER_VALIDATE_INT);

        if ($whole === false || $whole < 0) {
            $errors[] = $this->error($row, 'stock', 'El stock debe ser un número entero igual o mayor que cero.');

            return null;
        }

        return $whole;
    }

    /**
     * Refuse a name that a product of the same category already uses.
     *
     * The check needs the category, and a row whose category could not be
     * resolved has already been refused: there is nothing to name. Two rows of
     * the file asking for the same name are the same article already — the
     * grouping made sure of that — so only the products that are already saved
     * have to be looked up.
     */
    private function guardNameIsAvailable(CatalogSnapshot $snapshot, ?Category $category, string $name, int $row, array &$errors): void
    {
        if (! $category instanceof Category || $name === '') {
            return;
        }

        if ($snapshot->productNameExists((int) $category->getKey(), $name)) {
            $errors[] = $this->error($row, 'nombre', "Ya existe un producto llamado «{$name}» en la categoría «{$category->name}».");
        }
    }

    /**
     * The column each required name sits in, keyed by the name normalized.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    private function columnIndexes(array $headers): array
    {
        $indexes = [];

        foreach ($headers as $index => $header) {
            $column = $this->nameKey($this->cleanName($header));

            if ($column !== '' && ! isset($indexes[$column])) {
                $indexes[$column] = $index;
            }
        }

        $missing = array_values(array_filter(
            self::REQUIRED_COLUMNS,
            fn (string $column): bool => ! isset($indexes[$column]),
        ));

        if ($missing !== []) {
            throw ImportHeadersMissingException::forHeaders($missing);
        }

        return $indexes;
    }

    /**
     * How the `género`, `categoría` and `nombre` of a row were written, as one key.
     *
     * @param  array<int, int|float|string|null>  $values
     * @param  array<string, int>  $columns
     */
    private function writtenIdentity(array $values, array $columns): string
    {
        return implode('|', [
            $this->nameKey($this->cleanName($this->text($values[$columns['genero']] ?? null))),
            $this->nameKey($this->cleanName($this->text($values[$columns['categoria']] ?? null))),
            $this->nameKey($this->cleanName($this->text($values[$columns['nombre']] ?? null))),
        ]);
    }

    /**
     * The counts the preview shows at a glance.
     *
     * @param  list<array<string, mixed>>  $ready
     * @param  list<array<string, mixed>>  $rejected
     * @return array{ready_articles: int, ready_variants: int, rejected_articles: int, rejected_rows: int}
     */
    private function totals(array $ready, array $rejected): array
    {
        return [
            'ready_articles' => count($ready),
            'ready_variants' => array_sum(array_map(fn (array $article): int => count($article['variants']), $ready)),
            'rejected_articles' => count($rejected),
            'rejected_rows' => array_sum(array_map(fn (array $article): int => count($article['rows']), $rejected)),
        ];
    }

    /**
     * The value of a cell, wherever the column it stands under is.
     *
     * A column that the file did not name has no index, and a cell under it nothing
     * to read: both come back as `null`, the value of an empty cell.
     *
     * @param  array<int, int|float|string|null>  $values
     * @param  array<string, int>  $columns
     */
    private function valueOf(array $values, array $columns, string $column): mixed
    {
        return $values[$columns[$column] ?? -1] ?? null;
    }

    /**
     * The value of a cell as the text it holds.
     */
    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? trim($value) : trim((string) $value);
    }

    /**
     * One reason a row cannot be saved, with the row and the column it belongs to.
     *
     * @return array{row: int, column: string, message: string}
     */
    private function error(int $row, string $column, string $message): array
    {
        return ['row' => $row, 'column' => $column, 'message' => $message];
    }

    private function maxFileSizeInBytes(): int
    {
        return max(1, (int) config('bulk_import.max_file_size_kb', 2048)) * 1024;
    }

    private function maxRows(): int
    {
        return max(1, (int) config('bulk_import.max_rows', 1000));
    }

    /**
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('bulk_import.allowed_extensions', ['csv', 'xlsx']);

        return $extensions;
    }
}
