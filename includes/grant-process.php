<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/grant-functions.php';
require_once __DIR__ . '/loa-template-functions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error.');
}

$conn->set_charset('utf8mb4');


date_default_timezone_set('Africa/Kampala');
$conn->query("SET time_zone = '+03:00'");


$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!in_array($action, ['download_loa_template', 'download_loa', 'download_signed_loa'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Please submit this action using its form.');
}


define('LOA_OUTPUT_DIR', __DIR__ . '/../uploads/loa/');
define('TRANCHE_UPLOAD_DIR', __DIR__ . '/../uploads/tranche-evidence/');

function redirect_back(string $fallback = '../sessions.php'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? $fallback;
    header('Location: ' . $ref);
    exit;
}


function fetch_active_loa_template_row(mysqli $conn): ?array
{
    $result = $conn->query("
        SELECT
            id, name, file_name, mime_type, file_data, file_size,
            version, is_active, uploaded_by, notes, created_at
        FROM loa_templates
        WHERE is_active = 1
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();

    return $row ?: null;
}


function fill_loa_docx(string $templateBinary, array $replacements): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'loa_fill_') . '.docx';
    file_put_contents($tmpPath, $templateBinary);

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        @unlink($tmpPath);
        throw new RuntimeException('Could not open the LoA template as a .docx archive.');
    }

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        @unlink($tmpPath);
        throw new RuntimeException('Template is missing word/document.xml � not a valid .docx.');
    }

    foreach ($replacements as $placeholder => $value) {
        $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = str_replace('{{' . $placeholder . '}}', $escaped, $xml);
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    $output = file_get_contents($tmpPath);
    @unlink($tmpPath);

    if ($output === false) {
        throw new RuntimeException('Failed to read back the generated .docx.');
    }

    return $output;
}


function build_loa_replacements(array $loaData): array
{
    $effective = !empty($loaData['effective_date'])
        ? (new DateTime($loaData['effective_date']))->format('j F Y')
        : '';
    $end = !empty($loaData['end_date'])
        ? (new DateTime($loaData['end_date']))->format('j F Y')
        : '';

    return [
        'FELLOW_LEGAL_NAME'  => $loaData['fellow_legal_name'] ?? '',
        'ENTITY_TYPE'        => $loaData['entity_type'] ?? '',
        'FELLOW_ADDRESS'     => $loaData['fellow_address'] ?? '',
        'REP_TITLE'          => $loaData['rep_title'] ?? '',
        'REP_NAME'           => $loaData['rep_name'] ?? '',
        'EFFECTIVE_DATE'     => $effective,
        'END_DATE'           => $end,
        'GRANT_TOTAL'        => number_format((float)($loaData['grant_total'] ?? 0), 2),
        'GRANT_TOTAL_WORDS'  => $loaData['grant_total_words'] ?? '',
    ];
}


function docx_emu_to_px(string $emu): int
{
    return (int)round(((float)$emu) / 914400 * 96);
}

function docx_drawing_to_html(DOMElement $run, DOMXPath $xpath, array $imageMap): string
{
    $blips = $xpath->query('.//a:blip', $run);
    if ($blips->length === 0) {
        return '';
    }

    $blip = $blips->item(0);
    $rId  = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');

    if ($rId === '' || !isset($imageMap[$rId])) {
        return '';
    }

    $img = $imageMap[$rId];

    $styleAttr = '';
    $extent = $xpath->query('.//wp:extent', $run)->item(0);
    if ($extent instanceof DOMElement) {
        $width  = docx_emu_to_px($extent->getAttribute('cx'));
        $height = docx_emu_to_px($extent->getAttribute('cy'));
        if ($width && $height) {
            $styleAttr = " width=\"{$width}\" height=\"{$height}\"";
        }
    }

    $dataUri = 'data:' . $img['mime'] . ';base64,' . base64_encode($img['data']);

    return '<img src="' . $dataUri . '"' . $styleAttr . ' style="vertical-align:middle">';
}

function docx_run_to_html(DOMElement $run, DOMXPath $xpath, array $imageMap): string
{
    $drawingHtml = docx_drawing_to_html($run, $xpath, $imageMap);
    if ($drawingHtml !== '') {
        return $drawingHtml;
    }

    $isBold   = $xpath->query('.//w:rPr/w:b', $run)->length > 0;
    $isItalic = $xpath->query('.//w:rPr/w:i', $run)->length > 0;

    $text = '';
    foreach ($xpath->query('.//w:t', $run) as $t) {
        $text .= htmlspecialchars($t->textContent, ENT_QUOTES, 'UTF-8');
    }

    if ($xpath->query('.//w:tab', $run)->length > 0) {
        $text .= '&nbsp;&nbsp;&nbsp;&nbsp;';
    }
    if ($xpath->query('.//w:br', $run)->length > 0) {
        $text .= '<br>';
    }

    if ($text === '') {
        return '';
    }

    if ($isBold) {
        $text = '<strong>' . $text . '</strong>';
    }
    if ($isItalic) {
        $text = '<em>' . $text . '</em>';
    }

    return $text;
}

