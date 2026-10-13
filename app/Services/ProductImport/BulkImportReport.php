<?php

namespace App\Services\ProductImport;

/**
 * The refusals of a load, written as the CSV the admin downloads.
 *
 * The panel shows the first slice of the refusals, but a file of two thousand rows
 * can be refused in full, and the admin needs the whole list to fix it offline. This
 * turns the same `rejected` list the panel reads into a spreadsheet any program opens:
 * one row per reason, ordered by the row of the file it belongs to.
 *
 * **It is a Spanish CSV.** The separator is `;`, because a comma is what the file
 * itself uses between materials, and the text is UTF-8 with the byte order mark Excel
 * wants to read accents without asking. The header is written even when there is
 * nothing to report, so an empty download still opens as a table.
 *
 * **A cell is data, never a formula.** A name, a message or a column that starts with
 * `=`, `+`, `-`, `@` or a control character is what a spreadsheet reads as the start
 * of a formula, which is how a file turns into something that runs on the machine of
 * whoever opens it. Every cell is passed through `safe()`, which leans the value with
 * an apostrophe so the program reads it as text.
 */
final class BulkImportReport
{
    /**
     * The name the browser is told the download has.
     */
    public const FILENAME = 'errores-carga-masiva.csv';

    /**
     * The header of the report, in the order the columns are written.
     *
     * @var list<string>
     */
    private const HEADERS = ['fila', 'artículo', 'columna', 'motivo'];

    /**
     * The separator, which is the one a spreadsheet written in Spanish expects.
     */
    private const DELIMITER = ';';

    /**
     * The byte order mark Excel needs to read the file as UTF-8.
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * The first characters that make a spreadsheet read a cell as a formula.
     *
     * @var list<string>
     */
    private const FORMULA_HEADS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * The whole report as CSV text, header included, ready to be sent as a download.
     *
     * @param  list<array{
     *     name?: string,
     *     rows?: list<int>,
     *     errors?: list<array{row?: int, column?: string, message?: string}>
     * }>  $rejected
     */
    public function csv(array $rejected): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, self::HEADERS, self::DELIMITER, '"', '');

        foreach ($this->rows($rejected) as $row) {
            fputcsv($handle, $row, self::DELIMITER, '"', '');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return self::BOM.($contents === false ? '' : $contents);
    }

    /**
     * One line per reason, ordered by the row of the file it is about.
     *
     * An article refused at the moment of creating carries a single reason under the
     * `creacion` column; a row refused by the preview carries one reason per broken
     * cell. Both shapes flatten to the same four cells here.
     *
     * @param  list<array{
     *     name?: string,
     *     rows?: list<int>,
     *     errors?: list<array{row?: int, column?: string, message?: string}>
     * }>  $rejected
     * @return list<list<string>>
     */
    private function rows(array $rejected): array
    {
        $lines = [];

        foreach ($rejected as $article) {
            $name = $this->safe((string) ($article['name'] ?? ''));

            foreach ($article['errors'] ?? [] as $error) {
                $row = (int) ($error['row'] ?? 0);

                $lines[] = [
                    'row' => $row,
                    'cells' => [
                        (string) $row,
                        $name,
                        $this->safe((string) ($error['column'] ?? '')),
                        $this->safe((string) ($error['message'] ?? '')),
                    ],
                ];
            }
        }

        // El orden lo pide el propio archivo: la fila del archivo manda, y `usort`
        // es estable desde PHP 8, así que dos motivos de la misma fila y artículo
        // conservan el orden en que el validador los encontró.
        usort($lines, fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return array_map(fn (array $line): array => $line['cells'], $lines);
    }

    /**
     * A cell that a spreadsheet reads as text even when it was written like a formula.
     */
    private function safe(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_HEADS, true)) {
            return "'".$value;
        }

        return $value;
    }
}
