<?php

namespace App\Domain\Assistant\Import;

use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Reads and writes spreadsheets with openspout/openspout (pure PHP, only the
 * zip and xml extensions: fine on shared hosting). Supports .xlsx and .csv;
 * the old binary .xls is not readable by the library, so uploads refuse it.
 *
 * - Every cell comes back as trimmed text (dates `YYYY-MM-DD`, whole
 *   numbers without decimals, rich text joined).
 * - Row numbers are the Excel ones: empty rows are kept while reading.
 * - CSV: `;` or `,` (whichever the first line uses more), UTF-8 with or
 *   without BOM, otherwise Windows-1252 (Excel "CSV" on Spanish Windows).
 *   Its only sheet is named after the file.
 */
final class Spreadsheet
{
    public const EXTENSIONS = ['xlsx', 'csv'];

    /**
     * Name, first two rows and count of non-empty rows of every sheet.
     *
     * @return list<array{name: string, first: list<string>, second: list<string>, count: int}>
     *
     * @throws UnreadableSpreadsheet
     */
    public function sheets(string $path, string $extension, string $csvName = 'CSV'): array
    {
        $sheets = [];

        $this->read($path, $extension, function (ReaderInterface $reader) use (&$sheets, $extension, $csvName): void {
            foreach ($reader->getSheetIterator() as $sheet) {
                $first = null;
                $second = null;
                $count = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = self::cells($row);
                    if (self::isBlank($cells)) {
                        continue;
                    }
                    $count++;
                    if ($first === null) {
                        $first = $cells;
                    } elseif ($second === null) {
                        $second = $cells;
                    }
                }

                $sheets[] = [
                    'name' => $extension === 'csv' ? $csvName : $sheet->getName(),
                    'first' => self::trimEnd($first ?? []),
                    'second' => self::trimEnd($second ?? []),
                    'count' => $count,
                ];
            }
        });

        return $sheets;
    }

    /**
     * Rows of one sheet: Excel row number => cells (blank rows skipped).
     *
     * @return Generator<int, list<string>>
     *
     * @throws UnreadableSpreadsheet
     */
    public function rows(string $path, string $extension, string $sheetName): Generator
    {
        $reader = $this->open($path, $extension);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($extension !== 'csv' && $sheet->getName() !== $sheetName) {
                    continue;
                }

                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = self::cells($row);
                    if (! self::isBlank($cells)) {
                        yield $number => $cells;
                    }
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Writes an .xlsx: sheet name => rows of scalar values.
     *
     * @param  array<string, list<list<string|int|float|null>>>  $sheets
     */
    public static function write(string $path, array $sheets): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $writer->getCurrentSheet()->setName(mb_substr($name, 0, 31));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $first = false;
        }

        $writer->close();
    }

    /**
     * Column letter of a zero-based index (0 => A, 26 => AA).
     */
    public static function column(int $index): string
    {
        $letters = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + (($n - 1) % 26)).$letters;
        }

        return $letters;
    }

    /**
     * @param  callable(ReaderInterface): void  $callback
     *
     * @throws UnreadableSpreadsheet
     */
    private function read(string $path, string $extension, callable $callback): void
    {
        $reader = $this->open($path, $extension);

        try {
            $callback($reader);
        } catch (Throwable $exception) {
            throw new UnreadableSpreadsheet($exception->getMessage(), 0, $exception);
        } finally {
            $reader->close();
        }
    }

    /**
     * @throws UnreadableSpreadsheet
     */
    private function open(string $path, string $extension): ReaderInterface
    {
        $reader = match ($extension) {
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            'csv' => new CsvReader(new CsvOptions(
                SHOULD_PRESERVE_EMPTY_ROWS: true,
                FIELD_DELIMITER: self::delimiter($path),
                ENCODING: self::encoding($path),
            )),
            default => throw new UnreadableSpreadsheet("Unsupported extension {$extension}"),
        };

        try {
            $reader->open($path);
        } catch (Throwable $exception) {
            throw new UnreadableSpreadsheet($exception->getMessage(), 0, $exception);
        }

        return $reader;
    }

    private static function delimiter(string $path): string
    {
        $handle = @fopen($path, 'r');
        $line = $handle !== false ? (string) fgets($handle) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private static function encoding(string $path): string
    {
        $sample = (string) @file_get_contents($path, false, null, 0, 65536);
        // A cut multibyte character at the end of the sample is not an error.
        $sample = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $sample);

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    /**
     * @return list<string>
     */
    private static function cells(Row $row): array
    {
        $cells = [];
        $values = $row->toArray();
        $width = $values === [] ? 0 : max(array_keys($values)) + 1;

        for ($index = 0; $index < $width; $index++) {
            $cells[] = self::text($values[$index] ?? null);
        }

        return $cells;
    }

    private static function text(mixed $value): string
    {
        return trim(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Sí' : 'No',
            is_int($value) => (string) $value,
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value,
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i'),
            $value instanceof DateInterval => $value->format('%H:%I'),
            is_array($value) => implode('', array_map(fn ($run) => $run instanceof TextRun ? $run->text : '', $value)),
            default => (string) $value,
        });
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private static function trimEnd(array $cells): array
    {
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return array_values($cells);
    }
}
