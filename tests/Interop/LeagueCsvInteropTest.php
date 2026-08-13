<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use League\Csv\ByteSequence;
use League\Csv\Reader as LeagueReader;
use League\Csv\Writer as LeagueWriter;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Options;

/**
 * Interop between Baresheet CSV and league/csv.
 *
 * League CSV uses the same native fgetcsv/fputcsv engine as Baresheet, so these
 * tests pin the conventions (delimiter, enclosure, escape, BOM, newlines) rather
 * than byte-for-byte equality.
 */
class LeagueCsvInteropTest extends InteropTestCase
{
    public function testLeagueReadsBaresheetCsv(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $csv = $writer->writeString([
            ['id', 'name',        'notes'],
            ['1',  'José "Pepe"', "ligne 1\nligne 2"],
            ['2',  'Doe, Jane',   ''],
            ['3',  'Müller',      null],
        ]);

        $reader = LeagueReader::createFromString($csv);
        $reader->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $reader->setHeaderOffset(0);

        $records = array_values(iterator_to_array($reader->getRecords()));

        self::assertSame(
            [
                ['id' => '1', 'name' => 'José "Pepe"', 'notes' => "ligne 1\nligne 2"],
                ['id' => '2', 'name' => 'Doe, Jane', 'notes' => ''],
                ['id' => '3', 'name' => 'Müller', 'notes' => ''],
            ],
            $records,
        );
    }

    public function testBaresheetReadsLeagueCsv(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        $writer = LeagueWriter::createFromStream($stream);
        $writer->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $writer->insertAll([
            ['id', 'name',        'notes'],
            ['1',  'José "Pepe"', "ligne 1\nligne 2"],
            ['2',  'Doe, Jane',   ''],
            ['3',  'Müller',      null],
        ]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $reader = new CsvReader();
        $options = new Options(assoc: true);
        $options->applyTo($reader);
        $records = iterator_to_array($reader->readString($csv));

        self::assertSame(
            [
                ['id' => '1', 'name' => 'José "Pepe"', 'notes' => "ligne 1\nligne 2"],
                ['id' => '2', 'name' => 'Doe, Jane', 'notes' => ''],
                ['id' => '3', 'name' => 'Müller', 'notes' => ''],
            ],
            $records,
        );
    }

    public function testLeagueReadsBaresheetCsvWithBom(): void
    {
        $writer = new CsvWriter();
        $writer->bom = true;
        $csv = $writer->writeString([
            ['id', 'name'],
            ['1', 'John'],
        ]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $reader = LeagueReader::createFromString($csv);
        $reader->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $reader->setHeaderOffset(0);

        $records = array_values(iterator_to_array($reader->getRecords()));
        self::assertSame([['id' => '1', 'name' => 'John']], $records);
    }

    public function testBaresheetReadsLeagueCsvWithBom(): void
    {
        $writer = LeagueWriter::createFromString();
        $writer->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $writer->setOutputBOM(ByteSequence::BOM_UTF8);
        $writer->insertAll([
            ['id', 'name'],
            ['1', 'John'],
        ]);
        $csv = $writer->toString();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $reader = new CsvReader();
        $options = new Options(assoc: true);
        $options->applyTo($reader);
        $records = iterator_to_array($reader->readString($csv));

        self::assertSame([['id' => '1', 'name' => 'John']], $records);
    }

    public function testLeagueWritesHierarchicalHeaderForBaresheet(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        $writer = LeagueWriter::createFromStream($stream);
        $writer->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $writer->insertAll([
            ['Domaine',    '',       ''],
            ['Date',       'Statut', 'ICD10'],
            ['2026-07-30', 'Actif',  'A01'],
        ]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $reader = new CsvReader();
        $options = new Options(assoc: true, headerRows: 2);
        $options->applyTo($reader);
        $records = iterator_to_array($reader->readString($csv));

        self::assertSame(
            [
                ['Domaine' => ['Date' => '2026-07-30', 'Statut' => 'Actif', 'ICD10' => 'A01']],
            ],
            $records,
        );
    }

    public function testLeagueReadsBaresheetHierarchicalHeader(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->headers = ['Domaine' => ['Date', 'Statut', 'ICD10']];
        $csv = $writer->writeString([
            ['2026-07-30', 'Actif', 'A01'],
        ]);

        $reader = LeagueReader::createFromString($csv);
        $reader->setDelimiter(',')->setEnclosure('"')->setEscape('');

        $records = array_values(iterator_to_array($reader->getRecords()));

        self::assertSame(
            [
                ['Domaine',    'Domaine', 'Domaine'],
                ['Date',       'Statut',  'ICD10'],
                ['2026-07-30', 'Actif',   'A01'],
            ],
            $records,
        );
    }

    public function testBothNormalizeShortAndLongRows(): void
    {
        $csv = "id,name,email\n1,John\n2,Jane,jane@example.com,extra";

        $league = LeagueReader::createFromString($csv);
        $league->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $league->setHeaderOffset(0);
        $leagueRecords = array_values(iterator_to_array($league->getRecords()));

        $baresheet = new CsvReader();
        $options = new Options(assoc: true);
        $options->applyTo($baresheet);
        $baresheetRecords = iterator_to_array($baresheet->readString($csv));

        $expected = [
            ['id' => '1', 'name' => 'John', 'email' => null],
            ['id' => '2', 'name' => 'Jane', 'email' => 'jane@example.com'],
        ];

        self::assertSame($expected, $leagueRecords);
        self::assertSame($expected, $baresheetRecords);
    }

    public function testEmbeddedQuotesDelimitersAndNewlines(): void
    {
        $data = [
            ['name', 'notes'],
            ['José "Pepe"', "ligne 1\nligne 2"],
            ['Doe, Jane', 'semi;colon'],
            ['tab' . "\t" . 'here', 'quote "x"'],
        ];

        // Baresheet writes, League reads
        $writer = new CsvWriter();
        $writer->bom = false;
        $baresheetCsv = $writer->writeString($data);

        $reader = LeagueReader::createFromString($baresheetCsv);
        $reader->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $reader->setHeaderOffset(0);
        $leagueRecords = array_values(iterator_to_array($reader->getRecords()));

        // League writes, Baresheet reads
        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        $leagueWriter = LeagueWriter::createFromStream($stream);
        $leagueWriter->setDelimiter(',')->setEnclosure('"')->setEscape('');
        $leagueWriter->insertAll($data);
        rewind($stream);
        $leagueCsv = stream_get_contents($stream);
        fclose($stream);

        $baresheetReader = new CsvReader();
        $options = new Options(assoc: true);
        $options->applyTo($baresheetReader);
        $baresheetRecords = iterator_to_array($baresheetReader->readString($leagueCsv));

        $expected = [
            ['name' => 'José "Pepe"', 'notes' => "ligne 1\nligne 2"],
            ['name' => 'Doe, Jane', 'notes' => 'semi;colon'],
            ['name' => "tab\there", 'notes' => 'quote "x"'],
        ];

        self::assertSame($expected, $leagueRecords);
        self::assertSame($expected, $baresheetRecords);
    }
}
