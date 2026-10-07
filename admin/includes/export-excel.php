<?php


declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/* =========================================================
   CLEAN OUTPUT BUFFER
========================================================= */
while (ob_get_level()) {
    ob_end_clean();
}

/* =========================================================
   PURE PHP ZIP WRITER
========================================================= */
class PureZip
{
    private array $files = [];
    private string $central = '';
    private string $data = '';

    public function add(string $name, string $content): void
    {
        $usize = strlen($content);
        $crc = crc32($content);
        $deflated = gzdeflate($content, 6);
        $csize = strlen($deflated);
        $offset = strlen($this->data);

        $lfh  = pack('V', 0x04034b50);
        $lfh .= pack('v', 20);
        $lfh .= pack('v', 0);
        $lfh .= pack('v', 8);
        $lfh .= pack('v', 0);
        $lfh .= pack('v', 0);
        $lfh .= pack('V', $crc);
        $lfh .= pack('V', $csize);
        $lfh .= pack('V', $usize);
        $lfh .= pack('v', strlen($name));
        $lfh .= pack('v', 0);
        $lfh .= $name;

        $this->data .= $lfh . $deflated;

        $cde  = pack('V', 0x02014b50);
        $cde .= pack('v', 20);
        $cde .= pack('v', 20);
        $cde .= pack('v', 0);
        $cde .= pack('v', 8);
        $cde .= pack('v', 0);
        $cde .= pack('v', 0);
        $cde .= pack('V', $crc);
        $cde .= pack('V', $csize);
        $cde .= pack('V', $usize);
        $cde .= pack('v', strlen($name));
        $cde .= pack('v', 0);
        $cde .= pack('v', 0);
        $cde .= pack('v', 0);
        $cde .= pack('v', 0);
        $cde .= pack('V', 0);
        $cde .= pack('V', $offset);
        $cde .= $name;

        $this->central .= $cde;
        $this->files[] = $name;
    }

    public function build(): string
    {
        $cdOffset = strlen($this->data);
        $cdSize = strlen($this->central);
        $count = count($this->files);

        $eocd  = pack('V', 0x06054b50);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', $count);
        $eocd .= pack('v', $count);
        $eocd .= pack('V', $cdSize);
        $eocd .= pack('V', $cdOffset);
        $eocd .= pack('v', 0);

        return $this->data . $this->central . $eocd;
    }
}

/* =========================================================
   XLSX WRITER
========================================================= */
class XlsxWriter
{
    public array $sheets = [];
    public array $sheetMeta = [];

    private array $styles = [];
    private array $sharedStr = [];
    private array $ssIndex = [];

    public int $S_DEFAULT;
    public int $S_HEADER_DARK;
    public int $S_HEADER_ORANGE;
    public int $S_HEADER_MID;
    public int $S_LABEL;
    public int $S_DATA;
    public int $S_DATA_ALT;
    public int $S_DATA_CENTER;
    public int $S_GREEN;
    public int $S_AMBER;
    public int $S_RED;
    public int $S_GRAY;
    public int $S_BOLD_CENTER;
    public int $S_SUMMARY;
    public int $S_IMP_H;
    public int $S_IMP_M;
    public int $S_IMP_L;
    public int $S_TOTAL;

    public function __construct()
    {
        $this->buildStyles();
    }

