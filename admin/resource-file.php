<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');

$id   = (int)($_GET['id'] ?? 0);
$mode = strtolower(trim((string)($_GET['mode'] ?? 'download')));

if (!in_array($mode, ['view', 'download', 'pdf'], true)) {
    $mode = 'download';
}

if ($id <= 0) {
    http_response_code(404);
    exit('Invalid resource.');
}

function arf_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function arf_load_vendor(): array
{
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];

    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            return ['loaded' => true, 'path' => $autoload];
        }
    }

    return ['loaded' => false, 'path' => ''];
}

function arf_table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $q = $conn->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function arf_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

function arf_create_download_log_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS resource_download_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        resource_id INT UNSIGNED NOT NULL,
        venture_id INT UNSIGNED NULL,
        downloaded_by_name VARCHAR(190) NULL,
        downloaded_by_email VARCHAR(190) NULL,
        ip_address VARCHAR(80) NULL,
        user_agent TEXT NULL,
        downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_resource_id (resource_id), KEY idx_venture_id (venture_id), KEY idx_downloaded_at (downloaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function arf_mime(string $ext): string
{
    return [
        'pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation',
        'zip'=>'application/zip','rar'=>'application/vnd.rar','7z'=>'application/x-7z-compressed','txt'=>'text/plain','csv'=>'text/csv','json'=>'application/json','xml'=>'application/xml',
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
        'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg',
    ][$ext] ?? 'application/octet-stream';
}

function arf_safe_filename(string $filename): string
{
    $filename = str_replace(["\r", "\n", '"'], '', trim($filename));
    return $filename !== '' ? basename($filename) : 'resource-file';
}

function arf_send_file(string $file, string $filename, string $mime, bool $inline): never
{
    if (!is_file($file) || !is_readable($file)) { http_response_code(404); exit('File not found.'); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $filename = arf_safe_filename($filename);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($file));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    readfile($file);
    exit;
}

function arf_html_page(string $title, string $message, ?string $downloadUrl = null, string $details = ''): never
{
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= arf_h($title) ?></title>
    <?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <style>body{margin:0;background:#f5f7fb;color:#172033;font:14px/1.6 Arial,sans-serif}.box{max-width:760px;margin:8vh auto;background:#fff;border:1px solid #e3e8f0;border-radius:16px;padding:28px;box-shadow:0 12px 35px rgba(20,35,60,.08)}h1{margin:0 0 10px;font-size:22px}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;padding:10px 15px;border-radius:9px;background:#1f5eff;color:#fff;text-decoration:none}.btn.secondary{background:#eef2f7;color:#172033}.details{white-space:pre-wrap;margin-top:18px;padding:12px;background:#f8fafc;border-radius:9px;color:#667085}@media(max-width:600px){.box{margin:12px;padding:18px}.actions .btn{width:100%;justify-content:center}}</style></head><body><div class="box"><h1><?= arf_h($title) ?></h1><p><?= arf_h($message) ?></p><?php if($downloadUrl): ?><div class="actions"><a class="btn" href="<?= arf_h($downloadUrl) ?>">Download Original File</a><a class="btn secondary" href="resources.php">Go Back</a></div><?php endif; ?><?php if($details!==''): ?><div class="details"><?= arf_h($details) ?></div><?php endif; ?></div></body></html><?php exit;
}

function arf_render_html_document(string $title, string $body, string $downloadUrl): never
{
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= arf_h($title) ?></title>
    <?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <style>body{margin:0;background:#eef2f6;color:#111827;font:14px/1.55 Arial,sans-serif}.top{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid #dfe5ec;padding:10px 16px;display:flex;align-items:center;gap:12px}.top strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}.top a{background:#1f5eff;color:#fff;text-decoration:none;padding:8px 12px;border-radius:8px}.paper{max-width:1100px;margin:18px auto;background:#fff;padding:30px;box-shadow:0 4px 18px rgba(0,0,0,.08);overflow:auto}.paper img{max-width:100%;height:auto}.paper table{border-collapse:collapse;max-width:100%}.paper td,.paper th{border:1px solid #d5dbe3;padding:6px;vertical-align:top}@media(max-width:700px){.top{padding:8px}.paper{margin:0;padding:14px;box-shadow:none}.top a{padding:7px 9px}}</style></head><body><div class="top"><strong><?= arf_h($title) ?></strong><a href="<?= arf_h($downloadUrl) ?>">Download</a></div><main class="paper"><?= $body ?></main></body></html><?php exit;
}

function arf_office_preview(string $file, string $ext, string $title, string $downloadUrl): array
{
    $vendor = arf_load_vendor();
    if (!$vendor['loaded']) return ['ok'=>false,'html'=>'','error'=>'Composer vendor/autoload.php was not found.'];

    try {
        if (in_array($ext, ['xlsx','xls','ods'], true)) {
            if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) return ['ok'=>false,'html'=>'','error'=>'PhpSpreadsheet is not installed in vendor. Run: composer require phpoffice/phpspreadsheet'];
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            ob_start(); $writer->save('php://output'); $html=(string)ob_get_clean();
            if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) $html=$m[1];
            return ['ok'=>true,'html'=>$html,'error'=>''];
        }
        if (in_array($ext, ['docx','odt'], true)) {
            if (!class_exists('PhpOffice\\PhpWord\\IOFactory')) return ['ok'=>false,'html'=>'','error'=>'PHPWord is not installed in vendor. Run: composer require phpoffice/phpword'];
            $reader = \PhpOffice\PhpWord\IOFactory::load($file);
            $tmp = tempnam(sys_get_temp_dir(), 'arf_word_') . '.html';
            \PhpOffice\PhpWord\IOFactory::createWriter($reader, 'HTML')->save($tmp);
            $html=(string)@file_get_contents($tmp); @unlink($tmp);
            if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) $html=$m[1];
            return ['ok'=>true,'html'=>$html,'error'=>''];
        }
        if (in_array($ext, ['pptx','odp'], true)) {
            if (!class_exists('PhpOffice\\PhpPresentation\\IOFactory')) return ['ok'=>false,'html'=>'','error'=>'PHPPresentation is not installed in vendor. Run: composer require phpoffice/phppresentation'];
            $presentation = \PhpOffice\PhpPresentation\IOFactory::load($file);
            $out='<div style="display:grid;gap:18px">'; $n=0;
            foreach ($presentation->getAllSlides() as $slide) { $n++; $out.='<section style="border:1px solid #d9e0e8;border-radius:10px;padding:18px"><h3>Slide '.$n.'</h3>';
                foreach ($slide->getShapeCollection() as $shape) { if (method_exists($shape,'getPlainText')) { $text=trim((string)$shape->getPlainText()); if($text!=='') $out.='<p>'.nl2br(arf_h($text)).'</p>'; } }
                $out.='</section>'; }
            $out.='</div>'; return ['ok'=>true,'html'=>$out,'error'=>''];
        }
        return ['ok'=>false,'html'=>'','error'=>'This Office format has no safe PHP preview reader.'];
    } catch (Throwable $e) {
        error_log('[Resource preview] ' . $e->getMessage());
        return ['ok'=>false,'html'=>'','error'=>'The PHP document reader could not open this file: ' . $e->getMessage()];
    }
}