function docx_paragraph_to_html(DOMElement $p, DOMXPath $xpath, array $imageMap): string
{
    $inner = '';
    foreach ($xpath->query('./w:r', $p) as $run) {
        $inner .= docx_run_to_html($run, $xpath, $imageMap);
    }

    $styleNode = $xpath->query('./w:pPr/w:pStyle', $p)->item(0);
    $styleVal  = $styleNode instanceof DOMElement ? $styleNode->getAttribute('w:val') : '';

    $jcNode = $xpath->query('./w:pPr/w:jc', $p)->item(0);
    $align  = $jcNode instanceof DOMElement ? $jcNode->getAttribute('w:val') : '';
    $alignMap = ['center' => 'center', 'right' => 'right', 'both' => 'justify'];
    $styleAttr = isset($alignMap[$align]) ? ' style="text-align:' . $alignMap[$align] . '"' : '';

    if ($inner === '') {
        return '<p>&nbsp;</p>';
    }

    if (stripos($styleVal, 'Title') !== false || stripos($styleVal, 'Heading1') !== false) {
        return '<h1' . $styleAttr . '>' . $inner . '</h1>';
    }
    if (stripos($styleVal, 'Heading2') !== false) {
        return '<h2' . $styleAttr . '>' . $inner . '</h2>';
    }
    if (stripos($styleVal, 'Heading3') !== false) {
        return '<h3' . $styleAttr . '>' . $inner . '</h3>';
    }

    return '<p' . $styleAttr . '>' . $inner . '</p>';
}

function docx_table_to_html(DOMElement $tbl, DOMXPath $xpath, array $imageMap): string
{
    $html = '<table style="width:100%;border-collapse:collapse;margin:10px 0 16px">';

    foreach ($xpath->query('./w:tr', $tbl) as $tr) {
        $html .= '<tr>';
        foreach ($xpath->query('./w:tc', $tr) as $tc) {
            $cellHtml = '';
            foreach ($xpath->query('./w:p', $tc) as $p) {
                $cellHtml .= docx_paragraph_to_html($p, $xpath, $imageMap);
            }
            $html .= '<td style="padding:5px 10px;vertical-align:top;border-bottom:1px solid #e5e7eb">' . $cellHtml . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
}

function docx_xml_to_html(string $documentXml, array $imageMap): string
{
    $dom = new DOMDocument();
    $dom->loadXML($documentXml, LIBXML_NOWARNING | LIBXML_NOERROR);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
    $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

    $body = $xpath->query('//w:body')->item(0);
    if (!$body) {
        return '';
    }

    $html = '';
    foreach ($body->childNodes as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }
        if ($node->localName === 'p') {
            $html .= docx_paragraph_to_html($node, $xpath, $imageMap);
        } elseif ($node->localName === 'tbl') {
            $html .= docx_table_to_html($node, $xpath, $imageMap);
        }
    }

    return $html;
}


function docx_build_image_map(ZipArchive $zip): array
{
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($relsXml === false) {
        return [];
    }

    $relsDom = new DOMDocument();
    $relsDom->loadXML($relsXml, LIBXML_NOWARNING | LIBXML_NOERROR);

    $mimeByExt = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
    ];

    $map = [];
    foreach ($relsDom->getElementsByTagName('Relationship') as $rel) {
        if (stripos($rel->getAttribute('Type'), '/image') === false) {
            continue;
        }

        $rId    = $rel->getAttribute('Id');
        $target = $rel->getAttribute('Target');
        $data   = $zip->getFromName('word/' . ltrim($target, '/'));

        if ($data === false) {
            continue;
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $map[$rId] = [
            'data' => $data,
            'mime' => $mimeByExt[$ext] ?? 'application/octet-stream',
        ];
    }

    return $map;
}


function convert_docx_to_pdf_via_dompdf(string $docxBinary): ?string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'loa_docx_') . '.docx';
    file_put_contents($tmpPath, $docxBinary);

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        @unlink($tmpPath);
        return null;
    }

    $xml = $zip->getFromName('word/document.xml');
    $imageMap = docx_build_image_map($zip);
    $zip->close();
    @unlink($tmpPath);

    if ($xml === false) {
        return null;
    }

    $bodyHtml = docx_xml_to_html($xml, $imageMap);

    $fullHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 60px 55px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #222; line-height: 1.6; }
  h1 { font-size: 19px; text-align: center; }
  h2 { font-size: 15px; }
  h3 { font-size: 13px; }
  p { margin: 0 0 8px; }
  table { font-size: 12px; }
  img { max-width: 100%; }
</style>
</head>
<body>
{$bodyHtml}
</body>
</html>
HTML;

    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($fullHtml);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    } catch (\Throwable $e) {
        error_log('LoA docx->pdf (Dompdf) conversion failed: ' . $e->getMessage());
        return null;
    }
}


function loa_upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => 'The uploaded template exceeds the server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded template exceeds the maximum file size allowed by the form.',
        UPLOAD_ERR_PARTIAL => 'The template upload was interrupted and only part of the file was received.',
        UPLOAD_ERR_NO_FILE => 'No template file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the template upload.',
        default => 'The template upload failed with error code ' . $errorCode . '.',
    };
}

function loa_redirect_admin(array $query): never
{
    header('Location: ../admin/loa-template.php?' . http_build_query($query));
    exit;
}

function loa_normalize_upload_name(string $name): string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    return $name !== '' ? $name : 'loa-template';
}

function loa_table_columns(mysqli $conn, string $table): array
{
    $safeTable = str_replace('`', '``', $table);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
    if (!$result) {
        throw new RuntimeException("Could not inspect {$table}: " . $conn->error);
    }
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[(string)$row['Field']] = true;
    }
    return $columns;
}

function loa_next_template_version(mysqli $conn): int
{
    $result = $conn->query('SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM loa_templates');
    if (!$result) {
        throw new RuntimeException('Could not determine the next template version: ' . $conn->error);
    }
    return max(1, (int)($result->fetch_assoc()['next_version'] ?? 1));
}

