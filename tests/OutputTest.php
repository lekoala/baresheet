<?php

/**
 * We declare the LeKoala\Baresheet namespace to mock header() and headers_sent()
 * so we can test HTTP header outputs without provoking "headers already sent"
 * inside PHPUnit tests in the CLI.
 */

namespace LeKoala\Baresheet {
    // Global variable to capture all sent headers
    $GLOBALS['baresheet_mock_headers'] = [];

    function headers_sent(): bool
    {
        return false;
    }

    function header(string $string, bool $replace = true, int $http_response_code = 0): void
    {
        $GLOBALS['baresheet_mock_headers'][] = $string;
    }
}

namespace LeKoala\Baresheet\Tests {
    use PHPUnit\Framework\TestCase;
    use LeKoala\Baresheet\CsvWriter;
    use LeKoala\Baresheet\XlsxWriter;
    use LeKoala\Baresheet\OdsWriter;

    class OutputTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['baresheet_mock_headers'] = [];
        }

        private function getMockHeaders(): array
        {
            return $GLOBALS['baresheet_mock_headers'] ?? [];
        }

        private function hasHeaderPrefix(array $headers, string $prefix): bool
        {
            foreach ($headers as $h) {
                if (str_starts_with(strtolower($h), strtolower($prefix))) {
                    return true;
                }
            }
            return false;
        }

        public function testCsvWriterOutput(): void
        {
            $writer = new CsvWriter();
            $writer->stream = false;

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.csv');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: text/csv'));
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Buffered output should have Content-Length header');
        }

        public function testCsvWriterOutputStream(): void
        {
            $writer = new CsvWriter();

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.csv');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: text/csv'));
            $this->assertFalse($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Streamed output should not have Content-Length header');
        }

        public function testXlsxWriterOutput(): void
        {
            $writer = new XlsxWriter();
            $writer->stream = false;

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.xlsx');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Buffered output should have Content-Length header');
        }

        public function testXlsxWriterOutputStream(): void
        {
            $writer = new XlsxWriter();

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.xlsx');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
            $this->assertFalse($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Streamed output should not have Content-Length header');
        }

        public function testOdsWriterOutput(): void
        {
            $writer = new OdsWriter();
            $writer->stream = false;

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.ods');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: application/vnd.oasis.opendocument.spreadsheet'));
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Buffered output should have Content-Length header');
        }

        public function testOdsWriterOutputStream(): void
        {
            $writer = new OdsWriter();

            ob_start();
            $writer->output([['A', 'B'], ['1', '2']], 'test.ods');
            $output = ob_get_clean();

            $this->assertNotEmpty($output);

            $headers = $this->getMockHeaders();
            $this->assertTrue($this->hasHeaderPrefix($headers, 'Content-Type: application/vnd.oasis.opendocument.spreadsheet'));
            $this->assertFalse($this->hasHeaderPrefix($headers, 'Content-Length:'), 'Streamed output should not have Content-Length header');
        }
    }
}
