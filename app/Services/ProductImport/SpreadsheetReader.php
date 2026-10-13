<?php

namespace App\Services\ProductImport;

use App\Exceptions\ImportFileEmptyException;
use App\Exceptions\ImportFileTooLargeException;
use App\Exceptions\ImportRowLimitExceededException;
use App\Exceptions\UnsupportedImportFormatException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Helper\EncodingHelper;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * The rows of a file, read the way the file says they are written.
 *
 * The import does not know whether the admin is sending a CSV or an Excel file
 * until it opens one, and the two are read differently: an XLSX is a zip with a
 * sheet inside it, while a CSV has no metadata at all and the encoding and the
 * separator have to be worked out from the bytes themselves. That working out
 * lives here, so the validator only ever sees rows of values and a header.
 *
 * **On the CSV.** A file exported from Excel in Spanish is usually
 * semicolon-separated and written in Windows-1252, and one exported from a text
 * editor is comma-separated and UTF-8. Both are read here: the encoding is the
 * BOM when there is one and, when there is not, whether the sample is valid
 * UTF-8; the separator is whichever of the three candidates cuts the header into
 * the most fields. The empty rows are preserved so that the number of a row in
 * the file is the number of the line that has to be fixed.
 *
 * The file is read whole — within the size and row limits — because the preview
 * describes the whole file at once: what is bounded is the file, not the reading.
 */
final class SpreadsheetReader
{
    /** How many bytes are enough to see the BOM and the shape of the header. */
    private const SAMPLE_BYTES = 8192;

    /**
     * @param  list<string>  $allowedExtensions
     */
    public function __construct(
        private string $path,
        private int $maxFileSize,
        private int $maxRows,
        private array $allowedExtensions = ['csv', 'xlsx'],
    ) {}

    /**
     * Read the first sheet of the file into a header row and the rows that follow.
     *
     * A row is the line it sits on — the empty ones among them, so the number
     * tells the admin which line of the file to open — and holds the values of
     * its cells by the index of the column they are in.
     *
     * @return array{
     *     headers: array<int, string>,
     *     rows: list<array{row: int, values: array<int, int|float|string|null>}>
     * }
     */
    public function read(): array
    {
        $extension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));

        $this->guardFormatIsReadable($extension);
        $this->guardFileIsLightEnough();

        $reader = $this->readerFor($extension);

        $headers = null;
        $rows = [];

        $reader->open($this->path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    if ($row->isEmpty()) {
                        continue;
                    }

                    $values = $this->valuesOf($row);

                    if ($headers === null) {
                        $headers = $this->headersOf($values);

                        continue;
                    }

                    $this->guardRowCountIsAllowed(count($rows) + 1);

                    $rows[] = ['row' => (int) $rowNumber, 'values' => $values];
                }

                // Only the first sheet is read: the import describes one file of
                // products, and a second sheet is a different list, not more rows.
                break;
            }
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw ImportFileEmptyException::withoutHeaders();
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function guardFormatIsReadable(string $extension): void
    {
        if (! in_array($extension, $this->allowedExtensions, true)) {
            throw UnsupportedImportFormatException::forExtension($extension, $this->allowedExtensions);
        }
    }

    private function guardFileIsLightEnough(): void
    {
        $bytes = filesize($this->path);

        if ($bytes !== false && $bytes > $this->maxFileSize) {
            throw ImportFileTooLargeException::forSize($bytes, intdiv($this->maxFileSize, 1024));
        }
    }

    private function guardRowCountIsAllowed(int $rows): void
    {
        if ($rows > $this->maxRows) {
            throw ImportRowLimitExceededException::forRows($this->maxRows);
        }
    }

    /**
     * The reader that knows how to open this extension.
     */
    private function readerFor(string $extension): ReaderInterface
    {
        if ($extension === 'xlsx') {
            return new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        }

        [$delimiter, $encoding] = $this->detectCsvFormat();

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: $delimiter,
            ENCODING: $encoding,
        ));
    }

    /**
     * The encoding and the separator a CSV is written with, read from its first bytes.
     *
     * @return array{0: string, 1: string}
     */
    private function detectCsvFormat(): array
    {
        $sample = (string) file_get_contents($this->path, false, null, 0, self::SAMPLE_BYTES);

        $encoding = str_starts_with($sample, EncodingHelper::BOM_UTF8) || mb_check_encoding($sample, 'UTF-8')
            ? EncodingHelper::ENCODING_UTF8
            : 'Windows-1252';

        return [$this->detectDelimiter($sample), $encoding];
    }

    /**
     * The separator that cuts the header into the most fields.
     *
     * Only the header line is looked at, because it is the line that names every
     * column and therefore the one with a separator between each pair of them.
     * A comma wins a tie, which is the separator a file with no others is assumed
     * to have.
     */
    private function detectDelimiter(string $sample): string
    {
        $header = preg_split('/\r\n|\n|\r/', $sample, 2)[0] ?? $sample;

        $delimiter = ',';
        $most = $this->countOutsideQuotes($header, $delimiter);

        foreach ([';', "\t"] as $candidate) {
            $count = $this->countOutsideQuotes($header, $candidate);

            if ($count > $most) {
                $most = $count;
                $delimiter = $candidate;
            }
        }

        return $delimiter;
    }

    /**
     * How many times a character appears outside the parts of the line enclosed in quotes.
     *
     * A separator inside a quoted field is part of the value and not a separator,
     * which is what keeps a material list written as a sentence from being read
     * as a row of its own.
     */
    private function countOutsideQuotes(string $line, string $character): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $current = $line[$index];

            if ($current === '"') {
                if ($inQuotes && ($line[$index + 1] ?? '') === '"') {
                    $index++;

                    continue;
                }

                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $current === $character) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The value of every cell of a row, by the index of its column.
     *
     * @return array<int, int|float|string|null>
     */
    private function valuesOf(Row $row): array
    {
        $values = [];

        foreach ($row->cells as $index => $cell) {
            $values[$index] = $this->valueOf($cell);
        }

        ksort($values);

        return $values;
    }

    /**
     * The one value a cell holds, reduced to the three things the import reads.
     *
     * A formula is read as what it computes when Excel left the result in the
     * file, and as its text when it did not: a price written as a formula is
     * still a number the admin meant, and reading the formula as text would
     * refuse a file that Excel itself shows as correct.
     */
    private function valueOf(?Cell $cell): int|float|string|null
    {
        if ($cell === null || $cell instanceof EmptyCell) {
            return null;
        }

        if ($cell instanceof FormulaCell) {
            $computed = $cell->getComputedValue();

            return is_scalar($computed) ? $computed : $cell->getValue();
        }

        $value = $cell->getValue();

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }

    /**
     * The header of the file, with every name read as the text it is.
     *
     * @param  array<int, int|float|string|null>  $values
     * @return array<int, string>
     */
    private function headersOf(array $values): array
    {
        $headers = [];

        foreach ($values as $index => $value) {
            $headers[$index] = $value === null ? '' : (string) $value;
        }

        return $headers;
    }
}