function arf_detect_venture_id_from_session(): ?int
{
    $keys = [
        'venture_id',
        'startup_id',
        'applicant_venture_id'
    ];

    foreach ($keys as $key) {
        if (!empty($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
            return (int)$_SESSION[$key];
        }
    }

    return null;
}

function arf_detect_name_from_session(): ?string
{
    $keys = [
        'venture_name',
        'startup_name',
        'full_name',
        'name',
        'admin_name',
        'fname'
    ];

    foreach ($keys as $key) {
        if (!empty($_SESSION[$key])) {
            return (string)$_SESSION[$key];
        }
    }

    if (!empty($_SESSION['fname']) || !empty($_SESSION['lname'])) {
        return trim((string)($_SESSION['fname'] ?? '') . ' ' . (string)($_SESSION['lname'] ?? ''));
    }

    return null;
}

function arf_detect_email_from_session(): ?string
{
    $keys = [
        'venture_email',
        'startup_email',
        'email',
        'admin_email'
    ];

    foreach ($keys as $key) {
        if (!empty($_SESSION[$key])) {
            return (string)$_SESSION[$key];
        }
    }

    return null;
}

function arf_log_download(mysqli $conn, int $resourceId): void
{
    arf_create_download_log_table($conn);

    $ventureId = arf_detect_venture_id_from_session();
    $name      = arf_detect_name_from_session();
    $email     = arf_detect_email_from_session();

    if ($ventureId !== null && (empty($name) || empty($email)) && arf_table_exists($conn, 'ventures')) {
        $stmt = $conn->prepare("
            SELECT name, cofounder1_email, cofounder2_email
            FROM ventures
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $ventureId);
            $stmt->execute();
            $venture = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($venture) {
                if (empty($name)) {
                    $name = (string)($venture['name'] ?? '');
                }

                if (empty($email)) {
                    $email = (string)($venture['cofounder1_email'] ?: $venture['cofounder2_email'] ?: '');
                }
            }
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmtLog = $conn->prepare("
        INSERT INTO resource_download_logs
            (resource_id, venture_id, downloaded_by_name, downloaded_by_email, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($stmtLog) {
        $stmtLog->bind_param(
            'iissss',
            $resourceId,
            $ventureId,
            $name,
            $email,
            $ip,
            $ua
        );

        $stmtLog->execute();
        $stmtLog->close();
    }

    $stmtUp = $conn->prepare("
        UPDATE resources
        SET download_count = COALESCE(download_count, 0) + 1
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmtUp) {
        $stmtUp->bind_param('i', $resourceId);
        $stmtUp->execute();
        $stmtUp->close();
    }
}

$stmt = $conn->prepare("
    SELECT id, title, file_path, file_name, type, access_level, status
    FROM resources
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Database error.');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res || empty($res['file_path'])) {
    http_response_code(404);
    arf_html_page('Resource File Not Found', 'This resource has no uploaded file attached.');
}

if (($res['status'] ?? '') !== 'active') {
    http_response_code(403);
    arf_html_page('Resource Not Available', 'This resource is currently not available.');
}

$baseDir = realpath(__DIR__ . '/../');

if (!$baseDir) {
    http_response_code(500);
    exit('Server path error.');
}

$fileRel = str_replace(['../', '..\\'], '', (string)$res['file_path']);
$fileRel = ltrim($fileRel, '/\\');

$fileAbs = realpath($baseDir . DIRECTORY_SEPARATOR . $fileRel);

if (!$fileAbs || !str_starts_with($fileAbs, $baseDir) || !is_file($fileAbs)) {
    http_response_code(404);
    arf_html_page(
        'File Missing',
        'The uploaded file is missing on the server. Please re-upload this resource.'
    );
}

$originalName = (string)($res['file_name'] ?: basename($fileAbs));
$ext          = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
$mime         = arf_mime($ext);

if ($mode === 'download') {
    arf_log_download($conn, (int)$res['id']);
    arf_send_file($fileAbs, $originalName, $mime, false);
}

$inlineExt = [
    'pdf','jpg','jpeg','png','gif','webp','svg','mp4','webm','mov','mp3','wav','ogg','txt','csv','json','xml'
];

if ($mode === 'view' && in_array($ext, $inlineExt, true)) {
    arf_send_file($fileAbs, $originalName, $mime, true);
}

$officeExt = ['doc','docx','ppt','pptx','xls','xlsx','odt','ods','odp'];

if (in_array($ext, $officeExt, true) && in_array($mode, ['view','pdf'], true)) {
    $downloadUrl = 'resource-file.php?id=' . (int)$id . '&mode=download';
    $preview = arf_office_preview($fileAbs, $ext, $originalName, $downloadUrl);
    if ($preview['ok']) {
        arf_render_html_document($originalName, $preview['html'], $downloadUrl);
    }
    arf_html_page(
        'Preview Not Available',
        'This Office document cannot be previewed with the PHP vendor packages currently installed. You can still download the original file.',
        $downloadUrl,
        $preview['error']
    );
}

if ($mode === 'pdf') {
    arf_html_page('Preview Not Available', 'This file type cannot be previewed as a document. Please download the original file.', 'resource-file.php?id=' . (int)$id . '&mode=download');
}

arf_send_file($fileAbs, $originalName, $mime, false);
