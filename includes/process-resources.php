<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Africa/Nairobi');

function pr_base_url(): string
{
    return defined('SITE_URL') && SITE_URL !== '' ? rtrim((string)SITE_URL, '/') : '';
}

function pr_redirect(string $url): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Location: ' . $url);
    exit;
}

function pr_flash(string $message, string $type = 'success'): void
{
    $_SESSION['dash_resource_flash'] = $message;
    $_SESSION['dash_resource_flash_type'] = $type;
}

function pr_flash_redirect(string $url, string $message, string $type = 'success'): void
{
    pr_flash($message, $type);
    pr_redirect($url);
}

function pr_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS total\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND COLUMN_NAME = ?\n    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function pr_file_path_candidates(string $path): array
{
    $path = trim($path);

    if ($path === '') {
        return [];
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^https?://[^/]+/#i', '', $path);
    $path = ltrim($path, '/');

    $root = dirname(__DIR__);
    $parent = dirname($root);
    $base = basename($path);

    return array_values(array_unique([
        $root . '/' . $path,
        $root . '/uploads/resources/' . $base,
        $root . '/uploads/resource/' . $base,
        $root . '/assets/uploads/resources/' . $base,
        $root . '/assets/uploads/resource/' . $base,
        $root . '/admin/uploads/resources/' . $base,
        $root . '/admin/uploads/resource/' . $base,
        $parent . '/' . $path,
        $parent . '/uploads/resources/' . $base,
        $parent . '/uploads/resource/' . $base,
    ]));
}

function pr_resolve_file_path(string $path): string
{
    foreach (pr_file_path_candidates($path) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function pr_download_file(string $filePath, string $downloadName): void
{
    if (!is_file($filePath)) {
        pr_flash_redirect(
            '../venture_resources.php',
            'Resource file not found on server. Please contact admin to re-upload this file.',
            'error'
        );
    }

    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    $view = isset($_GET['view']) && $_GET['view'] === '1';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    if ($view) {
        header('Content-Disposition: inline; filename="' . addslashes($downloadName) . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    }

    readfile($filePath);
    exit;
}

function pr_generate_pdf_message(string $title, string $message): void
{
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body{font-family:DejaVu Sans,sans-serif;color:#111827;padding:35px}
        .box{border:1px solid #e5e7eb;border-radius:12px;padding:24px}
        h1{font-size:22px;margin:0 0 12px;color:#111827}
        p{font-size:14px;line-height:1.6;color:#374151}
    </style></head><body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('resource-message.pdf', ['Attachment' => false]);
    exit;
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$has_request_venture_id = pr_column_exists($conn, 'resource_requests', 'venture_id');
$has_download_token = pr_column_exists($conn, 'resource_requests', 'download_token');
$has_token_expires = pr_column_exists($conn, 'resource_requests', 'token_expires');

/* =========================================================
   REQUEST ACCESS / RESEND REQUEST
   - Approved access is permanent.
   - Pending/rejected/expired requests can be resent.
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'request_access') {
    $resource_id = (int)($_POST['resource_id'] ?? 0);

    if (empty($_SESSION['venture_id'])) {
        $redirect = 'venture_resources.php';

        if ($resource_id > 0) {
            $redirect .= '?request_resource_id=' . $resource_id;
        }

        pr_redirect(pr_base_url() . '/login.php?redirect=' . urlencode($redirect));
    }

    $venture_id = (int)$_SESSION['venture_id'];
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($resource_id <= 0) {
        pr_flash_redirect('../venture_resources.php', 'Invalid resource selected.', 'error');
    }

    if ($reason === '') {
        pr_flash_redirect(
            '../venture_resources.php?request_resource_id=' . $resource_id,
            'Please provide a reason for requesting this resource.',
            'error'
        );
    }

    $stmt = $conn->prepare("\n        SELECT id, name, email\n        FROM ventures\n        WHERE id = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $venture = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venture) {
        pr_flash_redirect('../login.php', 'Venture account not found. Please login again.', 'error');
    }

    $requester_name = (string)$venture['name'];
    $requester_email = (string)$venture['email'];
    $requester_org = (string)$venture['name'];

    $stmt = $conn->prepare("\n        SELECT id, title, access_level, status\n        FROM resources\n        WHERE id = ?\n          AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $resource_id);
    $stmt->execute();
    $resource = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$resource) {
        pr_flash_redirect('../venture_resources.php', 'Resource not found or inactive.', 'error');
    }

    if (($resource['access_level'] ?? '') === 'public') {
        pr_flash_redirect('../venture_resources.php', 'This resource is public and does not require approval.', 'success');
    }

    if ($has_request_venture_id) {
        $stmt = $conn->prepare("\n            SELECT id, status\n            FROM resource_requests\n            WHERE resource_id = ?\n              AND (venture_id = ? OR requester_email = ?)\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->bind_param('iis', $resource_id, $venture_id, $requester_email);
    } else {
        $stmt = $conn->prepare("\n            SELECT id, status\n            FROM resource_requests\n            WHERE resource_id = ?\n              AND requester_email = ?\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->bind_param('is', $resource_id, $requester_email);
    }

    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && (string)$existing['status'] === 'approved') {
        pr_flash_redirect('../venture_resources.php', 'Your request for this resource is already approved. You can view or download it now.', 'success');
    }

    if ($existing) {
        $existing_id = (int)$existing['id'];

        $set = "requester_name = ?, requester_email = ?, requester_org = ?, reason = ?, status = 'pending', updated_at = NOW()";
        if ($has_download_token) {
            $set .= ", download_token = NULL";
        }
        if ($has_token_expires) {
            $set .= ", token_expires = NULL";
        }

        $stmt = $conn->prepare("UPDATE resource_requests SET {$set} WHERE id = ? LIMIT 1");
        if (!$stmt) {
            pr_flash_redirect('../venture_resources.php', 'Failed to prepare resend request: ' . $conn->error, 'error');
        }

        $stmt->bind_param('ssssi', $requester_name, $requester_email, $requester_org, $reason, $existing_id);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            pr_flash_redirect('../venture_resources.php?request_resource_id=' . $resource_id, 'Failed to resend request: ' . $error, 'error');
        }

        $stmt->close();

        pr_flash_redirect(
            '../venture_resources.php',
            'Your resource access request has been resent successfully and is pending approval.',
            'success'
        );
    }

    $status = 'pending';

    if ($has_request_venture_id) {
        $stmt = $conn->prepare("\n            INSERT INTO resource_requests\n            (resource_id, venture_id, requester_name, requester_email, requester_org, reason, status, created_at, updated_at)\n            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n        ");

        if (!$stmt) {
            pr_flash_redirect('../venture_resources.php', 'Failed to prepare request: ' . $conn->error, 'error');
        }

        $stmt->bind_param('iisssss', $resource_id, $venture_id, $requester_name, $requester_email, $requester_org, $reason, $status);
    } else {
        $stmt = $conn->prepare("\n            INSERT INTO resource_requests\n            (resource_id, requester_name, requester_email, requester_org, reason, status, created_at, updated_at)\n            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())\n        ");

        if (!$stmt) {
            pr_flash_redirect('../venture_resources.php', 'Failed to prepare request: ' . $conn->error, 'error');
        }

        $stmt->bind_param('isssss', $resource_id, $requester_name, $requester_email, $requester_org, $reason, $status);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        pr_flash_redirect('../venture_resources.php?request_resource_id=' . $resource_id, 'Failed to submit request: ' . $error, 'error');
    }

    $stmt->close();

    pr_flash_redirect('../venture_resources.php', 'Your resource access request has been submitted successfully.', 'success');
}

/* =========================================================
   DOWNLOAD / VIEW RESOURCE
   IMPORTANT: approved status does NOT expire.
========================================================= */
if ($action === 'download') {
    $resource_id = (int)($_GET['resource_id'] ?? 0);
    $token = trim((string)($_GET['token'] ?? ''));

    if ($resource_id <= 0) {
        pr_flash_redirect('../venture_resources.php', 'Invalid resource selected.', 'error');
    }

    $stmt = $conn->prepare("\n        SELECT *\n        FROM resources\n        WHERE id = ?\n          AND status = 'active'\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $resource_id);
    $stmt->execute();
    $resource = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$resource) {
        pr_flash_redirect('../venture_resources.php', 'Resource not found or inactive.', 'error');
    }

    $access_level = (string)($resource['access_level'] ?? 'request_required');
    $can_download = $access_level === 'public';

    if (!$can_download && !empty($_SESSION['venture_id'])) {
        $venture_id = (int)$_SESSION['venture_id'];

        if ($has_request_venture_id) {
            $stmt = $conn->prepare("\n                SELECT id\n                FROM resource_requests\n                WHERE resource_id = ?\n                  AND venture_id = ?\n                  AND status = 'approved'\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $stmt->bind_param('ii', $resource_id, $venture_id);
        } else {
            $stmt_v = $conn->prepare("\n                SELECT email\n                FROM ventures\n                WHERE id = ?\n                LIMIT 1\n            ");
            $stmt_v->bind_param('i', $venture_id);
            $stmt_v->execute();
            $venture = $stmt_v->get_result()->fetch_assoc();
            $stmt_v->close();

            $requester_email = (string)($venture['email'] ?? '');

            $stmt = $conn->prepare("\n                SELECT id\n                FROM resource_requests\n                WHERE resource_id = ?\n                  AND requester_email = ?\n                  AND status = 'approved'\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $stmt->bind_param('is', $resource_id, $requester_email);
        }

        $stmt->execute();
        $approved = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($approved) {
            $can_download = true;
        }
    }

    if (!$can_download && $token !== '' && $has_download_token) {
        $stmt = $conn->prepare("\n            SELECT id\n            FROM resource_requests\n            WHERE resource_id = ?\n              AND download_token = ?\n              AND status = 'approved'\n            LIMIT 1\n        ");
        $stmt->bind_param('is', $resource_id, $token);
        $stmt->execute();
        $token_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($token_row) {
            $can_download = true;
        }
    }

    if (!$can_download) {
        pr_flash_redirect('../venture_resources.php', 'You do not have permission to download this resource.', 'error');
    }

    $file_path = trim((string)($resource['file_path'] ?? ''));

    if ($file_path === '') {
        pr_flash_redirect('../venture_resources.php', 'Resource file path is missing.', 'error');
    }

    $absolute_path = pr_resolve_file_path($file_path);

    if ($absolute_path === '') {
        pr_flash_redirect('../venture_resources.php', 'Resource file not found on server. Please contact admin to re-upload this file.', 'error');
    }

    $stmt = $conn->prepare("UPDATE resources SET download_count = COALESCE(download_count, 0) + 1 WHERE id = ?");
    $stmt->bind_param('i', $resource_id);
    $stmt->execute();
    $stmt->close();

    $download_name = trim((string)($resource['file_name'] ?? ''));

    if ($download_name === '') {
        $download_name = basename($absolute_path);
    }

    pr_download_file($absolute_path, $download_name);
}

/* =========================================================
   LEGACY TOKEN DOWNLOAD
   IMPORTANT: approved token link does NOT expire.
========================================================= */
if (!empty($_GET['download']) && $has_download_token) {
    $token = trim((string)$_GET['download']);

    $stmt = $conn->prepare("\n        SELECT rr.*, r.file_path, r.file_name, r.status AS resource_status\n        FROM resource_requests rr\n        INNER JOIN resources r ON r.id = rr.resource_id\n        WHERE rr.download_token = ?\n          AND rr.status = 'approved'\n          AND r.status = 'active'\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $dl = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$dl) {
        pr_flash_redirect('../venture_resources.php', 'Invalid download link.', 'error');
    }

    $absolute_path = pr_resolve_file_path((string)$dl['file_path']);

    if ($absolute_path === '') {
        pr_flash_redirect('../venture_resources.php', 'Resource file not found on server. Please contact admin to re-upload this file.', 'error');
    }

    $resource_id = (int)$dl['resource_id'];

    $stmt = $conn->prepare("UPDATE resources SET download_count = COALESCE(download_count, 0) + 1 WHERE id = ?");
    $stmt->bind_param('i', $resource_id);
    $stmt->execute();
    $stmt->close();

    $download_name = trim((string)($dl['file_name'] ?? ''));

    if ($download_name === '') {
        $download_name = basename($absolute_path);
    }

    pr_download_file($absolute_path, $download_name);
}

if ($action === 'pdf_message') {
    pr_generate_pdf_message('Resource Message', 'No resource action was completed.');
}

http_response_code(400);
die('Invalid resource action.');
