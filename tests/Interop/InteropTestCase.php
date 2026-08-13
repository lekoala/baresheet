<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use DateTimeInterface;
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Tests\TestCase as BaseTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Shared helpers for interop tests against third-party libraries.
 */
abstract class InteropTestCase extends BaseTestCase
{
    /**
     * Read all rows of the first sheet of an XLSX/ODS file with OpenSpout.
     * Date values are normalized to 'Y-m-d H:i:s' strings.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function readOpenSpout(string $ext, string $file): array
    {
        $reader = $ext === 'ods' ? new OdsReader() : new XlsxReader();
        $reader->open($file);
        $sheetIterator = $reader->getSheetIterator();
        $sheetIterator->rewind();
        $sheet = $sheetIterator->current();
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map([$this, 'normalizeOpenSpoutValue'], $row->toArray());
        }
        $this->exhaustSheets($sheetIterator);
        $reader->close();
        return $rows;
    }

    /**
     * Write rows with OpenSpout. Cells holding a DateTimeInterface are given an
     * explicit date format style, which Baresheet's reader needs to recognize
     * them as dates instead of plain numbers.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    protected function writeOpenSpout(string $ext, string $file, array $rows, ?string $sheetName = null): void
    {
        $writer = $ext === 'ods' ? new OdsWriter() : new XlsxWriter();
        $writer->openToFile($file);
        if ($sheetName !== null) {
            $writer->getCurrentSheet()->setName($sheetName);
        }
        $dateStyle = (new Style())->withFormat('yyyy-mm-dd hh:mm:ss');
        foreach ($rows as $row) {
            $styles = [];
            foreach ($row as $i => $value) {
                if ($value instanceof DateTimeInterface) {
                    $styles[$i] = $dateStyle;
                }
            }
            $writer->addRow($styles !== [] ? Row::fromValuesWithStyles($row, $styles) : Row::fromValues($row));
        }
        $writer->close();
    }

    /**
     * First sheet name of an XLSX/ODS file as seen by OpenSpout.
     */
    protected function openSpoutSheetName(string $ext, string $file): string
    {
        $reader = $ext === 'ods' ? new OdsReader() : new XlsxReader();
        $reader->open($file);
        $sheetIterator = $reader->getSheetIterator();
        $sheetIterator->rewind();
        $name = $sheetIterator->current()->getName();
        $this->exhaustSheets($sheetIterator);
        $reader->close();
        return $name;
    }

    /**
     * Drain the sheet iterator so OpenSpout releases its internal streaming
     * handles (required on Windows before the source file can be removed).
     *
     * @param \OpenSpout\Reader\SheetIteratorInterface $sheetIterator
     */
    private function exhaustSheets(\OpenSpout\Reader\SheetIteratorInterface $sheetIterator): void
    {
        while ($sheetIterator->valid()) {
            $sheetIterator->next();
        }
    }

    /**
     * Read a Baresheet file with Baresheet::read().
     *
     * @return array<int, array<mixed>>
     */
    protected function readBaresheet(string $file, ?Options $options = null): array
    {
        return iterator_to_array(Baresheet::read($file, $options));
    }

    /**
     * Normalize an OpenSpout cell value to a comparable scalar.
     */
    protected function normalizeOpenSpoutValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value;
    }

    /**
     * Normalize a Baresheet XLSX cell value to a comparable scalar.
     * Dates are already returned as formatted strings by the reader.
     */
    protected function normalizeBaresheetValue(mixed $value): mixed
    {
        return $value;
    }
}
