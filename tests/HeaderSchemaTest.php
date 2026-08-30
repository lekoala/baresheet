<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\MissingColumnException;
use LeKoala\Baresheet\HeaderSchema;
use PHPUnit\Framework\TestCase;

class HeaderSchemaTest extends TestCase
{
    // ─── fromFlatHeaders ──────────────────────────────────────

    public function testFromFlatHeadersBasic(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        self::assertSame(3, $schema->columnCount());
        self::assertSame(['First Name', 'Last Name', 'Email'], $schema->columnNames());
        self::assertSame([0, 1, 2], $schema->indices());
    }

    public function testFromFlatHeadersWithAccents(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['Prénom', 'Numéro de téléphone', 'Adresse électronique']);

        self::assertSame(3, $schema->columnCount());
        self::assertSame('Prénom', $schema->columnNames()[0]);
    }

    public function testFromFlatHeadersWithSpecialChars(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['Item #', 'Price (EUR)', 'In Stock?', 'N° SIRET']);

        self::assertSame(4, $schema->columnCount());
        self::assertSame('N° SIRET', $schema->columnNames()[3]);
    }

    public function testFromFlatHeadersEmpty(): void
    {
        $schema = HeaderSchema::fromFlatHeaders([]);

        self::assertSame(0, $schema->columnCount());
        self::assertSame([], $schema->columnNames());
    }

    public function testFromFlatHeadersRejectsDuplicates(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

        HeaderSchema::fromFlatHeaders(['Name', 'Email', 'Name']);
    }

    public function testFromFlatHeadersTrimsWhitespace(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['  Name', 'Email  ']);

        self::assertSame(['  Name', 'Email  '], $schema->columnNames());
    }

    // ─── fromRows (single row = fromFlatHeaders) ──────────────

    public function testFromRowsSingleRow(): void
    {
        $schema = HeaderSchema::fromRows([['First Name', 'Last Name', 'Email']]);

        self::assertSame(3, $schema->columnCount());
        self::assertSame(['First Name', 'Last Name', 'Email'], $schema->columnNames());
    }

    public function testFromRowsEmpty(): void
    {
        $schema = HeaderSchema::fromRows([]);

        self::assertSame(0, $schema->columnCount());
    }

    // ─── fromDefinition ───────────────────────────────────────

    public function testFromDefinitionFlat(): void
    {
        $schema = HeaderSchema::fromDefinition(['First Name', 'Email']);

        self::assertSame(2, $schema->columnCount());
        self::assertSame(['First Name', 'Email'], $schema->columnNames());
    }

    // ─── checkRequiredColumns ─────────────────────────────────

    public function testCheckRequiredColumnsPass(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        $schema->checkRequiredColumns(['First Name', 'Email']);
        // No exception thrown
        self::assertTrue(true);
    }

    public function testCheckRequiredColumnsFail(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Email']);

        $this->expectException(MissingColumnException::class);
        $this->expectExceptionMessage('Missing required columns: Phone');

        $schema->checkRequiredColumns(['First Name', 'Phone']);
    }

    public function testCheckRequiredColumnsEmptyArray(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name']);

        $schema->checkRequiredColumns([]);
        self::assertTrue(true);
    }

    // ─── flattenRow ───────────────────────────────────────────

    public function testFlattenRowWithFlatSequentialRowPadded(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);
        $result = $schema->flattenRow(['a', 'b']);
        self::assertSame(['a', 'b', null], $result);
    }

    public function testFlattenRowWithFlatSequentialRowSliced(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);
        $result = $schema->flattenRow(['a', 'b', 'c', 'd']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function testFlattenRowWithFlatSequentialRowExact(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);
        $result = $schema->flattenRow(['a', 'b', 'c']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function testFlattenRowWithNestedAssociativeArray(): void
    {
        $schema = HeaderSchema::fromDefinition([
            'User' => [
                'FirstName',
                'LastName',
            ],
            'Contact' => [
                'Email',
            ],
        ]);

        $result = $schema->flattenRow([
            'User' => ['FirstName' => 'John', 'LastName' => 'Doe'],
            'Contact' => ['Email' => 'john@example.com'],
        ]);

        self::assertSame(['John', 'Doe', 'john@example.com'], $result);
    }

    public function testFlattenRowWithNestedAssociativeArrayMissingPath(): void
    {
        $schema = HeaderSchema::fromDefinition([
            'User' => [
                'FirstName',
                'LastName',
            ],
            'Contact' => [
                'Email',
            ],
        ]);

        $result = $schema->flattenRow([
            'User' => ['FirstName' => 'John'],
            'Other' => ['Value' => 'Ignore'],
        ]);

        self::assertSame(['John', null, null], $result);
    }

    public function testFlattenRowWithExplicitIndicesAlreadyFlat(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);

        // Use reflection to explicitly set physIndices
        $reflection = new \ReflectionClass($schema);
        $prop = $reflection->getProperty('physIndices');
        $prop->setAccessible(true);
        $prop->setValue($schema, [2, 0, 1]); // A maps to index 2, B to 0, C to 1

        $result = $schema->flattenRow(['val_b', 'val_c', 'val_a']);
        self::assertEquals([0 => 'val_b', 1 => 'val_c', 2 => 'val_a'], $result);
    }

    public function testFlattenRowWithExplicitIndicesUnsetReturnsNulls(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);

        // Use reflection to explicitly set physIndices
        $reflection = new \ReflectionClass($schema);
        $prop = $reflection->getProperty('physIndices');
        $prop->setAccessible(true);
        $prop->setValue($schema, [2, 0, 1]);

        $result = $schema->flattenRow([]);
        self::assertSame([0 => null, 1 => null, 2 => null], $result);
    }

    // ─── select ──────────────────────────────────────────────

    public function testSelectColumns(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        $selected = $schema->select(['Email', 'First Name']);

        self::assertSame(2, $selected->columnCount());
        self::assertSame([2, 0], $selected->indices());
        self::assertSame(['Email', 'First Name'], $selected->columnNames());

        $result = $selected->mapRow(['John', 'Doe', 'john@example.com']);
        // Output follows the selection order: Email first, then First Name
        self::assertSame(['Email' => 'john@example.com', 'First Name' => 'John'], $result);
    }

    public function testSelectMissing(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Email']);

        $this->expectException(MissingColumnException::class);
        $this->expectExceptionMessage('Missing required columns: Phone');

        $schema->select(['Phone']);
    }

    public function testSelectEmpty(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Email']);

        $selected = $schema->select([]);
        self::assertSame($schema->columnCount(), $selected->columnCount());
    }

    // ─── Indices ───────────────────────────────────────────────

    public function testIndicesReturnsArrayKeysWhenPhysIndicesIsNull(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        self::assertSame([0, 1, 2], $schema->indices());
    }

    public function testIndicesReturnsPhysIndicesWhenNotNull(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);
        $selected = $schema->select(['Email', 'First Name']);

        self::assertSame([2, 0], $selected->indices());
    }

    // ─── mapRow (flat) ────────────────────────────────────────

    public function testMapRowFlat(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        $result = $schema->mapRow(['John', 'Doe', 'john@example.com']);

        self::assertSame(
            [
                'First Name' => 'John',
                'Last Name' => 'Doe',
                'Email' => 'john@example.com',
            ],
            $result,
        );
    }

    public function testMapRowWithAccentsInValues(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['Prénom', 'Nom']);

        $result = $schema->mapRow(['José', 'Muñoz']);

        self::assertSame('José', $result['Prénom']);
        self::assertSame('Muñoz', $result['Nom']);
    }

    public function testMapRowWithNullValues(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Last Name', 'Email']);

        $result = $schema->mapRow(['John', null, '']);

        self::assertSame('John', $result['First Name']);
        self::assertNull($result['Last Name']);
        self::assertSame('', $result['Email']);
    }

    public function testMapRowWithSelectedColumns(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['email', 'name', 'age']);

        $selected = $schema->select(['name', 'email']);
        $result = $selected->mapRow(['john@example.com', 'John', '25']);

        self::assertSame(['name' => 'John', 'email' => 'john@example.com'], $result);
    }

    // ─── columnCount ──────────────────────────────────────────

    public function testColumnCount(): void
    {
        $schemaEmpty = HeaderSchema::fromFlatHeaders([]);
        self::assertSame(0, $schemaEmpty->columnCount());

        $schemaOne = HeaderSchema::fromFlatHeaders(['Single']);
        self::assertSame(1, $schemaOne->columnCount());

        $schemaMultiple = HeaderSchema::fromFlatHeaders(['A', 'B', 'C']);
        self::assertSame(3, $schemaMultiple->columnCount());

        $schemaHierarchical = HeaderSchema::fromDefinition([
            'Person' => [
                'Contact' => [
                    'Email',
                    'Phone',
                ],
            ],
        ]);
        self::assertSame(2, $schemaHierarchical->columnCount());
    }

    // ─── paths (flat) ─────────────────────────────────────────

    public function testPathsFlat(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Email']);

        self::assertSame(
            [
                0 => ['First Name'],
                1 => ['Email'],
            ],
            $schema->paths(),
        );
    }

    // ─── Hierarchical: fromRows ───────────────────────────────

    public function testFromRowsTwoLevel(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Person',     '',          '',      'Company'],
            ['First Name', 'Last Name', 'Email', 'Name'],
        ]);

        self::assertSame(4, $schema->columnCount());
        self::assertSame(['First Name', 'Last Name', 'Email', 'Name'], $schema->columnNames());

        $paths = $schema->paths();
        self::assertSame(['Person', 'First Name'], $paths[0]);
        self::assertSame(['Person', 'Last Name'], $paths[1]);
        self::assertSame(['Person', 'Email'], $paths[2]);
        self::assertSame(['Company', 'Name'], $paths[3]);
    }

    public function testFromRowsThreeLevel(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Person',  '',      '',         'Order'],
            ['Contact', '',      'Identity', 'Shipping'],
            ['Email',   'Phone', 'ID',       'City'],
        ]);

        self::assertSame(4, $schema->columnCount());

        $paths = $schema->paths();
        self::assertSame(['Person', 'Contact', 'Email'], $paths[0]);
        self::assertSame(['Person', 'Contact', 'Phone'], $paths[1]);
        self::assertSame(['Person', 'Identity', 'ID'], $paths[2]);
        self::assertSame(['Order', 'Shipping', 'City'], $paths[3]);
    }

    public function testFromRowsParentResetDeep(): void
    {
        // A new parent should reset deeper prevValues
        $schema = HeaderSchema::fromRows([
            ['A',  '',   'B',  ''],
            ['X',  '',   '',   ''],
            ['a1', 'a2', 'b1', 'b2'],
        ]);

        $paths = $schema->paths();
        self::assertSame(['A', 'X', 'a1'], $paths[0]);
        self::assertSame(['A', 'X', 'a2'], $paths[1]);
        self::assertSame(['B', 'b1'], $paths[2]);
        self::assertSame(['B', 'b2'], $paths[3]);
    }

    public function testFromRowsWithGaps(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Category',     '',     '',            'Notes'],
            ['Sub Category', 'Code', 'Description', ''],
        ]);

        $paths = $schema->paths();
        self::assertSame(['Category', 'Sub Category'], $paths[0]);
        self::assertSame(['Category', 'Code'], $paths[1]);
        self::assertSame(['Category', 'Description'], $paths[2]);
        self::assertSame(['Notes'], $paths[3]);
    }

    public function testFromRowsUnevenWidth(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Meta Infos', '',     '',       ''],
            ['Date',       'Type', 'Valeur', 'Statut'],
        ]);

        self::assertSame(4, $schema->columnCount());

        $paths = $schema->paths();
        self::assertSame(['Meta Infos', 'Date'], $paths[0]);
        self::assertSame(['Meta Infos', 'Type'], $paths[1]);
        self::assertSame(['Meta Infos', 'Valeur'], $paths[2]);
        self::assertSame(['Meta Infos', 'Statut'], $paths[3]);
    }

    public function testFromRowsRejectsDuplicatePaths(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

        HeaderSchema::fromRows([
            ['Category', 'Category'],
            ['Name',     'Name'],
        ]);
    }

    public function testFromRowsRejectsPrefixCollision(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('cannot coexist');

        // Column 0: ['Cat', ''] → path ['Cat']
        // Column 1: ['', 'SubName'] → path ['Cat', 'SubName']
        HeaderSchema::fromRows([
            ['Cat', ''],
            ['', 'SubName'],
        ]);
    }

    // ─── Hierarchical: mapRow ─────────────────────────────────

    public function testMapRowHierarchicalTwoLevel(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Person',     '',          '',      'Company'],
            ['First Name', 'Last Name', 'Email', 'Name'],
        ]);

        $result = $schema->mapRow(['John', 'Doe', 'john@example.com', 'ACME Inc']);

        self::assertSame(
            [
                'Person' => [
                    'First Name' => 'John',
                    'Last Name' => 'Doe',
                    'Email' => 'john@example.com',
                ],
                'Company' => [
                    'Name' => 'ACME Inc',
                ],
            ],
            $result,
        );
    }

    public function testMapRowHierarchicalThreeLevel(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Person',  '',      '',         'Order'],
            ['Contact', '',      'Identity', 'Shipping'],
            ['Email',   'Phone', 'ID',       'City'],
        ]);

        $result = $schema->mapRow(['a@b.com', '1234', 'XYZ', 'Paris']);

        self::assertSame(
            [
                'Person' => [
                    'Contact' => [
                        'Email' => 'a@b.com',
                        'Phone' => '1234',
                    ],
                    'Identity' => [
                        'ID' => 'XYZ',
                    ],
                ],
                'Order' => [
                    'Shipping' => [
                        'City' => 'Paris',
                    ],
                ],
            ],
            $result,
        );
    }

    // ─── Hierarchical: fromDefinition ─────────────────────────

    public function testFromDefinitionHierarchical(): void
    {
        $schema = HeaderSchema::fromDefinition([
            'First Name',
            'Last Name',
            'Contact' => [
                'Email',
                'Phone',
            ],
        ]);

        self::assertSame(4, $schema->columnCount());
        self::assertSame(['First Name', 'Last Name', 'Email', 'Phone'], $schema->columnNames());

        $paths = $schema->paths();
        self::assertSame(['First Name'], $paths[0]);
        self::assertSame(['Last Name'], $paths[1]);
        self::assertSame(['Contact', 'Email'], $paths[2]);
        self::assertSame(['Contact', 'Phone'], $paths[3]);

        $result = $schema->mapRow(['John', 'Doe', 'john@example.com', '555-1234']);
        self::assertSame(
            [
                'First Name' => 'John',
                'Last Name' => 'Doe',
                'Contact' => [
                    'Email' => 'john@example.com',
                    'Phone' => '555-1234',
                ],
            ],
            $result,
        );
    }

    public function testFromDefinitionDeeplyNested(): void
    {
        $schema = HeaderSchema::fromDefinition([
            'Person' => [
                'Contact' => [
                    'Email',
                    'Phone',
                ],
            ],
        ]);

        self::assertSame(2, $schema->columnCount());
        self::assertSame(['Email', 'Phone'], $schema->columnNames());

        $paths = $schema->paths();
        self::assertSame(['Person', 'Contact', 'Email'], $paths[0]);
        self::assertSame(['Person', 'Contact', 'Phone'], $paths[1]);
    }

    // ─── rename ───────────────────────────────────────────────

    public function testRenameFlat(): void
    {
        $schema = HeaderSchema::fromFlatHeaders(['First Name', 'Email']);
        $renamed = $schema->rename(['First Name' => 'first_name']);

        self::assertSame(['first_name', 'Email'], $renamed->columnNames());
        $result = $renamed->mapRow(['John', 'john@example.com']);
        self::assertSame(['first_name' => 'John', 'Email' => 'john@example.com'], $result);
    }

    public function testRenameHierarchical(): void
    {
        $schema = HeaderSchema::fromDefinition([
            'Person' => ['First Name', 'Email'],
        ]);
        $renamed = $schema->rename(['Person' => ['First Name' => 'first_name']]);

        self::assertSame(['first_name', 'Email'], $renamed->columnNames());
    }

    public function testRenameDetectsCollision(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

        $schema = HeaderSchema::fromFlatHeaders(['A', 'B']);
        $schema->rename(['A' => 'B']);
    }

    // ─── New group resets propagation ─────────────────────────

    public function testFromRowsNewGroupResetsPropagation(): void
    {
        $schema = HeaderSchema::fromRows([
            ['Cat A', '',      'Cat B', ''],
            ['Type',  'Value', 'Type',  'Value'],
        ]);

        $paths = $schema->paths();
        self::assertSame(['Cat A', 'Type'], $paths[0]);
        self::assertSame(['Cat A', 'Value'], $paths[1]);
        self::assertSame(['Cat B', 'Type'], $paths[2]);
        self::assertSame(['Cat B', 'Value'], $paths[3]);
    }
}
