<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection unavailable.');
}
$conn->set_charset('utf8mb4');

function bem_redirect(): never
{
    header('Location: ../bulk-emails.php');
    exit;
}

function bem_flash(string $msg, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('bulk_email', $msg, $type);
        return;
    }
    $_SESSION['bulk_email_flash'] = ['msg' => $msg, 'type' => $type];
}

function bem_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bem_render_html(string $plainText): string
{
    $escaped = bem_h($plainText);
    $paragraphs = preg_split('/\n\s*\n/', $escaped) ?: [$escaped];
    $html = array_map(
        static fn(string $p): string => '<p style="margin:0 0 14px;line-height:1.65">' . nl2br($p) . '</p>',
        array_filter($paragraphs, static fn(string $p): bool => trim($p) !== '')
    );
    return implode('', $html) ?: '<p></p>';
}

function bem_personalize(string $template, string $name, string $email, string $type): string
{
    return str_replace(['{{name}}', '{{email}}', '{{type}}'], [$name, $email, $type], $template);
}

function bem_ensure_log_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS bulk_email_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id VARCHAR(40) NOT NULL,
        subject VARCHAR(180) NOT NULL,
        recipient_type VARCHAR(20) NOT NULL,
        recipient_id INT UNSIGNED NOT NULL,
        recipient_name VARCHAR(255) NOT NULL DEFAULT '',
        recipient_email VARCHAR(255) NOT NULL,
        status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
        error_message TEXT NULL,
        sent_by_name VARCHAR(255) NOT NULL DEFAULT '',
        attachment_count INT UNSIGNED NOT NULL DEFAULT 0,
        link_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'attachment_count' => "ALTER TABLE bulk_email_logs ADD COLUMN attachment_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER sent_by_name",
        'link_count' => "ALTER TABLE bulk_email_logs ADD COLUMN link_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attachment_count",
    ] as $column => $sql) {
        $escaped = $conn->real_escape_string($column);
        $check = $conn->query("SHOW COLUMNS FROM bulk_email_logs LIKE '{$escaped}'");
        if ($check && $check->num_rows === 0) {
            $conn->query($sql);
        }
    }
}

/** @return array<int,array{label:string,url:string}> */
function bem_parse_links(string $raw): array
{
    $links = [];
    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 2));
        $label = count($parts) === 2 ? $parts[0] : '';
        $url = count($parts) === 2 ? $parts[1] : $parts[0];
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) continue;
        $links[] = ['label' => $label !== '' ? mb_substr($label, 0, 120) : $url, 'url' => $url];
        if (count($links) >= 10) break;
    }
    return $links;
}

function bem_links_html(array $links): string
{
    if (!$links) return '';
    $items = '';
    foreach ($links as $link) {
        $items .= '<li style="margin:0 0 8px"><a href="' . bem_h($link['url']) . '" target="_blank" rel="noopener noreferrer" style="color:#ea580c;text-decoration:underline">' . bem_h($link['label']) . '</a></li>';
    }
    return '<div style="margin:22px 0 0;padding:16px 18px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc"><strong style="display:block;margin-bottom:10px">Related links</strong><ul style="margin:0;padding-left:20px">' . $items . '</ul></div>';
}

function bem_links_plain(array $links): string
{
    if (!$links) return '';
    $lines = ["", "Related links:"];
    foreach ($links as $link) $lines[] = '- ' . $link['label'] . ': ' . $link['url'];
    return implode("\n", $lines);
}

/** @return array<int,array{path:string,name:string}> */
function bem_prepare_attachments(array $files, string $campaignId): array
{
    $allowedExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','csv','txt','jpg','jpeg','png','webp','zip'];
    $allowedMime = [
        'application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/csv','text/plain','image/jpeg','image/png','image/webp','application/zip','application/x-zip-compressed',
        'application/octet-stream'
    ];

    $names = (array)($files['name'] ?? []);
    $tmpNames = (array)($files['tmp_name'] ?? []);
    $sizes = (array)($files['size'] ?? []);
    $errors = (array)($files['error'] ?? []);
    if (count(array_filter($names, static fn($v) => (string)$v !== '')) > 5) {
        throw new RuntimeException('A maximum of 5 file attachments is allowed.');
    }

    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bulk-email-' . preg_replace('/[^a-z0-9]/i', '', $campaignId);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create a temporary attachment directory.');
    }

    $prepared = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $i => $originalName) {
        $originalName = trim((string)$originalName);
        if ($originalName === '') continue;
        $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for ' . $originalName . ' (error ' . $error . ').');
        $size = (int)($sizes[$i] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException($originalName . ' must be between 1 byte and 10 MB.');
        $tmp = (string)($tmpNames[$i] ?? '');
        if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file: ' . $originalName);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('Unsupported attachment type: ' . $originalName);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        if (!in_array($mime, $allowedMime, true)) throw new RuntimeException('The file type for ' . $originalName . ' is not allowed.');
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
        $dest = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)) . '_' . $safeBase;
        if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Failed to store attachment: ' . $originalName);
        $prepared[] = ['path' => $dest, 'name' => $safeBase];
    }
    return $prepared;
}

function bem_cleanup_attachments(array $attachments): void
{
    $dirs = [];
    foreach ($attachments as $attachment) {
        $path = (string)($attachment['path'] ?? '');
        if ($path !== '' && is_file($path)) @unlink($path);
        if ($path !== '') $dirs[dirname($path)] = true;
    }
    foreach (array_keys($dirs) as $dir) @rmdir($dir);
}

