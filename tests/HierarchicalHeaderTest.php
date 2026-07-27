<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\Options;
use PHPUnit\Framework\TestCase;

class HierarchicalHeaderTest extends TestCase
{
    public function testCsvTwoLevelHeaders(): void
    {
        $csv = "Person,,\nFirst Name,Last Name,Email\nJohn,Doe,john@example.com\n";

        $reader = new CsvReader(new Options(assoc: true, headerRows: 2));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame(
            [
                'Person' => [
                    'First Name' => 'John',
                    'Last Name' => 'Doe',
                    'Email' => 'john@example.com',
                ],
            ],
            $data[0],
        );
    }

    public function testCsvThreeLevelHeaders(): void
    {
        $csv = "Info,,\nContact,,Address\nEmail,Phone,City\njohn@example.com,555-1234,Paris\n";

        $reader = new CsvReader(new Options(assoc: true, headerRows: 3));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame(
            [
                'Info' => [
                    'Contact' => [
                        'Email' => 'john@example.com',
                        'Phone' => '555-1234',
                    ],
                    'Address' => [
                        'City' => 'Paris',
                    ],
                ],
            ],
            $data[0],
        );
    }

    public function testCsvFlatHeaderStillWorks(): void
    {
        $csv = "First Name,Email\nJohn,john@example.com\n";

        $reader = new CsvReader(new Options(assoc: true, headerRows: 1));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame(
            [
                'First Name' => 'John',
                'Email' => 'john@example.com',
            ],
            $data[0],
        );
    }

    public function testCsvNotEnoughHeaderRowsThrows(): void
    {
        $csv = "Only one row\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough rows for header');

        $reader = new CsvReader(new Options(assoc: true, headerRows: 3));
        iterator_to_array($reader->readString($csv));
    }

    public function testCsvColumnsWithHierarchicalHeaders(): void
    {
        $csv = "Person,,\nFirst Name,Last Name,Email\nJohn,Doe,john@example.com\n";

        $reader = new CsvReader(new Options(
            assoc: true,
            headerRows: 2,
            columns: ['Person' => ['Last Name', 'Email']],
        ));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame(
            [
                'Person' => [
                    'Last Name' => 'Doe',
                    'Email' => 'john@example.com',
                ],
            ],
            $data[0],
        );
    }

    public function testCsvRequiredColumnsWithHierarchicalHeaders(): void
    {
        $csv = "Person,\nFirst Name,Email\nJohn,john@example.com\n";

        $reader = new CsvReader(new Options(
            assoc: true,
            headerRows: 2,
            requiredColumns: ['Person' => ['First Name']],
        ));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertArrayHasKey('Person', $data[0]);
    }

    public function testCsvRequiredColumnsHierarchicalMissing(): void
    {
        $csv = "Person,\nFirst Name,Email\nJohn,john@example.com\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: Person.Phone');

        $reader = new CsvReader(new Options(
            assoc: true,
            headerRows: 2,
            requiredColumns: ['Person' => ['First Name', 'Phone']],
        ));
        iterator_to_array($reader->readString($csv));
    }

    public function testCsvHierarchicalWithOffset(): void
    {
        $csv = "Person,\nFirst Name,Email\nJohn,john@example.com\nJane,jane@example.com\n";

        $reader = new CsvReader(new Options(
            assoc: true,
            headerRows: 2,
            offset: 1,
        ));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame('Jane', $data[0]['Person']['First Name']);
    }

    public function testCsvHierarchicalWithAccentedHeaders(): void
    {
        $csv = "Information,,\nPrénom,Nom,Adresse électronique\nJosé,Muñoz,jose@example.com\n";

        $reader = new CsvReader(new Options(assoc: true, headerRows: 2));
        $data = iterator_to_array($reader->readString($csv));

        self::assertCount(1, $data);
        self::assertSame('José', $data[0]['Information']['Prénom']);
        self::assertSame('jose@example.com', $data[0]['Information']['Adresse électronique']);
    }
}
