<?php
namespace App\Services\BulkSms;

/**
 * Streaming XLSX to CSV, ported unchanged from the old platform.
 *
 * ZipArchive and XMLReader only — no Composer — and constant memory, so a
 * million-row contact sheet converts on shared hosting. Used by the
 * campaign worker, never in a web request.
 */
final class XlsxReader {

    /**
     * Convert the first sheet of an XLSX file to a CSV file on disk.
     *
     * @param  string $xlsxPath  Absolute path to the uploaded .xlsx file
     * @param  string $csvPath   Absolute path where the CSV should be written
     * @return int               Number of DATA rows written (header not counted)
     * @throws RuntimeException  If the file cannot be opened or parsed
     */
    public static function toCsv(string $xlsxPath, string $csvPath): int {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('PHP zip extension is required for Excel (.xlsx) support. Enable it in php.ini or contact your host.');
        }
        if (!extension_loaded('xmlreader')) {
            throw new \RuntimeException('PHP xmlreader extension is required for Excel support. Enable it in php.ini or contact your host.');
        }

        $realXlsx = realpath($xlsxPath);
        if (!$realXlsx) {
            throw new \RuntimeException("XLSX file not found: $xlsxPath");
        }

        // 1. Load shared strings (all text cell values are stored here by index)
        $sharedStrings = self::loadSharedStrings($realXlsx);

        // 2. Discover which XML path holds the first worksheet
        $sheetXmlPath = self::firstSheetPath($realXlsx);

        // 3. Stream the sheet XML row by row → write CSV
        $csvHandle = fopen($csvPath, 'w');
        if ($csvHandle === false) {
            throw new \RuntimeException("Cannot open CSV output path for writing: $csvPath");
        }

        $reader = new \XMLReader();
        $uri    = 'zip://' . $realXlsx . '#' . $sheetXmlPath;
        if (!@$reader->open($uri)) {
            fclose($csvHandle);
            throw new \RuntimeException("Cannot read worksheet XML inside: $xlsxPath — verify the file is a valid .xlsx.");
        }

        $dataRows      = 0;
        $headerWritten = false;
        $currentRow    = [];
        $inCell        = false;
        $cellType      = '';
        $cellColIdx    = 0;
        $cellValue     = '';
        $captureText   = false;

        while ($reader->read()) {
            switch ($reader->nodeType) {

                case \XMLReader::ELEMENT:
                    switch ($reader->localName) {
                        case 'row':
                            $currentRow = [];
                            break;

                        case 'c': // individual cell
                            $ref        = $reader->getAttribute('r') ?? 'A1';
                            $cellColIdx = self::columnIndex($ref);
                            $cellType   = $reader->getAttribute('t') ?? '';
                            $cellValue  = '';
                            $inCell     = true;
                            $captureText = false;

                            // Handle self-closing empty cells (<c r="B1"/>)
                            if ($reader->isEmptyElement) {
                                while (count($currentRow) <= $cellColIdx) $currentRow[] = '';
                                $currentRow[$cellColIdx] = '';
                                $inCell = false;
                            }
                            break;

                        case 'v':   // <v> value node (numbers, shared-string index)
                        case 't':   // <t> inside inline-string <is><t>text</t></is>
                            $captureText = $inCell;
                            break;
                    }
                    break;

                case \XMLReader::TEXT:
                case \XMLReader::CDATA:
                    if ($captureText) {
                        $cellValue .= $reader->value;
                    }
                    break;

                case \XMLReader::END_ELEMENT:
                    switch ($reader->localName) {
                        case 'v':
                        case 't':
                            $captureText = false;
                            break;

                        case 'c':
                            if (!$inCell) break;
                            // Resolve the stored value to a display string
                            switch ($cellType) {
                                case 's': // shared string
                                    $val = $sharedStrings[(int)$cellValue] ?? '';
                                    break;
                                case 'b': // boolean
                                    $val = $cellValue === '1' ? 'TRUE' : 'FALSE';
                                    break;
                                case 'inlineStr': // inline string — value already in $cellValue via <t>
                                    $val = $cellValue;
                                    break;
                                default:  // number, date, formula result, etc.
                                    $val = $cellValue;
                            }

                            // Fill any skipped (empty) columns with ''
                            while (count($currentRow) < $cellColIdx) $currentRow[] = '';
                            $currentRow[$cellColIdx] = $val;
                            $inCell      = false;
                            $captureText = false;
                            break;

                        case 'row':
                            // Skip rows where every cell is empty
                            $hasData = false;
                            foreach ($currentRow as $v) {
                                if ($v !== '') { $hasData = true; break; }
                            }
                            if ($hasData) {
                                fputcsv($csvHandle, $currentRow);
                                if (!$headerWritten) {
                                    $headerWritten = true;
                                } else {
                                    $dataRows++;
                                }
                            }
                            $currentRow = [];
                            break;
                    }
                    break;
            }
        }

        $reader->close();
        fclose($csvHandle);
        return $dataRows;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Load all shared strings from xl/sharedStrings.xml into an array.
     * For typical bulk-SMS files (phone + a few text columns) this is small.
     * Worst case: 1 M unique names × ~20 chars ≈ 20 MB — acceptable.
     */
    private static function loadSharedStrings(string $xlsxPath): array {
        $strings = [];
        $uri     = 'zip://' . $xlsxPath . '#xl/sharedStrings.xml';
        $reader  = new \XMLReader();
        if (!@$reader->open($uri)) {
            return $strings; // No shared strings — all cells are inline numbers
        }

        $inSi    = false;
        $inT     = false;
        $current = '';

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    if ($reader->localName === 'si') { $inSi = true;  $current = ''; }
                    if ($reader->localName === 't')  { $inT  = true; }
                    break;
                case \XMLReader::TEXT:
                case \XMLReader::CDATA:
                    if ($inT) $current .= $reader->value;
                    break;
                case \XMLReader::END_ELEMENT:
                    if ($reader->localName === 't')  { $inT = false; }
                    if ($reader->localName === 'si') { $strings[] = $current; $inSi = false; }
                    break;
            }
        }
        $reader->close();
        return $strings;
    }

    /**
     * Find the XML path of the first worksheet inside the XLSX zip.
     * Falls back to 'xl/worksheets/sheet1.xml' if the rels file is missing.
     */
    private static function firstSheetPath(string $xlsxPath): string {
        $default = 'xl/worksheets/sheet1.xml';
        $uri     = 'zip://' . $xlsxPath . '#xl/_rels/workbook.xml.rels';
        $reader  = new \XMLReader();
        if (!@$reader->open($uri)) return $default;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $type   = $reader->getAttribute('Type') ?? '';
                $target = $reader->getAttribute('Target') ?? '';
                if (strpos($type, '/worksheet') !== false && $target !== '') {
                    $reader->close();
                    // Target may be relative: "../worksheets/sheet1.xml" or "worksheets/sheet1.xml"
                    $target = preg_replace('#^\.\./#', 'xl/', $target);
                    $target = ltrim($target, '/');
                    if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
                    return $target;
                }
            }
        }
        $reader->close();
        return $default;
    }

    /**
     * Convert a cell reference (e.g. "A1", "AE256") to a 0-based column index.
     * A→0, B→1, … Z→25, AA→26, AB→27 …
     */
    private static function columnIndex(string $cellRef): int {
        preg_match('/^([A-Za-z]+)/', $cellRef, $m);
        $col = strtoupper($m[1] ?? 'A');
        $n   = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n - 1; // 0-based
    }
}