function insert_and_activate_loa_template(
    mysqli $conn,
    string $name,
    string $fileName,
    string $mimeType,
    string $binary,
    ?int $uploadedBy,
    ?string $notes
): int {
    $columns = loa_table_columns($conn, 'loa_templates');
    foreach (['name','file_name','mime_type','file_data','file_size','version','is_active'] as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException("The loa_templates table is missing the required column: {$column}.");
        }
    }

    $version = loa_next_template_version($conn);
    $fileSize = strlen($binary);
    $conn->begin_transaction();

    try {
        $deactivate = $conn->prepare('UPDATE loa_templates SET is_active = 0 WHERE is_active = 1');
        if (!$deactivate || !$deactivate->execute()) {
            throw new RuntimeException('Could not deactivate the previous template: ' . ($deactivate?->error ?? $conn->error));
        }
        $deactivate->close();

        $fields = ['name','file_name','mime_type','file_data','file_size','version','is_active'];
        $values = [$name,$fileName,$mimeType,null,$fileSize,$version,1];
        $types = 'sssbiii';

        if (isset($columns['uploaded_by'])) {
            $fields[] = 'uploaded_by';
            $values[] = $uploadedBy;
            $types .= 'i';
        }
        if (isset($columns['notes'])) {
            $fields[] = 'notes';
            $values[] = $notes;
            $types .= 's';
        }

        $quoted = array_map(static fn(string $f): string => '`'.$f.'`', $fields);
        $sql = 'INSERT INTO loa_templates (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0,count($fields),'?')) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not prepare template insert: ' . $conn->error);
        }

        $refs = [$types];
        foreach ($values as $i => &$value) {
            $refs[] = &$value;
        }
        unset($value);

        if (!call_user_func_array([$stmt,'bind_param'],$refs)) {
            throw new RuntimeException('Could not bind template values: ' . $stmt->error);
        }
        if (!$stmt->send_long_data(3,$binary)) {
            throw new RuntimeException('Could not attach template file data: ' . $stmt->error);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not insert the uploaded template: ' . $stmt->error);
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();
        if ($id <= 0) {
            throw new RuntimeException('Template was inserted but no record ID was returned.');
        }
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function grant_process_is_admin(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (
        function_exists('auth_is_privileged_staff')
        &&
        auth_is_privileged_staff()
    ) {
        return true;
    }

    $role = strtolower(
        trim(
            (string)(
                $_SESSION['role']
                ?? $_SESSION['user_role']
                ?? ''
            )
        )
    );

    return in_array(
        $role,
        [
            'administrator',
            'admin',
            'program director',
            'program manager',
            'programs lead',
        ],
        true
    );
}

function grant_process_require_admin(): void
{
    if (grant_process_is_admin()) {
        return;
    }

    http_response_code(403);
    exit('You do not have permission to manage grant submissions.');
}

function grant_process_admin_redirect(
    int $ventureId,
    string $message = '',
    string $error = ''
): never {
    $query = [
        'venture_id' => $ventureId,
    ];

    if ($message !== '') {
        $query['message'] = $message;
    }

    if ($error !== '') {
        $query['error'] = $error;
    }

    header(
        'Location: ../admin/grant-tracking.php?'
        . http_build_query($query)
    );

    exit;
}

function grant_process_unlink_submission_file(array $file): void
{
    $storedName =
        basename(
            (string)(
                $file['file_path']
                ?? ''
            )
        );

    if ($storedName === '') {
        return;
    }

    $path =
        rtrim(
            TRANCHE_UPLOAD_DIR,
            '/\\'
        )
        . DIRECTORY_SEPARATOR
        . $storedName;

    if (is_file($path)) {
        @unlink($path);
    }
}

function grant_process_latest_submission_status(
    mysqli $conn,
    int $trancheId
): ?array {
    return get_latest_submission(
        $conn,
        $trancheId
    );
}

function grant_process_recalculate_tranche_status(
    mysqli $conn,
    int $trancheId
): void {
    $latest =
        grant_process_latest_submission_status(
            $conn,
            $trancheId
        );

    $status = 'open';

    if ($latest) {
        $reviewStatus =
            strtolower(
                trim(
                    (string)(
                        $latest['review_status']
                        ?? 'pending'
                    )
                )
            );

        $status =
            match ($reviewStatus) {
                'approved' => 'approved',
                'changes_requested' => 'changes_requested',
                'rejected' => 'rejected',
                default => 'submitted',
            };
    }

    $stmt = $conn->prepare("
        UPDATE grant_tranches
        SET status = ?
        WHERE id = ?
    ");

    if ($stmt) {
        $stmt->bind_param(
            'si',
            $status,
            $trancheId
        );

        $stmt->execute();
        $stmt->close();
    }
}


switch ($action) {


    case 'save_and_generate_loa': {
        

        $ventureId       = (int)($_POST['venture_id'] ?? 0);
        $fellowLegalName = trim($_POST['fellow_legal_name'] ?? '');
        $entityType      = trim($_POST['entity_type'] ?? '');
        $fellowAddress   = trim($_POST['fellow_address'] ?? '');
        $repTitle        = trim($_POST['rep_title'] ?? '');
        $repName         = trim($_POST['rep_name'] ?? '');
        $effectiveDate   = trim($_POST['effective_date'] ?? '');
        $endDate         = trim($_POST['end_date'] ?? '');
        $grantTotal      = (float)($_POST['grant_total'] ?? 70000);
        $grantTotalWords = trim($_POST['grant_total_words'] ?? 'Seventy Thousand');

        if ($ventureId <= 0 || $fellowLegalName === '' || $effectiveDate === '' || $endDate === '') {
            http_response_code(400);
            exit('Missing required fields.');
        }

     
        $templateRow = fetch_active_loa_template_row($conn);

        if ($templateRow === null || empty($templateRow['file_data'])) {
            redirect_back(
                '../admin/grant-tracking.php?venture_id='
                . $ventureId
                . '&message=loa_template_missing'
            );
        }

        $templateMime = (string)($templateRow['mime_type'] ?? '');

        $stmt = $conn->prepare("SELECT id FROM loa_agreements WHERE venture_id = ? LIMIT 1");
        $stmt->bind_param('i', $ventureId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $loaId = (int)$existing['id'];
            $stmt = $conn->prepare("
                UPDATE loa_agreements SET
                    fellow_legal_name = ?, entity_type = ?, fellow_address = ?,
                    rep_title = ?, rep_name = ?, effective_date = ?, end_date = ?,
                    grant_total = ?, grant_total_words = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                'sssssssdsi',
                $fellowLegalName, $entityType, $fellowAddress,
                $repTitle, $repName, $effectiveDate, $endDate,
                $grantTotal, $grantTotalWords, $loaId
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO loa_agreements
                    (venture_id, fellow_legal_name, entity_type, fellow_address,
                     rep_title, rep_name, effective_date, end_date, grant_total, grant_total_words)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'isssssssds',
                $ventureId, $fellowLegalName, $entityType, $fellowAddress,
                $repTitle, $repName, $effectiveDate, $endDate, $grantTotal, $grantTotalWords
            );
            $stmt->execute();
            $loaId = (int)$stmt->insert_id;
            $stmt->close();
        }

 
        $replacements = build_loa_replacements([
            'fellow_legal_name' => $fellowLegalName,
            'entity_type'       => $entityType,
            'fellow_address'    => $fellowAddress,
            'rep_title'         => $repTitle,
            'rep_name'          => $repName,
            'effective_date'    => $effectiveDate,
            'end_date'          => $endDate,
            'grant_total'       => $grantTotal,
            'grant_total_words' => $grantTotalWords,
        ]);

        if (!is_dir(LOA_OUTPUT_DIR)) {
            mkdir(LOA_OUTPUT_DIR, 0755, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $fellowLegalName) ?: 'agreement';

        if (loa_template_is_docx($templateMime)) {
            try {
                $filledDocx = fill_loa_docx($templateRow['file_data'], $replacements);
            } catch (\Throwable $e) {
                error_log('LoA docx generation failed: ' . $e->getMessage());
                http_response_code(500);
                exit('Failed to generate the LoA document from the active template. ' . $e->getMessage());
            }

            $outFileName = 'LoA_' . $safeName . '_v' . $loaId . '_' . time() . '.docx';
            $outPath     = LOA_OUTPUT_DIR . $outFileName;

            if (file_put_contents($outPath, $filledDocx) === false) {
                http_response_code(500);
                exit('Failed to write the generated LoA document. Check that the output directory is writable.');
            }
        } else {
       
            $mergedPdf = $templateRow['file_data'];

            $outFileName = 'LoA_' . $safeName . '_v' . $loaId . '_' . time() . '.pdf';
            $outPath     = LOA_OUTPUT_DIR . $outFileName;

            if (file_put_contents($outPath, $mergedPdf) === false) {
                http_response_code(500);
                exit('Failed to write the generated LoA document. Check that the output directory is writable.');
            }
        }

        $updateGen = $conn->prepare("
            UPDATE loa_agreements
            SET generated_file_path = ?, status = 'generated'
            WHERE id = ?
        ");
        $updateGen->bind_param('si', $outFileName, $loaId);
        $updateGen->execute();
        $updateGen->close();

        // Seed the standard tranche schedule the first time an LoA is generated
        $check = $conn->prepare("SELECT COUNT(*) AS c FROM grant_tranches WHERE venture_id = ?");
        $check->bind_param('i', $ventureId);
        $check->execute();
        $count = (int)($check->get_result()->fetch_assoc()['c'] ?? 0);
        $check->close();

        if ($count === 0) {
            $conn->query("CALL seed_standard_tranches({$ventureId})");
        }

        redirect_back('../admin/grant-tracking.php?venture_id=' . $ventureId . '&message=loa_generated');
        break;
    }


    case 'upload_loa_template': {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'Template uploads must use POST.']);
        }

        $upload = $_FILES['template_file'] ?? null;
        if (!is_array($upload)) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'No template file was received. Check post_max_size and upload_max_filesize.']);
        }

        $uploadError = isset($upload['error']) ? (int)$upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => loa_upload_error_message($uploadError)]);
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName) || !is_readable($tmpName)) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'The uploaded temporary file is unavailable or unreadable.']);
        }

        $originalName = loa_normalize_upload_name((string)($upload['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = loa_template_mime_for_extension($extension);
        if ($mimeType === null) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'Template must be a valid .docx or .pdf file.']);
        }

        $actualSize = filesize($tmpName);
        if ($actualSize === false || $actualSize <= 0) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'The uploaded template is empty.']);
        }
        if ($actualSize > 20 * 1024 * 1024) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'The template is too large. Maximum allowed size is 20 MB.']);
        }

        $binary = file_get_contents($tmpName);
        if ($binary === false || strlen($binary) !== $actualSize) {
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'The server could not read the complete uploaded template.']);
        }

        if ($extension === 'docx') {
            if (!class_exists(ZipArchive::class)) {
                loa_redirect_admin(['tab' => 'upload-template', 'error' => 'The PHP ZIP extension is required to validate Word templates.']);
            }
            $missing = missing_loa_template_placeholders($binary, $mimeType);
            if (!empty($missing)) {
                loa_redirect_admin(['tab' => 'upload-template', 'error' => 'Upload cancelled. Missing placeholders: ' . implode(', ', $missing)]);
            }
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 150) : 'Standard LoA Template';
        $notesValue = trim((string)($_POST['notes'] ?? ''));
        $notes = $notesValue !== '' ? mb_substr($notesValue, 0, 1000) : null;
        $uploadedByValue = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
        $uploadedBy = $uploadedByValue > 0 ? $uploadedByValue : null;

        try {
            $newTemplateId = insert_and_activate_loa_template(
                $conn,
                $name,
                $originalName,
                $mimeType,
                $binary,
                $uploadedBy,
                $notes
            );
        } catch (Throwable $e) {
            error_log('LoA template upload failed: ' . $e->getMessage());
            loa_redirect_admin(['tab' => 'upload-template', 'error' => 'Database insert failed: ' . $e->getMessage()]);
        }

        loa_redirect_admin(['uploaded' => 1, 'template_id' => $newTemplateId]);
    }


    case 'download_loa_template': {
        // TODO: enforce admin-only auth here.

        $templateId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $tpl = $templateId > 0
            ? get_loa_template_by_id($conn, $templateId, true)
            : get_active_loa_template($conn, true);

        if (!$tpl) {
            http_response_code(404);
            exit('Template not found.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove();
        header('Content-Type: ' . $tpl['mime_type']);
        header('Content-Disposition: attachment; filename="' . basename($tpl['file_name']) . '"');
        header('Content-Length: ' . strlen($tpl['file_data']));
        echo $tpl['file_data'];
        exit;
    }


    case 'activate_loa_template': {
        // TODO: enforce admin-only auth here.

        $templateId = (int)($_POST['id'] ?? 0);
        if ($templateId <= 0) {
            http_response_code(400);
            exit('Missing template id.');
        }

        activate_loa_template($conn, $templateId);

        redirect_back('../admin/loa-template.php?activated=1');
        break;
    }


    case 'download_loa': {
        $loaId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $mode  = $_GET['mode'] ?? $_POST['mode'] ?? 'download';

        if ($loaId <= 0) {
            http_response_code(400);
            exit('Missing LoA id.');
        }

        $stmt = $conn->prepare("SELECT * FROM loa_agreements WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $loaId);
        $stmt->execute();
        $loa = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loa) {
            http_response_code(404);
            exit('LoA not found.');
        }

        // Access control: a venture user may only view/download their own LoA.
        $sessionVentureId = (int)($_SESSION['venture_id'] ?? 0);
        $isAdmin          = !empty($_SESSION['is_admin']) || auth_is_privileged_staff();
        if (!$isAdmin && $sessionVentureId !== (int)$loa['venture_id']) {
            http_response_code(403);
            exit('You do not have access to this document.');
        }

        if (empty($loa['generated_file_path'])) {
            http_response_code(404);
            exit('This agreement has not been generated yet. Save the LoA details first.');
        }

        $filePath = LOA_OUTPUT_DIR . $loa['generated_file_path'];

        if (!file_exists($filePath)) {
            http_response_code(404);
            exit('The generated agreement file is missing on disk. Try regenerating it from the Letter of Agreement tab.');
        }


        if (!$isAdmin && $mode === 'download') {
            $trackDownload = $conn->prepare("
                UPDATE loa_agreements
                SET
                    download_count = COALESCE(download_count, 0) + 1,
                    first_downloaded_at = COALESCE(first_downloaded_at, NOW()),
                    last_downloaded_at = NOW()
                WHERE id = ?
            ");
            $trackDownload->bind_param('i', $loaId);
            $trackDownload->execute();
            $trackDownload->close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove();

        $disposition = $mode === 'preview' ? 'inline' : 'attachment';
        $isAlreadyPdf = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf';

        if ($isAlreadyPdf) {
            // Generated from a PDF template (summary + merged pages via
            // FPDI) � it's already a real PDF, nothing to convert.
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . basename($filePath) . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }

        $pdfCachePath    = preg_replace('/\.docx$/i', '.pdf', $filePath);
        $needsConversion = !file_exists($pdfCachePath) || filemtime($pdfCachePath) < filemtime($filePath);

        if ($needsConversion) {
            $docxBinary = file_get_contents($filePath);
            $pdfData = $docxBinary !== false ? convert_docx_to_pdf_via_dompdf($docxBinary) : null;
            if ($pdfData !== null) {
                file_put_contents($pdfCachePath, $pdfData);
            }
        }

        if (file_exists($pdfCachePath) && filemtime($pdfCachePath) >= filemtime($filePath)) {
            $pdfName = preg_replace('/\.docx$/i', '.pdf', basename($filePath));
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . $pdfName . '"');
            header('Content-Length: ' . filesize($pdfCachePath));
            readfile($pdfCachePath);
            exit;
        }

        
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }


    case 'upload_signed_loa': {
        $loaId     = (int)($_POST['loa_id'] ?? 0);
        $ventureId = (int)($_SESSION['venture_id'] ?? 0);

        if ($loaId <= 0 || $ventureId <= 0) {
            http_response_code(400);
            exit('Missing agreement or venture.');
        }

        $stmt = $conn->prepare("SELECT id, venture_id FROM loa_agreements WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $loaId);
        $stmt->execute();
        $loa = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loa || (int)$loa['venture_id'] !== $ventureId) {
            http_response_code(403);
            exit('This agreement does not belong to your venture.');
        }

        if (empty($_FILES['signed_file']) || $_FILES['signed_file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            exit('No signed document was uploaded.');
        }

        $originalName = $_FILES['signed_file']['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowedExt, true)) {
            http_response_code(400);
            exit('Signed document must be a PDF, JPG, or PNG (scanned/signed copy).');
        }

        $signedDir = __DIR__ . '/../uploads/loa-signed/';
        if (!is_dir($signedDir)) {
            mkdir($signedDir, 0755, true);
        }

        $safeName = 'signed_loa' . $loaId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath = $signedDir . $safeName;

        if (!move_uploaded_file($_FILES['signed_file']['tmp_name'], $destPath)) {
            http_response_code(500);
            exit('Failed to save the uploaded file.');
        }

        $update = $conn->prepare("
            UPDATE loa_agreements
            SET signed_file_path = ?, signed_file_name = ?, signed_uploaded_at = NOW(),
                signed_status = 'pending', signed_confirmed_by = NULL, signed_confirmed_at = NULL
            WHERE id = ?
        ");
        $update->bind_param('ssi', $safeName, $originalName, $loaId);
        $update->execute();
        $update->close();

        redirect_back('../tranches.php?signed_uploaded=1');
        break;
    }


    case 'confirm_signed_loa': {
        grant_process_require_admin();

        $loaId = (int)($_POST['loa_id'] ?? 0);
        if ($loaId <= 0) {
            http_response_code(400);
            exit('Missing agreement id.');
        }

        $confirmedBy = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0) ?: null;

        $update = $conn->prepare("
            UPDATE loa_agreements
            SET signed_status = 'confirmed', signed_confirmed_by = ?, signed_confirmed_at = NOW()
            WHERE id = ?
        ");
        $update->bind_param('ii', $confirmedBy, $loaId);
        $update->execute();
        $update->close();

        redirect_back('../admin/grant-tracking.php?signed_confirmed=1');
        break;
    }

   
    case 'download_signed_loa': {
        $loaId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

        if ($loaId <= 0) {
            http_response_code(400);
            exit('Missing agreement id.');
        }

        $stmt = $conn->prepare("SELECT * FROM loa_agreements WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $loaId);
        $stmt->execute();
        $loa = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loa || empty($loa['signed_file_path'])) {
            http_response_code(404);
            exit('No signed agreement has been uploaded for this LoA.');
        }

        $sessionVentureId = (int)($_SESSION['venture_id'] ?? 0);
        $isAdmin          = !empty($_SESSION['is_admin']) || auth_is_privileged_staff();
        if (!$isAdmin && $sessionVentureId !== (int)$loa['venture_id']) {
            http_response_code(403);
            exit('You do not have access to this document.');
        }

        $signedPath = __DIR__ . '/../uploads/loa-signed/' . $loa['signed_file_path'];
        if (!file_exists($signedPath)) {
            http_response_code(404);
            exit('The signed document is missing on disk.');
        }

        $ext = strtolower(pathinfo($signedPath, PATHINFO_EXTENSION));
        $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove();
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . basename((string)($loa['signed_file_name'] ?? $signedPath)) . '"');
        header('Content-Length: ' . filesize($signedPath));
        readfile($signedPath);
        exit;
    }

  
    case 'submit_tranche': {
        $trancheId = (int)($_POST['tranche_id'] ?? 0);

        $sessionVentureId =
            (int)(
                $_SESSION['venture_id']
                ?? 0
            );

        $ventureId =
            grant_process_is_admin()
                ? (int)(
                    $_POST['venture_id']
                    ?? $sessionVentureId
                )
                : $sessionVentureId;

        $narrative =
            trim(
                (string)(
                    $_POST['narrative']
                    ?? ''
                )
            );

        $responses =
            is_array(
                $_POST['responses']
                ?? null
            )
                ? $_POST['responses']
                : [];

        if ($trancheId <= 0 || $ventureId <= 0) {
            http_response_code(400);
            exit('Missing tranche or venture.');
        }

        // Confirm the tranche belongs to this venture and is actually open
        $check = $conn->prepare("SELECT status FROM grant_tranches WHERE id = ? AND venture_id = ? LIMIT 1");
        $check->bind_param('ii', $trancheId, $ventureId);
        $check->execute();
        $tranche = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$tranche) {
            http_response_code(403);
            exit('Tranche does not belong to this venture.');
        }
        if (!in_array($tranche['status'], ['open', 'changes_requested', 'rejected'], true)) {
            http_response_code(409);
            exit('This tranche is not currently open for submission.');
        }

        $stmt = $conn->prepare("
            INSERT INTO tranche_submissions (tranche_id, venture_id, narrative, submitted_by)
            VALUES (?, ?, ?, ?)
        ");
        $submittedBy = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $stmt->bind_param('iisi', $trancheId, $ventureId, $narrative, $submittedBy);
        $stmt->execute();
        $submissionId = (int)$stmt->insert_id;
        $stmt->close();

        foreach ($responses as $requirementId => $text) {
            $requirementId = (int)$requirementId;
            $text = trim((string)$text);
            if ($requirementId <= 0 || $text === '') {
                continue;
            }
            $rstmt = $conn->prepare("
                INSERT INTO tranche_requirement_responses (submission_id, requirement_id, response_text)
                VALUES (?, ?, ?)
            ");
            $rstmt->bind_param('iis', $submissionId, $requirementId, $text);
            $rstmt->execute();
            $rstmt->close();
        }

        // File uploads (multiple allowed): <input type="file" name="evidence[]" multiple>
        if (!empty($_FILES['evidence']) && is_array($_FILES['evidence']['name'])) {
            if (!is_dir(TRANCHE_UPLOAD_DIR)) {
                mkdir(TRANCHE_UPLOAD_DIR, 0755, true);
            }

            $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

            foreach ($_FILES['evidence']['name'] as $i => $originalName) {
                if ($_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    continue;
                }

                $safeName = 'sub' . $submissionId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destPath = TRANCHE_UPLOAD_DIR . $safeName;

                if (move_uploaded_file($_FILES['evidence']['tmp_name'][$i], $destPath)) {
                    $fstmt = $conn->prepare("
                        INSERT INTO tranche_submission_files (submission_id, file_name, file_path, file_label)
                        VALUES (?, ?, ?, ?)
                    ");
                    $label = $_POST['evidence_label'][$i] ?? null;
                    $fstmt->bind_param('isss', $submissionId, $originalName, $safeName, $label);
                    $fstmt->execute();
                    $fstmt->close();
                }
            }
        }

        // Mark tranche as submitted
        $update = $conn->prepare("UPDATE grant_tranches SET status = 'submitted' WHERE id = ?");
        $update->bind_param('i', $trancheId);
        $update->execute();
        $update->close();

        redirect_back('../tranches.php?submitted=1');
        break;
    }

  
    case 'review_tranche_submission': {
        // TODO: enforce admin-only auth here.

        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $decision     = $_POST['decision'] ?? ''; // 'approved' | 'changes_requested' | 'rejected'
        $notes        = trim($_POST['review_notes'] ?? '');
        $reviewerId   = (int)($_SESSION['user_id'] ?? 0) ?: null;

        if (
            $submissionId <= 0
            ||
            !in_array(
                $decision,
                [
                    'approved',
                    'changes_requested',
                    'rejected',
                ],
                true
            )
        ) {
            http_response_code(400);
            exit('Invalid review submission.');
        }

        grant_process_require_admin();

        if (
            in_array(
                $decision,
                [
                    'changes_requested',
                    'rejected',
                ],
                true
            )
            &&
            $notes === ''
        ) {
            http_response_code(422);
            exit('Review notes are required when requesting changes or rejecting a submission.');
        }

        $stmt = $conn->prepare("SELECT tranche_id, venture_id FROM tranche_submissions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sub) {
            http_response_code(404);
            exit('Submission not found.');
        }

        $update = $conn->prepare("
            UPDATE tranche_submissions
            SET review_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ?
        ");
        $update->bind_param('sisi', $decision, $reviewerId, $notes, $submissionId);
        $update->execute();
        $update->close();

        // Rejected and changes-requested tranches remain eligible for resubmission.
        $trancheStatus = match ($decision) {
            'approved'          => 'approved',
            'changes_requested' => 'changes_requested',
            'rejected'          => 'rejected',
            default             => 'submitted',
        };
        $update2 = $conn->prepare("UPDATE grant_tranches SET status = ? WHERE id = ?");
        $update2->bind_param('si', $trancheStatus, $sub['tranche_id']);
        $update2->execute();
        $update2->close();

        redirect_back('../admin/grant-tracking.php?venture_id=' . $sub['venture_id']);
        break;
    }

   

    case 'update_tranche_status': {
        grant_process_require_admin();

        $trancheId =
            (int)(
                $_POST['tranche_id']
                ?? 0
            );

        $ventureId =
            (int)(
                $_POST['venture_id']
                ?? 0
            );

        $status =
            grant_normalize_tranche_status(
                (string)(
                    $_POST['status']
                    ?? ''
                )
            );

        if (
            $trancheId <= 0
            ||
            $ventureId <= 0
            ||
            $status === ''
        ) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Invalid tranche status update.'
            );
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                venture_id
            FROM grant_tranches
            WHERE id = ?
              AND venture_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'ii',
            $trancheId,
            $ventureId
        );

        $stmt->execute();

        $tranche =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (!$tranche) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Tranche not found for this venture.'
            );
        }

        if (
            $status !== 'disbursed'
            &&
            get_tranche_disbursement(
                $conn,
                $trancheId
            )
        ) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'This tranche already has a recorded disbursement. Delete or correct the disbursement before changing it away from Disbursed.'
            );
        }

        $update = $conn->prepare("
            UPDATE grant_tranches
            SET status = ?
            WHERE id = ?
              AND venture_id = ?
        ");

        $update->bind_param(
            'sii',
            $status,
            $trancheId,
            $ventureId
        );

        $update->execute();
        $update->close();

        grant_process_admin_redirect(
            $ventureId,
            'tranche_status_updated'
        );
    }


    case 'allow_tranche_resubmission': {
        grant_process_require_admin();

        $trancheId =
            (int)(
                $_POST['tranche_id']
                ?? 0
            );

        $ventureId =
            (int)(
                $_POST['venture_id']
                ?? 0
            );

        $notes =
            trim(
                (string)(
                    $_POST['review_notes']
                    ?? 'Resubmission enabled by administrator.'
                )
            );

        if (
            $trancheId <= 0
            ||
            $ventureId <= 0
        ) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Invalid tranche resubmission request.'
            );
        }

        if (
            get_tranche_disbursement(
                $conn,
                $trancheId
            )
        ) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'A disbursed tranche cannot be reopened for resubmission.'
            );
        }

        $latest =
            get_latest_submission(
                $conn,
                $trancheId
            );

        if ($latest) {
            $submissionId =
                (int)$latest['id'];

            $reviewerId =
                (int)(
                    $_SESSION['user_id']
                    ?? $_SESSION['admin_id']
                    ?? 0
                )
                ?: null;

            $updateSubmission = $conn->prepare("
                UPDATE tranche_submissions
                SET
                    review_status = 'changes_requested',
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");

            $updateSubmission->bind_param(
                'sii',
                $notes,
                $reviewerId,
                $submissionId
            );

            $updateSubmission->execute();
            $updateSubmission->close();
        }

        $updateTranche = $conn->prepare("
            UPDATE grant_tranches
            SET status = 'changes_requested'
            WHERE id = ?
              AND venture_id = ?
        ");

        $updateTranche->bind_param(
            'ii',
            $trancheId,
            $ventureId
        );

        $updateTranche->execute();
        $updateTranche->close();

        grant_process_admin_redirect(
            $ventureId,
            'tranche_resubmission_enabled'
        );
    }


    case 'delete_tranche_submission_file': {
        grant_process_require_admin();

        $fileId =
            (int)(
                $_POST['file_id']
                ?? 0
            );

        $ventureId =
            (int)(
                $_POST['venture_id']
                ?? 0
            );

        if ($fileId <= 0) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Invalid submitted file.'
            );
        }

        $file =
            get_submission_file(
                $conn,
                $fileId
            );

        if (!$file) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Submitted file not found.'
            );
        }

        $ventureId =
            (int)(
                $file['venture_id']
                ?? $ventureId
            );

        grant_process_unlink_submission_file(
            $file
        );

        $delete = $conn->prepare("
            DELETE FROM tranche_submission_files
            WHERE id = ?
            LIMIT 1
        ");

        $delete->bind_param(
            'i',
            $fileId
        );

        $delete->execute();
        $delete->close();

        grant_process_admin_redirect(
            $ventureId,
            'tranche_file_deleted'
        );
    }


    case 'delete_tranche_submission': {
        grant_process_require_admin();

        $submissionId =
            (int)(
                $_POST['submission_id']
                ?? 0
            );

        $ventureId =
            (int)(
                $_POST['venture_id']
                ?? 0
            );

        if ($submissionId <= 0) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Invalid tranche submission.'
            );
        }

        $submission =
            get_tranche_submission(
                $conn,
                $submissionId
            );

        if (!$submission) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'Tranche submission not found.'
            );
        }

        $trancheId =
            (int)$submission['tranche_id'];

        $ventureId =
            (int)$submission['venture_id'];

        if (
            get_tranche_disbursement(
                $conn,
                $trancheId
            )
        ) {
            grant_process_admin_redirect(
                $ventureId,
                '',
                'A submission linked to a disbursed tranche cannot be deleted.'
            );
        }

        $conn->begin_transaction();

        try {
            foreach (
                $submission['files']
                ?? []
                as $file
            ) {
                grant_process_unlink_submission_file(
                    $file
                );
            }

            $deleteFiles = $conn->prepare("
                DELETE FROM tranche_submission_files
                WHERE submission_id = ?
            ");

            $deleteFiles->bind_param(
                'i',
                $submissionId
            );

            $deleteFiles->execute();
            $deleteFiles->close();

            $deleteResponses = $conn->prepare("
                DELETE FROM tranche_requirement_responses
                WHERE submission_id = ?
            ");

            $deleteResponses->bind_param(
                'i',
                $submissionId
            );

            $deleteResponses->execute();
            $deleteResponses->close();

            $deleteSubmission = $conn->prepare("
                DELETE FROM tranche_submissions
                WHERE id = ?
                LIMIT 1
            ");

            $deleteSubmission->bind_param(
                'i',
                $submissionId
            );

            $deleteSubmission->execute();
            $deleteSubmission->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();

            error_log(
                'Delete tranche submission failed: '
                . $e->getMessage()
            );

            grant_process_admin_redirect(
                $ventureId,
                '',
                'Could not delete the tranche submission.'
            );
        }

        grant_process_recalculate_tranche_status(
            $conn,
            $trancheId
        );

        grant_process_admin_redirect(
            $ventureId,
            'tranche_submission_deleted'
        );
    }


    case 'record_disbursement': {
        grant_process_require_admin();

        $trancheId       = (int)($_POST['tranche_id'] ?? 0);
        $ventureId       = (int)($_POST['venture_id'] ?? 0);
        $amount          = (float)($_POST['amount'] ?? 0);
        $currency        = trim($_POST['currency'] ?? 'USD');
        $disbursedDate   = trim($_POST['disbursed_date'] ?? '');
        $transactionRef  = trim($_POST['transaction_ref'] ?? '');
        $paymentMethod   = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $notes           = trim($_POST['notes'] ?? '');
        $recordedBy      = (int)($_SESSION['user_id'] ?? 0) ?: null;

        if ($trancheId <= 0 || $ventureId <= 0 || $amount <= 0 || $disbursedDate === '') {
            http_response_code(400);
            exit('Missing required disbursement fields.');
        }

        $stmt = $conn->prepare("
            INSERT INTO fund_disbursements
                (venture_id, tranche_id, amount, currency, disbursed_date, transaction_ref, payment_method, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'iidsssssi',
            $ventureId, $trancheId, $amount, $currency, $disbursedDate,
            $transactionRef, $paymentMethod, $notes, $recordedBy
        );
        $stmt->execute();
        $stmt->close();

        $update = $conn->prepare("UPDATE grant_tranches SET status = 'disbursed' WHERE id = ?");
        $update->bind_param('i', $trancheId);
        $update->execute();
        $update->close();

        unlock_next_tranche($conn, $ventureId, $trancheId);

        redirect_back('../admin/grant-tracking.php?venture_id=' . $ventureId . '&message=disbursement_recorded');
        break;
    }


    case 'confirm_disbursement_receipt': {
        $disbursementId = (int)($_POST['disbursement_id'] ?? 0);

        $update = $conn->prepare("
            UPDATE fund_disbursements
            SET confirmation_received = 1, confirmation_received_at = NOW()
            WHERE id = ?
        ");
        $update->bind_param('i', $disbursementId);
        $update->execute();
        $update->close();

        redirect_back();
        break;
    }

    default:
        http_response_code(400);
        exit('Unknown action.');
}
