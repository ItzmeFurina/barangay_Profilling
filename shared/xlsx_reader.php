<?php
/**
 * Lightweight XLSX reader using PHP's built-in ZipArchive and DOMDocument.
 * No external dependencies required.
 *
 * Usage:
 *   $reader = new XlsxReader();
 *   $rows = $reader->read('/path/to/file.xlsx');
 *   // $rows[0] = header row, $rows[1..N] = data rows
 */
class XlsxReader
{
    /**
     * Read an XLSX file and return all rows as a 2D array.
     * Each row is a numerically-indexed array of cell values.
     */
    public function read(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException("Could not open XLSX file as ZIP archive.");
        }

        try {
            // Read shared strings (cell value cache)
            $sharedStrings = $this->readSharedStrings($zip);

            // Read the first worksheet (or the active one)
            $sheetData = $this->readWorksheet($zip, $sharedStrings, 0);

            return $sheetData;
        } finally {
            $zip->close();
        }
    }

    /**
     * Read shared strings from xl/sharedStrings.xml.
     * XLSX stores repeated string values in a shared table; cells reference
     * them by index. This builds the lookup array.
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $strings = [];
        $xmlPath = 'xl/sharedStrings.xml';

        if ($zip->locateName($xmlPath) === false) {
            return $strings;
        }

        $xml = $zip->getFromName($xmlPath);
        if ($xml === false) {
            return $strings;
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->loadXML($xml)) {
            return $strings;
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sis = $dom->getElementsByTagNameNS($ns, 'si');

        foreach ($sis as $index => $si) {
            $strings[$index] = $this->extractSiValue($si, $ns);
        }

        return $strings;
    }

    /**
     * Extract the text value from a <si> (shared string item) element.
     * A <si> can contain either a single <t> or multiple <r><t> runs.
     */
    private function extractSiValue(DOMElement $si, string $ns): string
    {
        // Simple case: single <t> child
        $ts = $si->getElementsByTagNameNS($ns, 't');
        if ($ts->length === 1 && $ts->item(0)->parentNode->localName !== 'r') {
            return $ts->item(0)->textContent;
        }

        // Rich text: multiple <r><t> runs - concatenate them
        $parts = [];
        $rs = $si->getElementsByTagNameNS($ns, 'r');
        foreach ($rs as $r) {
            $tNodes = $r->getElementsByTagNameNS($ns, 't');
            foreach ($tNodes as $t) {
                $parts[] = $t->textContent;
            }
        }

        return implode('', $parts);
    }

    /**
     * Read worksheet data from xl/worksheets/sheet1.xml (or the Nth sheet).
     * Returns a 2D array of cell values.
     */
    private function readWorksheet(ZipArchive $zip, array $sharedStrings, int $sheetIndex = 0): array
    {
        // Determine sheet file path
        $sheetPaths = [
            'xl/worksheets/sheet1.xml',
            'xl/worksheets/sheet2.xml',
            'xl/worksheets/sheet3.xml',
            'xl/worksheets/sheet4.xml',
            'xl/worksheets/sheet5.xml',
        ];

        $xmlPath = $sheetPaths[$sheetIndex] ?? "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";

        if ($zip->locateName($xmlPath) === false) {
            // Try the workbook.xml.rels to find the actual sheet path
            $xmlPath = $this->findSheetPath($zip, $sheetIndex);
            if ($xmlPath === null) {
                return [];
            }
        }

        $xml = $zip->getFromName($xmlPath);
        if ($xml === false) {
            return [];
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->loadXML($xml)) {
            return [];
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        $sheetData = $dom->getElementsByTagNameNS($ns, 'sheetData')->item(0);
        if ($sheetData === null) {
            return [];
        }

        $rowElements = $sheetData->getElementsByTagNameNS($ns, 'row');

        foreach ($rowElements as $rowEl) {
            $rowData = [];
            $cells = $rowEl->getElementsByTagNameNS($ns, 'c');

            // Build a map of cell references to values
            $cellMap = [];
            $maxCol = 0;

            foreach ($cells as $cell) {
                $ref = $cell->getAttribute('r'); // e.g., "A1", "B2"
                $type = $cell->getAttribute('t'); // "s" = shared string, "n" = number, etc.

                $valueEl = $cell->getElementsByTagNameNS($ns, 'v')->item(0);
                $rawValue = $valueEl ? $valueEl->textContent : '';

                if ($type === 's') {
                    // Shared string reference
                    $index = (int) $rawValue;
                    $value = $sharedStrings[$index] ?? '';
                } elseif ($type === 'b') {
                    // Boolean
                    $value = $rawValue === '1' ? 'TRUE' : 'FALSE';
                } else {
                    // Number or inline string
                    $value = $rawValue;
                }

                // Parse column letter(s) from reference
                $colLetter = preg_replace('/\d+/', '', $ref);
                $colIndex = $this->columnToIndex($colLetter);
                $cellMap[$colIndex] = $value;
                $maxCol = max($maxCol, $colIndex);
            }

            // Build the row array
            for ($c = 0; $c <= $maxCol; $c++) {
                $rowData[] = $cellMap[$c] ?? '';
            }

            // Skip completely empty rows (all values empty)
            $hasContent = false;
            foreach ($rowData as $v) {
                if (trim((string) $v) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent) {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }

    /**
     * Try to find the actual sheet path from workbook.xml.rels.
     */
    private function findSheetPath(ZipArchive $zip, int $sheetIndex): ?string
    {
        $relsPath = 'xl/_rels/workbook.xml.rels';
        if ($zip->locateName($relsPath) === false) {
            return null;
        }

        $xml = $zip->getFromName($relsPath);
        if ($xml === false) {
            return null;
        }

        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) {
            return null;
        }

        $rels = $dom->getElementsByTagName('Relationship');
        $sheets = [];
        foreach ($rels as $rel) {
            if ($rel->getAttribute('Type') === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet') {
                $sheets[] = $rel->getAttribute('Target');
            }
        }

        // Sort by rId (they appear in order)
        sort($sheets);

        $target = $sheets[$sheetIndex] ?? null;
        if ($target === null) {
            return null;
        }

        // Target is relative to xl/ directory
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        return 'xl/' . $target;
    }

    /**
     * Convert Excel column letter(s) to a 0-based column index.
     * A=0, B=1, ..., Z=25, AA=26, AB=27, etc.
     */
    private function columnToIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}
