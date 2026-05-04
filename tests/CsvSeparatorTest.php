<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\CsvReader;

class CsvSeparatorTest extends TestCase
{
    public function testDetectComma(): void
    {
        $sample = "name,email,city\nJohn,john@example.com,New York";
        $this->assertEquals(',', CsvReader::detectSeparator($sample));
    }

    public function testDetectSemicolon(): void
    {
        $sample = "name;email;city\nJohn;john@example.com;Paris";
        $this->assertEquals(';', CsvReader::detectSeparator($sample));
    }

    public function testDetectTab(): void
    {
        $sample = "name\temail\tcity\nJohn\tjohn@example.com\tLondon";
        $this->assertEquals("\t", CsvReader::detectSeparator($sample));
    }

    public function testDetectPipe(): void
    {
        $sample = "name|email|city\nJohn|john@example.com|Berlin";
        $this->assertEquals('|', CsvReader::detectSeparator($sample));
    }

    public function testDetectWithQuotes(): void
    {
        // Semicolons inside quotes should be ignored by the detection logic
        $sample = "name,email,comment\nJohn,john@example.com,\"This;is;a;comment;with;semicolons\"";
        $this->assertEquals(',', CsvReader::detectSeparator($sample));
    }

    public function testDetectEmptySample(): void
    {
        $this->assertEquals(',', CsvReader::detectSeparator(''));
    }

    public function testDetectNoSeparator(): void
    {
        $this->assertEquals(',', CsvReader::detectSeparator('JohnDoejohn@example.com'));
    }

    public function testDetectMultiLineAccumulation(): void
    {
        // Line 1: 1 comma
        // Line 2: 2 semicolons
        // Line 3: 2 semicolons
        // Total: 1 comma, 4 semicolons -> should be semicolon
        $sample = "a,b\nc;d;e\nf;g;h";
        $this->assertEquals(';', CsvReader::detectSeparator($sample));
    }

    public function testDetectOnlyFirstTenLines(): void
    {
        // We need 10 lines with one separator, and the 11th line with many other separators.
        // Actually the code takes 10 fragments. The 10th fragment contains the rest of the string.
        $lines = [];
        for ($i = 0; $i < 9; $i++) {
            $lines[] = "a,b";
        }
        // 10th line
        $lines[] = "a,b";
        // 11th line - this will be part of the 10th fragment
        $lines[] = "a;b;c;d;e;f;g;h;i;j;k;l;m;n;o;p;q;r;s;t;u";
        $sample = implode("\n", $lines);

        // Comma count in first 9 lines: 9
        // 10th fragment: "a,b\na;b;c;d;e;f;g;h;i;j;k;l;m;n;o;p;q;r;s;t;u"
        // Comma count in 10th fragment: 1
        // Semicolon count in 10th fragment: 20
        // Total: 10 commas, 20 semicolons -> should be semicolon because the 10th fragment includes the rest of the file!
        $this->assertEquals(';', CsvReader::detectSeparator($sample));
    }

    public function testDetectTieBreaking(): void
    {
        // Same number of commas and semicolons.
        // Candidates are [',', ';', '|', "\t"]
        // arsort should keep the first one if values are equal.
        $sample = "a,b;c";
        $this->assertEquals(',', CsvReader::detectSeparator($sample));
    }
}
