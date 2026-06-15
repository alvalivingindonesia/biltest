<?php
/**
 * Build in Lombok — Detailed RAB Generator (ADR 0012)
 * api/lib/xlsx_writer.php
 *
 * A self-contained, dependency-free real .xlsx writer. NO Composer,
 * NO PhpSpreadsheet. PHP 7.4 compatible (no match(), no fn(), no enums,
 * no named args). Produces a valid multi-sheet Office Open XML
 * SpreadsheetML workbook using ZipArchive + hand-written XML.
 *
 * Public contract (called by api/drab_api.php handle_export()):
 *
 *   $w = new DrabXlsx();
 *   foreach ($data['sheets'] as $sh) {
 *       $w->addSheet($sh['title'], $sh['rows'], isset($sh['opts']) ? $sh['opts'] : array());
 *   }
 *   $w->stream($fname . '.xlsx');   // sends headers + bytes, caller exit()s after
 *
 *   $rows = array of rows; each row = array of cells (string | int | float | null).
 *   $opts may include ['summary' => true].
 *
 * ── How a cell becomes a NUMBER vs TEXT ─────────────────────────────────────
 *   A cell is written as an Excel number (<c><v>...</v></c>, no t="s") only when
 *   cellIsNumeric() returns true. That function is deliberately conservative:
 *     • Real PHP int/float (not NAN/INF)                         → number
 *     • A numeric string that ALSO survives a round-trip back to
 *       its own canonical form (so "007", "1.0e3", "+5", " 5 ",
 *       "0x1A", leading-zero codes, etc. stay TEXT)              → number
 *   Everything else — ref codes ("A.1.2", "P.1"), labels, blanks,
 *   percent-bearing strings ("Overhead (10%)"), unit codes ("m2") — is written
 *   as an inline string, XML-escaped. This guarantees identifier-like values are
 *   never silently coerced to numbers (which would drop leading zeros / change
 *   "1.10" to "1.1") while genuine quantities, rates and IDR totals are real
 *   numbers you can sum in the sheet.
 *
 * ── Assumptions ─────────────────────────────────────────────────────────────
 *   • Rows are "ragged": drab_build_export_model() emits rows of differing
 *     lengths. We size each <row> to its own cell count; the sheet dimension is
 *     the max width seen. Trailing nulls are simply not emitted.
 *   • Currency is plain numbers (no cell number-format applied) — the caller has
 *     already decided IDR vs raw; we keep the integer so the user can format in
 *     Excel. Large IDR integers are written verbatim (no thousands separators in
 *     the stored value).
 *   • Styling is intentionally minimal: a bold style for header rows. On a sheet
 *     with opts['summary'] the first two rows (project + building title) are bold;
 *     on every other sheet the first row (column headers) is bold.
 */