function bem_send_with_attachments(string $to, string $subject, string $html, string $plain, array $attachments): mixed
{
    if (!$attachments) return sendEmail($to, $subject, $html, $plain);
    $reflection = new ReflectionFunction('sendEmail');
    if ($reflection->getNumberOfParameters() < 5 && !$reflection->isVariadic()) {
        throw new RuntimeException('The current sendEmail() helper does not support file attachments. Update mail-function.php to accept a fifth attachments argument.');
    }
    $payload = array_map(static fn(array $a): array => ['path' => $a['path'], 'name' => $a['name']], $attachments);
    return sendEmail($to, $subject, $html, $plain, $payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bem_redirect();
if (!hash_equals((string)($_SESSION['bulk_email_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    bem_flash('Your session token expired. Refresh the page and try again.', 'error');
    bem_redirect();
}
if (trim((string)($_POST['action'] ?? '')) !== 'send_bulk_email') {
    bem_flash('Invalid action.', 'error');
    bem_redirect();
}

$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$confirmed = isset($_POST['confirm_bulk_send']);
$mentorIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['mentor_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$ventureIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['venture_ids'] ?? [])), static fn(int $id): bool => $id > 0)));

if (!$confirmed || $subject === '' || $message === '' || (!$mentorIds && !$ventureIds)) {
    bem_flash('Complete the subject, message, recipients, and confirmation before sending.', 'error');
    bem_redirect();
}

$campaignId = bin2hex(random_bytes(12));
$links = bem_parse_links((string)($_POST['attachment_links'] ?? ''));
$attachments = [];
try {
    $attachments = bem_prepare_attachments((array)($_FILES['attachments'] ?? []), $campaignId);
} catch (Throwable $e) {
    bem_flash($e->getMessage(), 'error');
    bem_redirect();
}

$recipients = [];
if ($mentorIds) {
    $in = implode(',', $mentorIds);
    $res = $conn->query("SELECT id, full_name, email FROM mentors WHERE id IN ($in) AND email IS NOT NULL AND email <> ''");
    if ($res) while ($row = $res->fetch_assoc()) $recipients[] = ['type'=>'mentor','id'=>(int)$row['id'],'name'=>(string)$row['full_name'],'email'=>trim((string)$row['email'])];
}
if ($ventureIds) {
    $in = implode(',', $ventureIds);
    $res = $conn->query("SELECT v.id,v.name,COALESCE(NULLIF(vp.email,''),NULLIF(v.cofounder1_email,''),NULLIF(v.email,'')) email FROM ventures v LEFT JOIN venture_portal_access vp ON vp.venture_id=v.id WHERE v.id IN ($in) HAVING email IS NOT NULL AND email<>''");
    if ($res) while ($row = $res->fetch_assoc()) $recipients[] = ['type'=>'venture','id'=>(int)$row['id'],'name'=>(string)$row['name'],'email'=>trim((string)$row['email'])];
}

$deduped = [];
foreach ($recipients as $r) {
    $key = mb_strtolower($r['email']);
    if (!isset($deduped[$key])) $deduped[$key] = $r;
}
$recipients = array_values($deduped);
if (!$recipients) {
    bem_cleanup_attachments($attachments);
    bem_flash('None of the selected recipients has a valid email address.', 'error');
    bem_redirect();
}
if (!function_exists('sendEmail')) {
    bem_cleanup_attachments($attachments);
    bem_flash('Email sending is not configured on this server.', 'error');
    bem_redirect();
}

bem_ensure_log_table($conn);
$sentBy = (string)($GLOBALS['ADMIN']['full_name'] ?? 'Administrator');
$log = $conn->prepare("INSERT INTO bulk_email_logs (campaign_id,subject,recipient_type,recipient_id,recipient_name,recipient_email,status,error_message,sent_by_name,attachment_count,link_count) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$sent = 0; $failed = 0;
$linkHtml = bem_links_html($links);
$linkPlain = bem_links_plain($links);

foreach ($recipients as $recipient) {
    $typeLabel = $recipient['type'] === 'mentor' ? 'Mentor' : 'Venture';
    $plain = bem_personalize($message, $recipient['name'], $recipient['email'], $typeLabel) . $linkPlain;
    $html = bem_render_html(bem_personalize($message, $recipient['name'], $recipient['email'], $typeLabel)) . $linkHtml;
    $body = function_exists('email_wrapper') ? email_wrapper($html) : $html;
    $status = 'failed'; $error = null;
    try {
        $result = bem_send_with_attachments($recipient['email'], $subject, $body, $plain, $attachments);
        if ($result === true) { $status = 'sent'; $sent++; }
        else { $failed++; $error = is_string($result) ? $result : 'sendEmail() returned a falsy result.'; }
    } catch (Throwable $e) { $failed++; $error = $e->getMessage(); }

    if ($log) {
        $attachmentCount = count($attachments); $linkCount = count($links);
        $log->bind_param('sssisssssii', $campaignId, $subject, $recipient['type'], $recipient['id'], $recipient['name'], $recipient['email'], $status, $error, $sentBy, $attachmentCount, $linkCount);
        $log->execute();
    }
}
if ($log) $log->close();
bem_cleanup_attachments($attachments);

$total = $sent + $failed;
$extra = [];
if ($attachments) $extra[] = count($attachments) . ' file attachment(s)';
if ($links) $extra[] = count($links) . ' hyperlink(s)';
$extraText = $extra ? ' Included ' . implode(' and ', $extra) . '.' : '';
if ($sent > 0 && $failed === 0) bem_flash("Campaign sent to all {$sent} recipient(s).{$extraText}", 'success');
elseif ($sent > 0) bem_flash("Campaign sent: {$sent} delivered and {$failed} failed out of {$total}.{$extraText}", 'warning');
else bem_flash("Campaign failed for all {$total} recipient(s). Check the mail configuration and attachment support.", 'error');

bem_redirect();