    private function buildStyles(): void
    {
        $this->S_DEFAULT       = $this->addStyle([]);
        $this->S_HEADER_DARK   = $this->addStyle(['bg'=>'0F1117','fg'=>'FFFFFF','bold'=>true,'sz'=>11,'ha'=>'center','va'=>'center','wrap'=>true]);
        $this->S_HEADER_ORANGE = $this->addStyle(['bg'=>'FC7F10','fg'=>'FFFFFF','bold'=>true,'sz'=>10,'ha'=>'center','va'=>'center','wrap'=>true]);
        $this->S_HEADER_MID    = $this->addStyle(['bg'=>'1E293B','fg'=>'CBD5E1','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'center','wrap'=>true]);
        $this->S_LABEL         = $this->addStyle(['bold'=>true,'sz'=>9,'ha'=>'left','va'=>'top','border'=>true]);
        $this->S_DATA          = $this->addStyle(['sz'=>9,'ha'=>'left','va'=>'top','wrap'=>true,'border'=>true]);
        $this->S_DATA_ALT      = $this->addStyle(['bg'=>'F1F5F9','sz'=>9,'ha'=>'left','va'=>'top','wrap'=>true,'border'=>true]);
        $this->S_DATA_CENTER   = $this->addStyle(['sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_GREEN         = $this->addStyle(['bg'=>'DCFCE7','fg'=>'16A34A','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_AMBER         = $this->addStyle(['bg'=>'FEF3C7','fg'=>'D97706','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_RED           = $this->addStyle(['bg'=>'FEE2E2','fg'=>'DC2626','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_GRAY          = $this->addStyle(['bg'=>'F3F4F6','fg'=>'9CA3AF','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_BOLD_CENTER   = $this->addStyle(['bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_SUMMARY       = $this->addStyle(['bg'=>'F1F5F9','bold'=>true,'sz'=>9,'ha'=>'left','va'=>'top','border'=>true]);
        $this->S_IMP_H         = $this->addStyle(['bg'=>'FEE2E2','fg'=>'DC2626','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_IMP_M         = $this->addStyle(['bg'=>'FEF3C7','fg'=>'D97706','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_IMP_L         = $this->addStyle(['bg'=>'DCFCE7','fg'=>'16A34A','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
        $this->S_TOTAL         = $this->addStyle(['bg'=>'0F1117','fg'=>'FFFFFF','bold'=>true,'sz'=>9,'ha'=>'center','va'=>'top','border'=>true]);
    }

    public function addStyle(array $s): int
    {
        $this->styles[] = $s;
        return count($this->styles) - 1;
    }

    public function addSheet(string $name, array $colWidths = [], int $freezeRow = 0): void
    {
        $safe = $this->safeName($name);

        if (isset($this->sheets[$safe])) {
            $safe = substr($safe, 0, 25) . '_' . (count($this->sheets) + 1);
        }

        $this->sheets[$safe] = [];
        $this->sheetMeta[$safe] = [
            'colWidths' => $colWidths,
            'freezeRow' => $freezeRow,
            'merges' => [],
            'rowHeights' => []
        ];
    }

    public function addRow(string $sheet, array $cells, float $height = 0): void
    {
        $safe = $this->safeName($sheet);

        if (!isset($this->sheets[$safe])) {
            $this->addSheet($safe);
        }

        $ri = count($this->sheets[$safe]);
        $this->sheets[$safe][] = $cells;

        if ($height > 0) {
            $this->sheetMeta[$safe]['rowHeights'][$ri] = $height;
        }
    }

    public function merge(string $sheet, int $r, int $c, int $rows, int $cols): void
    {
        $safe = $this->safeName($sheet);

        if (!isset($this->sheetMeta[$safe])) {
            return;
        }

        $this->sheetMeta[$safe]['merges'][] = [$r, $c, $rows, $cols];
    }

    private function safeName(string $n): string
    {
        $n = trim($n);
        $n = preg_replace('/[\/\\\\\?\*\[\]:]/', '_', $n);
        $n = $n === '' ? 'Sheet' : $n;

        return mb_substr($n, 0, 31);
    }

    private function ss(string $v): int
    {
        if (!isset($this->ssIndex[$v])) {
            $this->ssIndex[$v] = count($this->sharedStr);
            $this->sharedStr[] = $v;
        }

        return $this->ssIndex[$v];
    }

    private static function col(int $n): string
    {
        $s = '';

        for ($n++; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }

        return $s;
    }

    private static function ref(int $c, int $r): string
    {
        return self::col($c) . ($r + 1);
    }

    public function output(string $filename): void
    {
        $zip = new PureZip();
        $names = array_keys($this->sheets);

        if (empty($names)) {
            $this->addSheet('Empty');
            $this->addRow('Empty', [['No data available', $this->S_DATA]]);
            $names = array_keys($this->sheets);
        }

        /*
         * CRITICAL FIX:
         * Build worksheet XML first because buildSheetXml() calls ss(),
         * which fills shared strings. If sharedStrings.xml is created before
         * this, Excel receives empty strings and displays blank sheets.
         */
        $sheetXmlFiles = [];

        foreach ($names as $i => $nm) {
            $sheetXmlFiles['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->buildSheetXml($nm);
        }

        $ssXml = $this->buildSharedStringsXml();
        $wbXml = $this->buildWorkbookXml($names);
        $wbRels = $this->buildWorkbookRelsXml($names);
        $ct = $this->buildContentTypesXml($names);
        $rels = $this->buildRootRelsXml();

        $zip->add('[Content_Types].xml', $ct);
        $zip->add('_rels/.rels', $rels);
        $zip->add('xl/workbook.xml', $wbXml);
        $zip->add('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->add('xl/styles.xml', $this->buildStylesXml());
        $zip->add('xl/sharedStrings.xml', $ssXml);

        foreach ($sheetXmlFiles as $path => $xml) {
            $zip->add($path, $xml);
        }

        $out = $zip->build();

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . strlen($out));
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Expires: 0');

        echo $out;
        exit;
    }

    private function buildSharedStringsXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $xml .= ' count="' . count($this->sharedStr) . '"';
        $xml .= ' uniqueCount="' . count($this->sharedStr) . '">';

        foreach ($this->sharedStr as $s) {
            $xml .= '<si><t xml:space="preserve">' .
                htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8') .
                '</t></si>';
        }

        $xml .= '</sst>';

        return $xml;
    }

    private function buildWorkbookXml(array $names): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $xml .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';

        foreach ($names as $i => $nm) {
            $xml .= '<sheet name="' . htmlspecialchars($nm, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 3) . '"/>';
        }

        $xml .= '</sheets></workbook>';

        return $xml;
    }

    private function buildWorkbookRelsXml(array $names): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        foreach ($names as $i => $nm) {
            $xml .= '<Relationship Id="rId' . ($i + 3) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        $xml .= '</Relationships>';

        return $xml;
    }

    private function buildContentTypesXml(array $names): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.stylesheet+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        foreach ($names as $i => $nm) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $xml .= '</Types>';

        return $xml;
    }

    private function buildRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildSheetXml(string $name): string
    {
        $meta = $this->sheetMeta[$name];
        $rows = $this->sheets[$name];

        $mergeMap = [];
        $mergeCells = [];

        foreach ($meta['merges'] as [$mr, $mc, $mrows, $mcols]) {
            $mergeMap[$mr][$mc] = $mcols;

            for ($ri = $mr; $ri < $mr + $mrows; $ri++) {
                for ($ci = $mc; $ci < $mc + $mcols; $ci++) {
                    if ($ri === $mr && $ci === $mc) {
                        continue;
                    }

                    $mergeMap[$ri][$ci] = -1;
                }
            }

            $mergeCells[] = self::ref($mc, $mr) . ':' . self::ref($mc + $mcols - 1, $mr + $mrows - 1);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $xml .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if (($meta['freezeRow'] ?? 0) > 0) {
            $freezeRow = (int)$meta['freezeRow'];
            $xml .= '<sheetViews><sheetView workbookViewId="0">';
            $xml .= '<pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/>';
            $xml .= '</sheetView></sheetViews>';
        }

        if (!empty($meta['colWidths'])) {
            $xml .= '<cols>';

            foreach ($meta['colWidths'] as $ci => $w) {
                $xml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }

            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        foreach ($rows as $ri => $cells) {
            $ht = $meta['rowHeights'][$ri] ?? 0;

            $xml .= '<row r="' . ($ri + 1) . '"';

            if ($ht > 0) {
                $xml .= ' ht="' . (float)$ht . '" customHeight="1"';
            }

            $xml .= '>';

            $ci = 0;

            foreach ($cells as $cell) {
                while (isset($mergeMap[$ri][$ci]) && $mergeMap[$ri][$ci] === -1) {
                    $ci++;
                }

                if (is_array($cell)) {
                    $val = $cell[0] ?? '';
                    $styleIdx = (int)($cell[1] ?? 0);
                } else {
                    $val = $cell;
                    $styleIdx = 0;
                }

                $ref = self::ref($ci, $ri);
                $sAttr = ' s="' . $styleIdx . '"';

                if ($val === null || $val === '') {
                    $xml .= '<c r="' . $ref . '"' . $sAttr . '/>';
                } elseif (is_int($val) || is_float($val)) {
                    $xml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $val . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="s"' . $sAttr . '><v>' . $this->ss((string)$val) . '</v></c>';
                }

                $ci++;
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if (!empty($mergeCells)) {
            $xml .= '<mergeCells count="' . count($mergeCells) . '">';

            foreach ($mergeCells as $m) {
                $xml .= '<mergeCell ref="' . $m . '"/>';
            }

            $xml .= '</mergeCells>';
        }

        $xml .= '</worksheet>';

        return $xml;
    }

    private function buildStylesXml(): string
    {
        $fonts = [];
        $fills = ['__none__', '__gray125__'];
        $fontIdx = [];
        $fillIdx = [];

        foreach ($this->styles as $s) {
            $fKey = ($s['bold'] ?? false ? '1' : '0') . '|' . ($s['sz'] ?? 9) . '|' . ($s['fg'] ?? '000000');

            if (!isset($fontIdx[$fKey])) {
                $fontIdx[$fKey] = count($fonts);
                $fonts[] = $s;
            }

            $bg = $s['bg'] ?? '';

            if (!isset($fillIdx[$bg])) {
                $fillIdx[$bg] = count($fills);
                $fills[] = $bg;
            }
        }

        $fontsXml = '<fonts count="' . count($fonts) . '">';

        foreach ($fonts as $f) {
            $fontsXml .= '<font>';
            $fontsXml .= '<sz val="' . ($f['sz'] ?? 9) . '"/>';
            $fontsXml .= ($f['bold'] ?? false) ? '<b/>' : '';
            $fontsXml .= '<name val="Arial"/>';
            $fontsXml .= '<color rgb="FF' . ltrim($f['fg'] ?? '000000', '#') . '"/>';
            $fontsXml .= '</font>';
        }

        $fontsXml .= '</fonts>';

        $fillsXml = '<fills count="' . count($fills) . '">';
        $fillsXml .= '<fill><patternFill patternType="none"/></fill>';
        $fillsXml .= '<fill><patternFill patternType="gray125"/></fill>';

        foreach (array_slice($fills, 2) as $bg) {
            if ($bg === '') {
                $fillsXml .= '<fill><patternFill patternType="none"/></fill>';
            } else {
                $fillsXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . ltrim($bg, '#') . '"/></patternFill></fill>';
            }
        }

        $fillsXml .= '</fills>';

        $bordersXml = '<borders count="2">';
        $bordersXml .= '<border><left/><right/><top/><bottom/><diagonal/></border>';
        $bordersXml .= '<border>';
        $bordersXml .= '<left style="thin"><color rgb="FFE5E7EB"/></left>';
        $bordersXml .= '<right style="thin"><color rgb="FFE5E7EB"/></right>';
        $bordersXml .= '<top style="thin"><color rgb="FFE5E7EB"/></top>';
        $bordersXml .= '<bottom style="thin"><color rgb="FFE5E7EB"/></bottom>';
        $bordersXml .= '<diagonal/>';
        $bordersXml .= '</border>';
        $bordersXml .= '</borders>';

        $xfsXml = '<cellXfs count="' . count($this->styles) . '">';

        foreach ($this->styles as $s) {
            $fKey = ($s['bold'] ?? false ? '1' : '0') . '|' . ($s['sz'] ?? 9) . '|' . ($s['fg'] ?? '000000');
            $fi = $fontIdx[$fKey] ?? 0;
            $bg = $s['bg'] ?? '';
            $filli = $fillIdx[$bg] ?? 0;
            $bord = ($s['border'] ?? false) ? 1 : 0;

            $hasFill = $bg !== '';
            $hasAlign = isset($s['ha']) || isset($s['va']) || ($s['wrap'] ?? false);

            $xfsXml .= '<xf numFmtId="0" fontId="' . $fi . '" fillId="' . $filli . '" borderId="' . $bord . '"';
            $xfsXml .= ' applyFont="1" applyBorder="1"';

            if ($hasFill) {
                $xfsXml .= ' applyFill="1"';
            }

            if ($hasAlign) {
                $xfsXml .= ' applyAlignment="1"';
            }

            $xfsXml .= '>';

            if ($hasAlign) {
                $xfsXml .= '<alignment';

                if (isset($s['ha'])) {
                    $xfsXml .= ' horizontal="' . $s['ha'] . '"';
                }

                if (isset($s['va'])) {
                    $xfsXml .= ' vertical="' . $s['va'] . '"';
                }

                if ($s['wrap'] ?? false) {
                    $xfsXml .= ' wrapText="1"';
                }

                $xfsXml .= '/>';
            }

            $xfsXml .= '</xf>';
        }

        $xfsXml .= '</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $fontsXml
            . $fillsXml
            . $bordersXml
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . $xfsXml
            . '</styleSheet>';
    }
}

/* =========================================================
   DATA FETCH
========================================================= */
$type = $_GET['type'] ?? 'all';
$venture_id = (int)($_GET['venture_id'] ?? 0);

$ventures = [];
$vr = $conn->query("SELECT * FROM ventures ORDER BY name ASC");

if ($vr) {
    while ($r = $vr->fetch_assoc()) {
        $ventures[(int)$r['id']] = $r;
    }
}

$analyses = [];
$analysisMap = [];

$ar = $conn->query("
    SELECT ga.*, v.name AS venture_name
    FROM gap_analyses ga
    LEFT JOIN ventures v ON v.id = ga.venture_id
    ORDER BY ga.venture_id ASC, ga.analysis_date DESC, ga.id DESC
");

if ($ar) {
    while ($r = $ar->fetch_assoc()) {
        $analyses[] = $r;

        $vid = (int)$r['venture_id'];

        if (!isset($analysisMap[$vid])) {
            $analysisMap[$vid] = $r;
        }
    }
}

$all_gaps = [];

$gr = $conn->query("
    SELECT 
        g.*,
        ga.venture_id,
        ga.analyst_name,
        ga.analysis_date,
        v.name AS venture_name
    FROM gap_items g
    INNER JOIN gap_analyses ga ON ga.id = g.analysis_id
    LEFT JOIN ventures v ON v.id = ga.venture_id
    ORDER BY ga.venture_id ASC, g.priority_score ASC, g.created_at DESC
");

if ($gr) {
    while ($r = $gr->fetch_assoc()) {
        $all_gaps[] = $r;
    }
}

$categories = [
    'business_model'     => 'Business Model',
    'pedagogy'           => 'Pedagogy & Curriculum',
    'technology'         => 'Technology',
    'marketing_sales'    => 'Marketing & Sales',
    'team_talent'        => 'Team & Talent',
    'finance'            => 'Finance',
    'monitoring_eval'    => 'M&E / Reporting',
    'scale_partnerships' => 'Scale & Partnerships',
    'sops'               => 'SOPs & Safeguarding',
];

/* =========================================================
   HELPERS
========================================================= */
function xlsx_status_style(XlsxWriter $w, string $status): int
{
    return match ($status) {
        'resolved' => $w->S_GREEN,
        'in_progress' => $w->S_AMBER,
        'not_started' => $w->S_RED,
        default => $w->S_GRAY,
    };
}

function xlsx_status_label(string $status): string
{
    return match ($status) {
        'resolved' => 'Resolved',
        'in_progress' => 'In Progress',
        'not_started' => 'Not Started',
        default => 'Not Started',
    };
}

function xlsx_impact_style(XlsxWriter $w, string $v): int
{
    return match ($v) {
        'H' => $w->S_IMP_H,
        'M' => $w->S_IMP_M,
        default => $w->S_IMP_L,
    };
}

function xlsx_pct_status(int $pct): array
{
    if ($pct >= 70) {
        return ['On Track'];
    }

    if ($pct >= 40) {
        return ['In Progress'];
    }

    return ['Needs Attention'];
}

function xlsx_short(?string $text, int $limit = 60): string
{
    $text = trim((string)$text);

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

/* =========================================================
   BUILD VENTURE SHEET
========================================================= */
function buildVentureSheet(
    XlsxWriter $w,
    string $sheetName,
    array $venture,
    array $gaps,
    array $categories,
    ?array $analysis
): void {
    $name = $venture['name'] ?? 'Venture';

    $colWidths = [4,22,32,28,8,8,8,28,20,8,22,14,9,18,12,16,10,12,20];

    $w->addSheet($sheetName, $colWidths, 13);
    $S = $w;

    $w->addRow($sheetName, [[strtoupper((string)$name) . ' | Gap Analysis | EdTech Fellowship Program | Mastercard Foundation', $S->S_HEADER_DARK]], 28);
    $w->merge($sheetName, 0, 0, 1, 19);

    $w->addRow($sheetName, [['One section per gap category - Summary row + detailed gap rows below', $S->S_HEADER_MID]], 18);
    $w->merge($sheetName, 1, 0, 1, 19);

    $w->addRow($sheetName, [['', $S->S_DEFAULT]], 6);

    $w->addRow($sheetName, [['VENTURE INFORMATION', $S->S_HEADER_ORANGE]], 22);
    $w->merge($sheetName, 3, 0, 1, 19);

    $infoRows = [
        ['Venture Name:', $name, 'Stage / Phase:', $analysis['stage'] ?? ''],
        ['Founder(s):', $analysis['founders'] ?? '', 'Sector Focus:', $analysis['sector'] ?? 'EdTech'],
        ['Region:', $analysis['region'] ?? '', 'Analysis Date:', $analysis['analysis_date'] ?? ''],
        ['Analyst/Coach:', $analysis['analyst_name'] ?? '', 'Review Date:', $analysis['review_date'] ?? ''],
    ];

    foreach ($infoRows as $ir) {
        $w->addRow($sheetName, [
            [$ir[0], $S->S_LABEL],
            [$ir[1], $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA],
            [$ir[2], $S->S_LABEL],
            [$ir[3], $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA], ['', $S->S_DATA],
        ], 18);
    }

    foreach ([4,5,6,7] as $ri) {
        $w->merge($sheetName, $ri, 1, 1, 6);
        $w->merge($sheetName, $ri, 8, 1, 4);
    }

    $w->addRow($sheetName, [['', $S->S_DEFAULT]], 6);

    $w->addRow($sheetName, [['GAP CATEGORY SUMMARY - Overall status per category at a glance', $S->S_HEADER_DARK]], 22);
    $w->merge($sheetName, 9, 0, 1, 19);

    $sumHdrs = ['#','Category','','','Total','Resolved','Progress %','Top Gap','','Status','Notes'];

    $w->addRow($sheetName, array_map(fn($h) => [$h, $S->S_HEADER_MID], $sumHdrs), 18);

    $catNum = 1;

    foreach ($categories as $catKey => $catLabel) {
        $cg = array_values(array_filter($gaps, fn($g) => ($g['gap_category'] ?? '') === $catKey));

        $ct = count($cg);
        $cr = count(array_filter($cg, fn($g) => ($g['status'] ?? '') === 'resolved'));
        $cpct = $ct > 0 ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $cg)) / $ct) : 0;

        $topG = '';

        foreach ($cg as $cgi) {
            if (($cgi['impact'] ?? '') === 'H') {
                $topG = $cgi['challenge'] ?? '';
                break;
            }
        }

        if ($topG === '' && !empty($cg)) {
            $topG = $cg[0]['challenge'] ?? '';
        }

        [$slabel] = xlsx_pct_status($cpct);

        $sStyle = $cpct >= 70
            ? $S->S_GREEN
            : ($cpct >= 40 ? $S->S_AMBER : ($ct > 0 ? $S->S_RED : $S->S_GRAY));

        $rBefore = count($w->sheets[$sheetName]);

        $w->addRow($sheetName, [
            [$catNum++, $S->S_DATA_CENTER],
            [$catLabel, $S->S_LABEL],
            ['', $S->S_DATA],
            ['', $S->S_DATA],
            [$ct ?: '-', $S->S_DATA_CENTER],
            [$cr ?: '-', $S->S_DATA_CENTER],
            [$ct > 0 ? $cpct . '%' : '-', $S->S_DATA_CENTER],
            [xlsx_short($topG, 60), $S->S_DATA],
            ['', $S->S_DATA],
            [$ct > 0 ? $slabel : '-', $sStyle],
            ['', $S->S_DATA],
        ], 18);

        $w->merge($sheetName, $rBefore, 1, 1, 3);
        $w->merge($sheetName, $rBefore, 7, 1, 2);
    }

    $w->addRow($sheetName, [['', $S->S_DEFAULT]], 6);

    $colHdrs = [
        '#','Category','Gap / Challenge','Root Cause','Imp.','Urg.','Pri.',
        'Intervention','Responsible','Month','Resources','Status','%',
        'Evidence','Date Resolved','Outcome','Milestone','Verified','Notes'
    ];

    foreach ($categories as $catKey => $catLabel) {
        $cg = array_values(array_filter($gaps, fn($g) => ($g['gap_category'] ?? '') === $catKey));

        if (empty($cg)) {
            continue;
        }

        $ct = count($cg);
        $cr = count(array_filter($cg, fn($g) => ($g['status'] ?? '') === 'resolved'));
        $cpct = $ct > 0 ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $cg)) / $ct) : 0;

        $rSec = count($w->sheets[$sheetName]);

        $w->addRow($sheetName, [['GAP DETAILS - ' . strtoupper($catLabel), $S->S_HEADER_ORANGE]], 22);
        $w->merge($sheetName, $rSec, 0, 1, 19);

        $w->addRow($sheetName, array_map(fn($h) => [$h, $S->S_HEADER_MID], $colHdrs), 28);

        $rSum = count($w->sheets[$sheetName]);

        $w->addRow($sheetName, [
            ['?', $S->S_SUMMARY],
            ['SUMMARY - ' . $catLabel, $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            [$ct, $S->S_SUMMARY],
            [$cr, $S->S_SUMMARY],
            [$cpct . '%', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
            ['', $S->S_SUMMARY],
        ], 18);

        $w->merge($sheetName, $rSum, 1, 1, 2);

        foreach ($cg as $gi => $gap) {
            $alt = $gi % 2 !== 0;
            $dStyle = $alt ? $S->S_DATA_ALT : $S->S_DATA;

            $sStyle = xlsx_status_style($w, $gap['status'] ?? '');
            $iStyle = xlsx_impact_style($w, $gap['impact'] ?? '');
            $uStyle = xlsx_impact_style($w, $gap['urgency'] ?? '');

            $targetMonth = !empty($gap['target_month']) ? 'M' . (int)$gap['target_month'] : '';

            $w->addRow($sheetName, [
                [$gi + 1, $S->S_DATA_CENTER],
                [$catLabel, $dStyle],
                [$gap['challenge'] ?? '', $dStyle],
                [$gap['root_cause'] ?? '', $dStyle],
                [$gap['impact'] ?? '', $iStyle],
                [$gap['urgency'] ?? '', $uStyle],
                [$gap['priority_score'] ?? '', $S->S_DATA_CENTER],
                [$gap['intervention'] ?? '', $dStyle],
                [$gap['responsible'] ?? '', $dStyle],
                [$targetMonth, $S->S_DATA_CENTER],
                [$gap['resources'] ?? '', $dStyle],
                [xlsx_status_label($gap['status'] ?? ''), $sStyle],
                [isset($gap['pct_complete']) ? (int)$gap['pct_complete'] . '%' : '', $S->S_DATA_CENTER],
                [$gap['evidence'] ?? '', $dStyle],
                [$gap['date_resolved'] ?? '', $dStyle],
                [$gap['outcome_achieved'] ?? '', $dStyle],
                [$gap['linked_milestone'] ?? '', $S->S_DATA_CENTER],
                [$gap['verified_by'] ?? '', $dStyle],
                [$gap['notes'] ?? '', $dStyle],
            ], 22);
        }

        $w->addRow($sheetName, [['', $S->S_DEFAULT]], 6);
    }

    $totalGaps = count($gaps);
    $totalRes = count(array_filter($gaps, fn($g) => ($g['status'] ?? '') === 'resolved'));
    $inprog = count(array_filter($gaps, fn($g) => ($g['status'] ?? '') === 'in_progress'));

    $critical = count(array_filter($gaps, fn($g) =>
        ($g['impact'] ?? '') === 'H'
        && ($g['urgency'] ?? '') === 'H'
        && ($g['status'] ?? '') !== 'resolved'
    ));

    $overallPct = $totalGaps > 0
        ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $gaps)) / $totalGaps)
        : 0;

    $rFoot = count($w->sheets[$sheetName]);

    $w->addRow($sheetName, [['OVERALL VENTURE GAP SUMMARY', $S->S_HEADER_DARK]], 22);
    $w->merge($sheetName, $rFoot, 0, 1, 19);

    $footerRows = [
        ['Total Gaps Identified:', $totalGaps],
        ['Gaps Resolved:', $totalRes],
        ['Gaps In Progress:', $inprog],
        ['Gaps Not Started:', $totalGaps - $totalRes - $inprog],
        ['Critical Gaps (HxH):', $critical],
        ['Overall Progress %:', $overallPct . '%'],
        ['Key Risk:', $analysis['key_risk'] ?? ''],
        ['Next Action:', $analysis['next_action'] ?? ''],
        ['Next Review Date:', $analysis['review_date'] ?? ''],
    ];

    foreach ($footerRows as $sl) {
        $rF = count($w->sheets[$sheetName]);

        $w->addRow($sheetName, [
            [$sl[0], $S->S_LABEL],
            [$sl[1], $S->S_DATA],
            ['', $S->S_DATA],
            ['', $S->S_DATA],
        ], 18);

        $w->merge($sheetName, $rF, 1, 1, 4);
    }
}

/* =========================================================
   BUILD DASHBOARD SHEET
========================================================= */
function buildDashboardSheet(
    XlsxWriter $w,
    array $ventures,
    array $all_gaps,
    array $analysisMap,
    array $categories
): void {
    $colWidths = [4,24,14,10,10,10,24,10,16,14,32,32];

    $w->addSheet('Dashboard', $colWidths, 13);
    $S = $w;

    $w->addRow('Dashboard', [['EdTech Fellowship Program | Mastercard Foundation | Venture Gap Analysis Dashboard', $S->S_HEADER_DARK]], 28);
    $w->merge('Dashboard', 0, 0, 1, 12);

    $w->addRow('Dashboard', [['Cross-Venture Gap Summary - Progress Overview - 12-Month Support Period', $S->S_HEADER_MID]], 18);
    $w->merge('Dashboard', 1, 0, 1, 12);

    $w->addRow('Dashboard', [['', $S->S_DEFAULT]], 6);

    $w->addRow('Dashboard', [['PROGRAM INFORMATION', $S->S_HEADER_ORANGE]], 22);
    $w->merge('Dashboard', 3, 0, 1, 12);

    $programRows = [
        ['Program Name:', 'EdTech Fellowship Program', 'Supported By:', 'Mastercard Foundation'],
        ['Cohort / Cycle:', '', 'Support Period:', '12 Months'],
        ['Program Manager:', '', 'Start Date:', ''],
        ['Total Ventures:', count($ventures), 'End Date:', ''],
        ['Prepared By:', '', 'Date:', date('Y-m-d')],
    ];

    foreach ($programRows as $ir) {
        $ri = count($w->sheets['Dashboard']);

        $w->addRow('Dashboard', [
            [$ir[0], $S->S_LABEL],
            [$ir[1], $S->S_DATA],
            ['', $S->S_DATA],
            ['', $S->S_DATA],
            [$ir[2], $S->S_LABEL],
            [$ir[3], $S->S_DATA],
            ['', $S->S_DATA],
        ], 18);

        $w->merge('Dashboard', $ri, 1, 1, 3);
        $w->merge('Dashboard', $ri, 5, 1, 2);
    }

    $w->addRow('Dashboard', [['', $S->S_DEFAULT]], 6);

    $rH = count($w->sheets['Dashboard']);

    $w->addRow('Dashboard', [['VENTURE GAP SUMMARY - One row per venture', $S->S_HEADER_DARK]], 22);
    $w->merge('Dashboard', $rH, 0, 1, 12);

    $hdrs = [
        '#','Venture Name','Stage','Total Gaps','Resolved','Progress %',
        'Top Category','Critical','Status','Last Analysis','Key Risk','Next Action'
    ];

    $w->addRow('Dashboard', array_map(fn($h) => [$h, $S->S_HEADER_MID], $hdrs), 32);

    $vNum = 1;

    foreach ($ventures as $vid => $v) {
        $vg = array_values(array_filter($all_gaps, fn($g) => (int)($g['venture_id'] ?? 0) === (int)$vid));

        $vt = count($vg);
        $vr = count(array_filter($vg, fn($g) => ($g['status'] ?? '') === 'resolved'));

        $vpct = $vt > 0
            ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $vg)) / $vt)
            : 0;

        $vc = count(array_filter($vg, fn($g) =>
            ($g['impact'] ?? '') === 'H'
            && ($g['urgency'] ?? '') === 'H'
            && ($g['status'] ?? '') !== 'resolved'
        ));

        [$vstatus] = xlsx_pct_status($vpct);

        $vsStyle = $vpct >= 70
            ? $S->S_GREEN
            : ($vpct >= 40 ? $S->S_AMBER : ($vt > 0 ? $S->S_RED : $S->S_GRAY));

        $catCounts = [];

        foreach ($vg as $gi) {
            $cat = $gi['gap_category'] ?? '';

            if ($cat !== '') {
                $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
            }
        }

        arsort($catCounts);

        $topKey = array_key_first($catCounts);
        $topCat = $topKey && isset($categories[$topKey]) ? $categories[$topKey] : '-';

        $la = $analysisMap[$vid] ?? null;
        $ds = $vNum % 2 === 0 ? $S->S_DATA_ALT : $S->S_DATA;

        $w->addRow('Dashboard', [
            [$vNum++, $S->S_DATA_CENTER],
            [$v['name'] ?? '', $ds],
            [$la['stage'] ?? '', $ds],
            [$vt ?: '-', $S->S_DATA_CENTER],
            [$vr ?: '-', $S->S_DATA_CENTER],
            [$vt > 0 ? $vpct . '%' : '-', $S->S_DATA_CENTER],
            [$topCat, $ds],
            [$vc ?: '-', $S->S_DATA_CENTER],
            [$vt > 0 ? $vstatus : 'No Data', $vsStyle],
            [$la['analysis_date'] ?? '', $ds],
            [$la['key_risk'] ?? '', $ds],
            [$la['next_action'] ?? '', $ds],
        ], 22);
    }

    $tTotal = count($all_gaps);
    $tRes = count(array_filter($all_gaps, fn($g) => ($g['status'] ?? '') === 'resolved'));

    $tCrit = count(array_filter($all_gaps, fn($g) =>
        ($g['impact'] ?? '') === 'H'
        && ($g['urgency'] ?? '') === 'H'
        && ($g['status'] ?? '') !== 'resolved'
    ));

    $tPct = $tTotal > 0
        ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $all_gaps)) / $tTotal)
        : 0;

    $w->addRow('Dashboard', [
        ['', $S->S_TOTAL],
        ['PROGRAM TOTAL', $S->S_TOTAL],
        ['', $S->S_TOTAL],
        [$tTotal, $S->S_TOTAL],
        [$tRes, $S->S_TOTAL],
        [$tPct . '%', $S->S_TOTAL],
        ['', $S->S_TOTAL],
        [$tCrit, $S->S_TOTAL],
        ['', $S->S_TOTAL],
        ['', $S->S_TOTAL],
        ['', $S->S_TOTAL],
        ['', $S->S_TOTAL],
    ], 20);

    $w->addRow('Dashboard', [['', $S->S_DEFAULT]], 6);

    $rCat = count($w->sheets['Dashboard']);

    $w->addRow('Dashboard', [['CROSS-VENTURE GAP CATEGORY BREAKDOWN', $S->S_HEADER_ORANGE]], 22);
    $w->merge('Dashboard', $rCat, 0, 1, 12);

    $catHdrs = [
        'Category','Total','Resolved','In Progress','Not Started',
        'Avg. Progress','Critical','Status'
    ];

    $w->addRow('Dashboard', array_map(fn($h) => [$h, $S->S_HEADER_MID], $catHdrs), 18);

    foreach ($categories as $catKey => $catLabel) {
        $cg = array_values(array_filter($all_gaps, fn($g) => ($g['gap_category'] ?? '') === $catKey));

        $ct = count($cg);
        $cr = count(array_filter($cg, fn($g) => ($g['status'] ?? '') === 'resolved'));
        $ci = count(array_filter($cg, fn($g) => ($g['status'] ?? '') === 'in_progress'));
        $cn = $ct - $cr - $ci;

        $cpct = $ct > 0
            ? (int)round(array_sum(array_map(fn($g) => (int)($g['pct_complete'] ?? 0), $cg)) / $ct)
            : 0;

        $ccrit = count(array_filter($cg, fn($g) =>
            ($g['impact'] ?? '') === 'H'
            && ($g['urgency'] ?? '') === 'H'
            && ($g['status'] ?? '') !== 'resolved'
        ));

        [$csl] = xlsx_pct_status($cpct);

        $csStyle = $cpct >= 70
            ? $S->S_GREEN
            : ($cpct >= 40 ? $S->S_AMBER : ($ct > 0 ? $S->S_RED : $S->S_GRAY));

        $w->addRow('Dashboard', [
            [$catLabel, $S->S_LABEL],
            [$ct ?: '-', $S->S_DATA_CENTER],
            [$cr ?: '-', $S->S_DATA_CENTER],
            [$ci ?: '-', $S->S_DATA_CENTER],
            [$cn ?: '-', $S->S_DATA_CENTER],
            [$ct > 0 ? $cpct . '%' : '-', $S->S_DATA_CENTER],
            [$ccrit ?: '-', $S->S_DATA_CENTER],
            [$ct > 0 ? $csl : '-', $csStyle],
        ], 18);
    }
}

/* =========================================================
   MAIN
========================================================= */
$w = new XlsxWriter();

if ($type === 'venture' && $venture_id > 0) {
    if (!isset($ventures[$venture_id])) {
        http_response_code(404);
        exit('Venture not found.');
    }

    $v = $ventures[$venture_id];

    $vGaps = array_values(array_filter(
        $all_gaps,
        fn($g) => (int)($g['venture_id'] ?? 0) === $venture_id
    ));

    buildVentureSheet(
        $w,
        substr((string)$v['name'], 0, 31),
        $v,
        $vGaps,
        $categories,
        $analysisMap[$venture_id] ?? null
    );

    $filename = 'GapReport_' . preg_replace('/[^a-z0-9]+/i', '_', (string)$v['name']) . '_' . date('Ymd') . '.xlsx';
} else {
    buildDashboardSheet($w, $ventures, $all_gaps, $analysisMap, $categories);

    foreach ($ventures as $vid => $v) {
        $vGaps = array_values(array_filter(
            $all_gaps,
            fn($g) => (int)($g['venture_id'] ?? 0) === (int)$vid
        ));

        buildVentureSheet(
            $w,
            substr((string)$v['name'], 0, 31),
            $v,
            $vGaps,
            $categories,
            $analysisMap[$vid] ?? null
        );
    }

    $filename = 'GapReport_AllVentures_' . date('Ymd') . '.xlsx';
}

$w->output($filename);