if (!class_exists('DrabXlsx')) {

class DrabXlsx
{
    /** @var array[] list of ['name'=>string, 'rows'=>array, 'summary'=>bool] */
    private $sheets = array();
    /** @var array used names (lower-cased) -> true, to keep sheet names unique */
    private $usedNames = array();

    public function __construct()
    {
        // nothing to set up; sheets are added incrementally
    }

    /**
     * Add a worksheet.
     *
     * @param string $title Desired tab name (will be sanitised, <=31 chars, unique).
     * @param array  $rows  Array of rows; each row an array of cells (string|int|float|null).
     * @param array  $opts  e.g. array('summary' => true).
     */
    public function addSheet($title, $rows, $opts = array())
    {
        $summary = !empty($opts['summary']);
        $this->sheets[] = array(
            'name'    => $this->sanitiseSheetName($title),
            'rows'    => is_array($rows) ? $rows : array(),
            'summary' => $summary,
        );
    }

    /**
     * Build the workbook and stream it to the client as a real .xlsx download.
     * Sends Content-Type + Content-Disposition headers. Caller may exit() after.
     *
     * @param string $filename Suggested download filename (e.g. "RAB-Villa-v1.xlsx").
     */
    public function stream($filename)
    {
        if (empty($this->sheets)) {
            // Always ship at least one sheet so the file is openable.
            $this->addSheet('Sheet1', array(array('')), array());
        }

        $bytes = $this->build();

        $safeName = $this->sanitiseFilename($filename);

        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: public');
        }

        echo $bytes;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Build: assemble the OOXML part tree and zip it.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return string Raw bytes of the .xlsx (a ZIP container).
     */
    public function build()
    {
        $sheetCount = count($this->sheets);
        $parts = array();

        // Fixed parts ------------------------------------------------------
        $parts['[Content_Types].xml'] = $this->contentTypesXml($sheetCount);
        $parts['_rels/.rels']         = $this->rootRelsXml();
        $parts['xl/workbook.xml']     = $this->workbookXml();
        $parts['xl/_rels/workbook.xml.rels'] = $this->workbookRelsXml($sheetCount);
        $parts['xl/styles.xml']       = $this->stylesXml();
        $parts['docProps/core.xml']   = $this->coreXml();
        $parts['docProps/app.xml']    = $this->appXml();

        // Worksheet parts --------------------------------------------------
        for ($i = 0; $i < $sheetCount; $i++) {
            $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] =
                $this->sheetXml($this->sheets[$i]);
        }

        return $this->zip($parts);
    }

    // ──────────────────────────────────────────────────────────────────────
    // ZIP assembly (ZipArchive into a temp file, then read back).
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array $parts map of archive-path => string-content
     * @return string ZIP bytes
     */
    private function zip($parts)
    {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'drabxlsx');
            if ($tmp === false) {
                // Fall back to the manual writer if we can't make a temp file.
                return $this->zipManual($parts);
            }
            $zip = new ZipArchive();
            // OVERWRITE because tempnam() already created an (empty) file.
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                @unlink($tmp);
                return $this->zipManual($parts);
            }
            foreach ($parts as $path => $content) {
                $zip->addFromString($path, $content);
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
            @unlink($tmp);
            if ($bytes === false) {
                return $this->zipManual($parts);
            }
            return $bytes;
        }
        return $this->zipManual($parts);
    }

    /**
     * Minimal pure-PHP ZIP writer (store, no compression) — only used if
     * ZipArchive is unavailable on the host. Produces a spec-valid ZIP that
     * Excel/LibreOffice accept.
     *
     * @param array $parts map of archive-path => string-content
     * @return string ZIP bytes
     */
    private function zipManual($parts)
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($parts as $path => $content) {
            $name = $this->zipPath($path);
            $crc = crc32($content);
            $len = strlen($content);

            // Local file header (PK\x03\x04), method 0 = stored.
            $lf  = "\x50\x4b\x03\x04";
            $lf .= pack('v', 20);             // version needed
            $lf .= pack('v', 0);              // flags
            $lf .= pack('v', 0);              // method: stored
            $lf .= pack('v', 0);             // mod time
            $lf .= pack('v', 0x21);          // mod date (arbitrary, 1980+)
            $lf .= pack('V', $crc);
            $lf .= pack('V', $len);          // compressed size
            $lf .= pack('V', $len);          // uncompressed size
            $lf .= pack('v', strlen($name));
            $lf .= pack('v', 0);             // extra len
            $lf .= $name;

            $localRecord = $lf . $content;
            $local .= $localRecord;

            // Central directory header (PK\x01\x02).
            $cd  = "\x50\x4b\x01\x02";
            $cd .= pack('v', 20);            // version made by
            $cd .= pack('v', 20);            // version needed
            $cd .= pack('v', 0);             // flags
            $cd .= pack('v', 0);             // method
            $cd .= pack('v', 0);            // mod time
            $cd .= pack('v', 0x21);         // mod date
            $cd .= pack('V', $crc);
            $cd .= pack('V', $len);
            $cd .= pack('V', $len);
            $cd .= pack('v', strlen($name));
            $cd .= pack('v', 0);            // extra
            $cd .= pack('v', 0);            // comment
            $cd .= pack('v', 0);            // disk number
            $cd .= pack('v', 0);            // internal attrs
            $cd .= pack('V', 0);            // external attrs
            $cd .= pack('V', $offset);      // offset of local header
            $cd .= $name;

            $central .= $cd;
            $offset += strlen($localRecord);
        }

        $count = count($parts);
        $eocd  = "\x50\x4b\x05\x06";
        $eocd .= pack('v', 0);              // this disk
        $eocd .= pack('v', 0);              // central dir disk
        $eocd .= pack('v', $count);         // entries this disk
        $eocd .= pack('v', $count);         // total entries
        $eocd .= pack('V', strlen($central));
        $eocd .= pack('V', strlen($local)); // central dir offset
        $eocd .= pack('v', 0);              // comment length

        return $local . $central . $eocd;
    }

    private function zipPath($path)
    {
        // ZIP entries always use forward slashes.
        return str_replace('\\', '/', $path);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Worksheet XML
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array $sheet array('name'=>..,'rows'=>..,'summary'=>bool)
     * @return string sheetN.xml content
     */
    private function sheetXml($sheet)
    {
        $rows = $sheet['rows'];
        $summary = $sheet['summary'];

        // Determine grid extent for the <dimension> hint.
        $maxCols = 1;
        $rowCount = count($rows);
        foreach ($rows as $r) {
            $n = is_array($r) ? count($r) : 1;
            if ($n > $maxCols) $maxCols = $n;
        }
        if ($rowCount < 1) $rowCount = 1;

        // Which rows are "bold" headers.
        //  - summary sheet: rows 1 & 2 (project name + building/title line)
        //  - other sheets:  row 1 (column header)
        $boldRows = $summary ? array(1 => true, 2 => true) : array(1 => true);

        $sb = array();
        $sb[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sb[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $sb[] = '<dimension ref="A1:' . $this->colLetter($maxCols) . $rowCount . '"/>';
        $sb[] = '<sheetViews><sheetView workbookViewId="0">'
              . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
              . '</sheetView></sheetViews>';
        $sb[] = '<sheetFormatPr defaultRowHeight="15"/>';
        // Reasonable column widths: a wide Description column (col B) helps readability.
        $sb[] = $this->colsXml($maxCols);
        $sb[] = '<sheetData>';

        $rowIndex = 0;
        foreach ($rows as $row) {
            $rowIndex++;
            if (!is_array($row)) {
                $row = array($row);
            }
            $isBold = isset($boldRows[$rowIndex]);
            $sb[] = $this->rowXml($rowIndex, $row, $isBold);
        }
        if ($rowCount === 1 && empty($rows)) {
            // ensure at least one (empty) row so the file is well-formed
            $sb[] = '<row r="1"/>';
        }

        $sb[] = '</sheetData>';
        $sb[] = '</worksheet>';
        return implode('', $sb);
    }

    /**
     * @param int   $rowIndex 1-based row number
     * @param array $cells    cell values
     * @param bool  $bold     apply the bold style to non-empty cells
     * @return string <row>...</row>
     */
    private function rowXml($rowIndex, $cells, $bold)
    {
        $out = '<row r="' . $rowIndex . '">';
        $colIndex = 0;
        foreach ($cells as $value) {
            $colIndex++;
            // Skip trailing-style empty cells entirely? No — keep position
            // integrity for ragged rows by emitting only non-null cells but
            // with explicit references so columns line up.
            if ($value === null || $value === '') {
                continue;
            }
            $ref = $this->colLetter($colIndex) . $rowIndex;
            $out .= $this->cellXml($ref, $value, $bold);
        }
        $out .= '</row>';
        return $out;
    }

    /**
     * @param string $ref   e.g. "B7"
     * @param mixed  $value cell value
     * @param bool   $bold  use bold style index
     * @return string <c .../>
     */
    private function cellXml($ref, $value, $bold)
    {
        $styleAttr = $bold ? ' s="1"' : '';

        if ($this->cellIsNumeric($value)) {
            // Numeric: store the canonical number, no type attr (default = "n").
            $num = $this->canonicalNumber($value);
            return '<c r="' . $ref . '"' . $styleAttr . '><v>' . $num . '</v></c>';
        }

        // Text: inline string, XML-escaped. xml:space="preserve" keeps the
        // leading spaces drab_build_export_model() uses to indent take-off rows.
        $text = $this->xmlEscape((string)$value);
        return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr">'
             . '<is><t xml:space="preserve">' . $text . '</t></is></c>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Number vs text detection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * True only when $value should be written as an Excel number.
     * See the file header for the rationale. Conservative on purpose: an
     * identifier-like string (leading zeros, "1.10", "+5", hex, whitespace,
     * exponent) stays text so it is never silently re-formatted by Excel.
     *
     * @param mixed $value
     * @return bool
     */
    private function cellIsNumeric($value)
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_bool($value) || $value === null) {
            return false;
        }
        if (!is_string($value)) {
            return false;
        }

        $s = $value;
        if ($s === '') {
            return false;
        }
        // Must be a plain numeric string with no surrounding whitespace,
        // sign-prefix oddities, exponents, hex, or leading-zero codes.
        if (!is_numeric($s)) {
            return false;
        }
        // is_numeric() accepts "0x1A", "1e3", " 5", "+5" on some PHP builds —
        // reject anything that doesn't match a clean decimal literal.
        if (!preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $s)) {
            return false;
        }
        // Final guard: a numeric STRING is only a number if it round-trips to
        // its own canonical form. This keeps version/identifier strings like
        // "1.10" or "2.0" as text (canonical "1.1"/"2" != original) — losing the
        // trailing zero would change their meaning — while genuine literals such
        // as "48", "32.5" or "56160000" stay numbers. (Real int/float values
        // emitted by drab_build_export_model are handled above and unaffected.)
        if ($this->canonicalNumber($s) !== $s) {
            return false;
        }
        return true;
    }

    /**
     * Canonical numeric literal for the <v> node. Integers print without a
     * decimal point; floats print with PHP's full precision but trimmed of
     * trailing zeros, and always with a leading digit. Locale-independent
     * (uses '.' as the decimal separator regardless of PHP locale).
     *
     * @param int|float|string $value
     * @return string
     */
    private function canonicalNumber($value)
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            // Already validated by cellIsNumeric()'s regex: safe to emit as-is,
            // but normalise an integer-looking string and trim a redundant '.0'.
            if (strpos($value, '.') === false) {
                return $value;
            }
            $v = rtrim(rtrim($value, '0'), '.');
            return $v === '' || $v === '-' ? '0' : $v;
        }
        // float: format with enough precision, locale-safe, then trim.
        $s = rtrim(rtrim(sprintf('%.10F', (float)$value), '0'), '.');
        if ($s === '' || $s === '-' || $s === '-0') {
            $s = '0';
        }
        return $s;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Static workbook parts
    // ──────────────────────────────────────────────────────────────────────

    private function contentTypesXml($sheetCount)
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $x .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $x .= '<Default Extension="xml" ContentType="application/xml"/>';
        $x .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $x .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $x .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $x .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $x .= '</Types>';
        return $x;
    }

    private function rootRelsXml()
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $x .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $x .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $x .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';
        $x .= '</Relationships>';
        return $x;
    }

    private function workbookXml()
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheets>';
        $n = count($this->sheets);
        for ($i = 0; $i < $n; $i++) {
            $sheetId = $i + 1;
            $name = $this->xmlEscape($this->sheets[$i]['name']);
            // r:id points at the relationship in workbook.xml.rels (rId1 = sheet1).
            $x .= '<sheet name="' . $name . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        }
        $x .= '</sheets>';
        $x .= '</workbook>';
        return $x;
    }

    private function workbookRelsXml($sheetCount)
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $x .= '<Relationship Id="rId' . $i . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $i . '.xml"/>';
        }
        // styles relationship gets the id after the sheets
        $styleId = $sheetCount + 1;
        $x .= '<Relationship Id="rId' . $styleId . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';
        $x .= '</Relationships>';
        return $x;
    }

    /**
     * Minimal styles.xml: index 0 = default (Normal), index 1 = bold.
     * Both share the default number format (General) so currency-ish integers
     * stay plain numbers the user can format in Excel.
     */
    private function stylesXml()
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        // Two fonts: 0 = normal, 1 = bold.
        $x .= '<fonts count="2">';
        $x .= '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>';
        $x .= '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>';
        $x .= '</fonts>';
        // One (default) fill set is required; provide the two reserved fills.
        $x .= '<fills count="2">';
        $x .= '<fill><patternFill patternType="none"/></fill>';
        $x .= '<fill><patternFill patternType="gray125"/></fill>';
        $x .= '</fills>';
        $x .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        // cellXfs: 0 = normal, 1 = bold (fontId 1).
        $x .= '<cellXfs count="2">';
        $x .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $x .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
        $x .= '</cellXfs>';
        $x .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $x .= '</styleSheet>';
        return $x;
    }

    private function coreXml()
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<cp:coreProperties '
            . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $x .= '<dc:creator>Build in Lombok</dc:creator>';
        $x .= '<cp:lastModifiedBy>Build in Lombok</cp:lastModifiedBy>';
        $x .= '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>';
        $x .= '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>';
        $x .= '</cp:coreProperties>';
        return $x;
    }

    private function appXml()
    {
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<Properties '
            . 'xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">';
        $x .= '<Application>Build in Lombok RAB</Application>';
        $x .= '</Properties>';
        return $x;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Column helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Provide sensible column widths. Column B (Description) is widened; the
     * rest get a comfortable default. Width is in Excel "character" units.
     */
    private function colsXml($maxCols)
    {
        if ($maxCols < 1) $maxCols = 1;
        $out = '<cols>';
        // Column A (Ref / No.) narrow-ish.
        $out .= '<col min="1" max="1" width="10" customWidth="1"/>';
        if ($maxCols >= 2) {
            // Column B (Description) wide.
            $out .= '<col min="2" max="2" width="46" customWidth="1"/>';
        }
        if ($maxCols >= 3) {
            // Remaining columns: roomy numeric columns.
            $out .= '<col min="3" max="' . $maxCols . '" width="16" customWidth="1"/>';
        }
        $out .= '</cols>';
        return $out;
    }

    /**
     * 1-based column index -> spreadsheet column letters (1 -> A, 27 -> AA).
     *
     * @param int $index
     * @return string
     */
    private function colLetter($index)
    {
        $index = (int)$index;
        if ($index < 1) $index = 1;
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index = (int)(($index - $mod) / 26);
        }
        return $letters;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sanitisation / escaping
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Excel sheet-name rules: <=31 chars; cannot contain []:*?/\ ; cannot be
     * blank; cannot start/end with an apostrophe; must be unique (case-
     * insensitively) within the workbook.
     *
     * @param string $title
     * @return string
     */
    private function sanitiseSheetName($title)
    {
        $name = (string)$title;
        // Strip the forbidden characters.
        $name = str_replace(array('[', ']', ':', '*', '?', '/', '\\'), ' ', $name);
        // Collapse control chars / newlines to spaces.
        $name = preg_replace('/[\x00-\x1F]+/', ' ', $name);
        // Apostrophes are allowed inside, just not at the edges.
        $name = trim($name);
        $name = trim($name, "'");
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet';
        }
        // Multi-byte safe truncation to 31 characters.
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 31, 'UTF-8');
        } else {
            $name = substr($name, 0, 31);
        }
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet';
        }

        // Ensure uniqueness (case-insensitive), keeping within 31 chars.
        $base = $name;
        $key = $this->ciKey($name);
        $n = 1;
        while (isset($this->usedNames[$key])) {
            $n++;
            $suffix = ' (' . $n . ')';
            $room = 31 - strlen($suffix);
            if ($room < 1) $room = 1;
            if (function_exists('mb_substr')) {
                $trimmed = mb_substr($base, 0, $room, 'UTF-8');
            } else {
                $trimmed = substr($base, 0, $room);
            }
            $trimmed = trim($trimmed);
            if ($trimmed === '') $trimmed = 'Sheet';
            $name = $trimmed . $suffix;
            $key = $this->ciKey($name);
        }
        $this->usedNames[$key] = true;
        return $name;
    }

    private function ciKey($s)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        return strtolower($s);
    }

    /**
     * Make a download filename safe for the Content-Disposition header.
     */
    private function sanitiseFilename($filename)
    {
        $f = (string)$filename;
        // Drop directory separators and quotes / control chars.
        $f = str_replace(array('"', '\\', '/', "\r", "\n"), '', $f);
        $f = preg_replace('/[\x00-\x1F]+/', '', $f);
        $f = trim($f);
        if ($f === '') {
            $f = 'export.xlsx';
        }
        if (substr(strtolower($f), -5) !== '.xlsx') {
            $f .= '.xlsx';
        }
        return $f;
    }

    /**
     * XML-escape text for element content / attribute values. Also strips the
     * characters that are illegal in XML 1.0 (control chars except tab/CR/LF),
     * which can otherwise corrupt the whole worksheet part.
     *
     * @param string $s
     * @return string
     */
    private function xmlEscape($s)
    {
        $s = (string)$s;
        // Remove XML-1.0-illegal control characters (keep \t \n \r).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace(
            array('&', '<', '>', '"', "'"),
            array('&amp;', '&lt;', '&gt;', '&quot;', '&apos;'),
            $s
        );
    }
}

} // class_exists guard
