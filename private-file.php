<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/private-files.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$path = $_GET['path'] ?? '';
if (!is_string($path) || ($file = site_private_resolve($path, __DIR__)) === null) {
    http_response_code(404);
    exit('File not found.');
}
$record = site_private_record($conn, $path);
$principal = [];
if (!empty($_SESSION['admin_id'])) {
    require_once __DIR__ . '/admin/includes/auth.php';
    // A mentor's permission to open a page never grants access to all its records.
    if (!ms_is_mentor()) {
        foreach (site_private_permissions((string)site_private_group($path)) as $permission) {
            foreach (array_unique([$permission, str_replace('-', '_', $permission), $permission . '.php']) as $key) {
                if (admin_has_permission($conn, $key)) {
                    $principal['staff_permission'] = true;
                    break 2;
                }
            }
        }
    }
    $principal['mentor_id'] = ms_mentor_record_id($conn);
    if ($principal['mentor_id'] > 0) {
        $mentorId = (int)$principal['mentor_id'];
        $adminId = (int)$ADMIN['id'];
        $email = (string)$ADMIN['email'];
        $check = $conn->prepare("SELECT id FROM mentors WHERE id=? AND status='active' AND (user_id=? OR email=?) LIMIT 1");
        $check->bind_param('iis', $mentorId, $adminId, $email);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) $principal['mentor_id'] = 0;
        $check->close();
    }
    if ($record && !empty($record['session_id']) && $principal['mentor_id'] > 0) {
        $principal['session_member'] = ms_owns_session($conn, (int)$record['session_id'], $principal['mentor_id']);
    }
} elseif (!empty($_SESSION['venture_id']) && !empty($_SESSION['venture_login'])) {
    $ventureId = (int)$_SESSION['venture_id'];
    $stmt = $conn->prepare("SELECT id FROM ventures WHERE id=? AND status IN ('active', 'approved') LIMIT 1");
    $stmt->bind_param('i', $ventureId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $principal['venture_id'] = $ventureId;
        if ($record && isset($record['resource_id'])) {
            $principal['resource_approved'] = site_private_resource_approved($conn, (int)$record['resource_id'], $ventureId);
        }
    }
    $stmt->close();
}
if (!$record || !site_private_can_read($record, $principal)) {
    http_response_code(404);
    exit('File not found.');
}
while (ob_get_level()) {
    ob_end_clean();
}
session_write_close();
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$name = preg_replace('/[^A-Za-z0-9_. -]/', '_', basename($file));
header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Length: ' . filesize($file));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($file);
}
