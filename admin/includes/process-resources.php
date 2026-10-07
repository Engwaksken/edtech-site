<?php
declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('DB error.');
}

$conn->set_charset('utf8mb4');

function res_redirect(?string $msg = null, string $type = 'success', string $tab = 'resources'): void
{
    if ($msg && function_exists('flash')) {
        flash('resources', $msg, $type);
    }

    header("Location: ../resources.php?tab=" . urlencode($tab));
    exit;
}

function res_current_admin_id(): int
{
    foreach (['admin_id', 'user_id', 'id'] as $key) {
        if (!empty($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }

    return 0;
}

function res_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $q = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    return $q && $q->num_rows > 0;
}

function res_save_setting(mysqli $conn, string $key, string $value): bool
{
    $stmt = $conn->prepare("
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function res_get_setting(mysqli $conn, string $key, string $default = ''): string
{
    if (function_exists('get_setting')) {
        return (string)get_setting($conn, $key, $default);
    }

    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string)($row['setting_value'] ?? $default);
}

function res_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large. Increase upload_max_filesize and post_max_size.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        default => 'Unknown upload error.',
    };
}

function res_ensure_dir(string $dir): bool
{
    if (!is_dir($dir)) {
        return mkdir($dir, 0775, true);
    }

    return is_writable($dir);
}

function res_safe_upload(
    string $fileKey,
    string $relativeDir,
    array $allowedExt,
    string $prefix,
    string &$originalName = '',
    int &$fileSize = 0,
    string &$err = ''
): string {
    if (empty($_FILES[$fileKey]['name'])) {
        return '';
    }

    if (!isset($_FILES[$fileKey]['error']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        $err = res_upload_error_message((int)($_FILES[$fileKey]['error'] ?? -1));
        return '';
    }

    $originalName = basename((string)$_FILES[$fileKey]['name']);
    $fileSize = (int)$_FILES[$fileKey]['size'];

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        $err = 'File type not allowed. Supported formats include PDF, images, video, DOC/DOCX/ODT/RTF, XLS/XLSX/ODS/CSV, PPT/PPTX, ZIP and TXT.';
        return '';
    }

    $relativeDir = trim($relativeDir, '/');
    $baseDir = realpath(__DIR__ . '/../../');

    if (!$baseDir) {
        $err = 'Server base path not found.';
        return '';
    }

    $targetDir = $baseDir . '/' . $relativeDir . '/';

    if (!res_ensure_dir($targetDir)) {
        $err = 'Upload folder is not writable: ' . $relativeDir;
        return '';
    }

    $safeName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $targetDir . $safeName;

    if (!is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
        $err = 'Invalid uploaded file.';
        return '';
    }

    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
        error_log('Resource upload failed: ' . $target);
        error_log(print_r($_FILES[$fileKey], true));
        $err = 'Unable to save uploaded file. Check folder permissions.';
        return '';
    }

    @chmod($target, 0644);

    return $relativeDir . '/' . $safeName;
}

function res_delete_file(string $relativePath): void
{
    $relativePath = trim($relativePath);

    if ($relativePath === '') {
        return;
    }

    $baseDir = realpath(__DIR__ . '/../../');

    if (!$baseDir) {
        return;
    }

    $file = realpath($baseDir . '/' . ltrim($relativePath, '/'));

    if ($file && str_starts_with($file, $baseDir) && is_file($file)) {
        @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    res_redirect('Invalid request.', 'error');
}

$action = $_POST['action'] ?? '';

if ($action === 'save_hero_banner') {
    $err = '';

    $bg_type = trim($_POST['resources_hero_bg_type'] ?? 'color');
    if (!in_array($bg_type, ['color', 'image', 'video'], true)) {
        $bg_type = 'color';
    }

    $bg_color = trim($_POST['resources_hero_bg_color'] ?? '#fff5eb');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bg_color)) {
        $bg_color = '#fff5eb';
    }

    $overlay_on = isset($_POST['resources_hero_bg_overlay']) ? '1' : '0';

    $overlay_color = trim($_POST['resources_hero_bg_overlay_color'] ?? '#000000');
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $overlay_color)) {
        $overlay_color = '#000000';
    }

    $overlay_opacity = max(0, min(100, (int)($_POST['resources_hero_bg_overlay_opacity'] ?? 35)));

    res_save_setting($conn, 'resources_hero_bg_type', $bg_type);
    res_save_setting($conn, 'resources_hero_bg_color', $bg_color);
    res_save_setting($conn, 'resources_hero_bg_overlay', $overlay_on);
    res_save_setting($conn, 'resources_hero_bg_overlay_color', $overlay_color);
    res_save_setting($conn, 'resources_hero_bg_overlay_opacity', (string)$overlay_opacity);
    res_save_setting($conn, 'resources_hero_label', trim($_POST['resources_hero_label'] ?? 'Resources'));
    res_save_setting($conn, 'resources_hero_title', trim($_POST['resources_hero_title'] ?? 'Venture Resources'));
    res_save_setting($conn, 'resources_hero_subtitle', trim($_POST['resources_hero_subtitle'] ?? ''));

    if (!empty($_FILES['resources_hero_bg_image_file']['name'])) {
        $name = '';
        $size = 0;

        $img = res_safe_upload(
            'resources_hero_bg_image_file',
            'uploads/settings/backgrounds',
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'resources-hero',
            $name,
            $size,
            $err
        );

        if (!$img) {
            res_redirect($err ?: 'Hero background image upload failed.', 'error', 'settings');
        }

        res_save_setting($conn, 'resources_hero_bg_image', $img);
        res_save_setting($conn, 'resources_hero_bg_type', 'image');
    }

    if (!empty($_FILES['resources_hero_bg_video_file']['name'])) {
        $name = '';
        $size = 0;

        $vid = res_safe_upload(
            'resources_hero_bg_video_file',
            'uploads/settings/backgrounds',
            ['mp4', 'webm', 'ogg'],
            'resources-hero',
            $name,
            $size,
            $err
        );

        if (!$vid) {
            res_redirect($err ?: 'Hero background video upload failed.', 'error', 'settings');
        }

        res_save_setting($conn, 'resources_hero_bg_video', $vid);
        res_save_setting($conn, 'resources_hero_bg_type', 'video');
    }

    res_redirect('Resources hero banner updated successfully.', 'success', 'settings');
}

if ($action === 'remove_hero_image') {
    $oldImage = res_get_setting($conn, 'resources_hero_bg_image', '');
    res_delete_file($oldImage);

    res_save_setting($conn, 'resources_hero_bg_image', '');

    if (res_get_setting($conn, 'resources_hero_bg_type', 'color') === 'image') {
        res_save_setting($conn, 'resources_hero_bg_type', 'color');
    }

    res_redirect('Resources hero image removed.', 'success', 'settings');
}

if ($action === 'remove_hero_video') {
    $oldVideo = res_get_setting($conn, 'resources_hero_bg_video', '');
    res_delete_file($oldVideo);

    res_save_setting($conn, 'resources_hero_bg_video', '');

    if (res_get_setting($conn, 'resources_hero_bg_type', 'color') === 'video') {
        res_save_setting($conn, 'resources_hero_bg_type', 'color');
    }

    res_redirect('Resources hero video removed.', 'success', 'settings');
}

if ($action === 'save') {
    $id          = (int)($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type        = trim($_POST['type'] ?? 'pdf');
    $category    = trim($_POST['category'] ?? '');
    $tags        = trim($_POST['tags'] ?? '');
    $access      = trim($_POST['access_level'] ?? 'request_required');
    $status      = trim($_POST['status'] ?? 'active');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $file_path   = trim($_POST['existing_file'] ?? '');
    $file_name   = '';
    $file_size   = 0;
    $thumbnail   = '';
    $adminId     = res_current_admin_id();

    if ($title === '') {
        res_redirect('Title is required.', 'error');
    }

    if (!in_array($type, ['pdf', 'video', 'image', 'document', 'other'], true)) {
        $type = 'other';
    }

    if (!in_array($access, ['public', 'request_required'], true)) {
        $access = 'request_required';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if (!empty($_FILES['resource_file']['name'])) {
        $err = '';

        $newPath = res_safe_upload(
            'resource_file',
            'uploads/resources',
            [
                // Browser/native preview
                'pdf',
                'jpg', 'jpeg', 'png', 'gif', 'webp',
                'mp4', 'webm',

                // PHPWord-supported document formats
                'docx', 'odt', 'rtf',

                // PhpSpreadsheet-supported spreadsheet formats
                'xls', 'xlsx', 'ods', 'csv',

                // Download-only / unsupported preview formats
                'doc',
                'ppt', 'pptx',
                'mov', 'avi',
                'zip',
                'txt'
            ],
            'res',
            $file_name,
            $file_size,
            $err
        );

        if (!$newPath) {
            res_redirect($err ?: 'File upload failed.', 'error');
        }

        if ($id > 0 && $file_path !== '') {
            res_delete_file($file_path);
        }

        $file_path = $newPath;
    }

    if (!empty($_FILES['thumbnail']['name'])) {
        $err = '';
        $thumbName = '';
        $thumbSize = 0;

        $thumbnail = res_safe_upload(
            'thumbnail',
            'uploads/resources/thumbs',
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'thumb',
            $thumbName,
            $thumbSize,
            $err
        );

        if (!$thumbnail) {
            res_redirect($err ?: 'Thumbnail upload failed.', 'error');
        }
    }

    $hasUploadedBy = res_table_has_column($conn, 'resources', 'uploaded_by');
    $hasUpdatedBy  = res_table_has_column($conn, 'resources', 'updated_by');
    $hasUpdatedAt  = res_table_has_column($conn, 'resources', 'updated_at');

    if ($id > 0) {
        $sets = "
            title=?,
            description=?,
            type=?,
            category=?,
            tags=?,
            access_level=?,
            status=?,
            sort_order=?
        ";

        $types = 'sssssssi';
        $vals = [$title, $description, $type, $category, $tags, $access, $status, $sort_order];

        if ($file_name !== '') {
            $sets .= ", file_path=?, file_name=?, file_size=?";
            $types .= 'ssi';
            $vals[] = $file_path;
            $vals[] = $file_name;
            $vals[] = $file_size;
        }

        if ($thumbnail !== '') {
            $sets .= ", thumbnail=?";
            $types .= 's';
            $vals[] = $thumbnail;
        }

        if ($hasUpdatedBy) {
            $sets .= ", updated_by=?";
            $types .= 'i';
            $vals[] = $adminId;
        }

        if ($hasUpdatedAt) {
            $sets .= ", updated_at=NOW()";
        }

        $types .= 'i';
        $vals[] = $id;

        $stmt = $conn->prepare("UPDATE resources SET $sets WHERE id=? LIMIT 1");

        if (!$stmt) {
            res_redirect('DB error: ' . $conn->error, 'error');
        }

        $stmt->bind_param($types, ...$vals);
    } else {
        if ($file_path === '') {
            res_redirect('Please upload a file.', 'error');
        }

        $columns = "
            title,
            description,
            type,
            category,
            tags,
            access_level,
            status,
            sort_order,
            file_path,
            file_name,
            file_size,
            thumbnail
        ";

        $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
        $types = 'sssssssissis';
        $vals = [
            $title,
            $description,
            $type,
            $category,
            $tags,
            $access,
            $status,
            $sort_order,
            $file_path,
            $file_name,
            $file_size,
            $thumbnail
        ];

        if ($hasUploadedBy) {
            $columns .= ", uploaded_by";
            $placeholders .= ", ?";
            $types .= 'i';
            $vals[] = $adminId;
        }

        $stmt = $conn->prepare("
            INSERT INTO resources ($columns)
            VALUES ($placeholders)
        ");

        if (!$stmt) {
            res_redirect('DB error: ' . $conn->error, 'error');
        }

        $stmt->bind_param($types, ...$vals);
    }

    if (!$stmt->execute()) {
        res_redirect('DB error: ' . $stmt->error, 'error');
    }

    $stmt->close();

    res_redirect($id > 0 ? 'Resource updated successfully.' : 'Resource uploaded successfully.');
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        res_redirect('Invalid resource selected.', 'error');
    }

    $stmt = $conn->prepare("SELECT file_path, thumbnail FROM resources WHERE id=? LIMIT 1");

    if (!$stmt) {
        res_redirect('DB error: ' . $conn->error, 'error');
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        res_delete_file((string)($row['file_path'] ?? ''));
        res_delete_file((string)($row['thumbnail'] ?? ''));
    }

    $stmt = $conn->prepare("DELETE FROM resource_requests WHERE resource_id=?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("DELETE FROM resources WHERE id=? LIMIT 1");

    if (!$stmt) {
        res_redirect('DB error: ' . $conn->error, 'error');
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    res_redirect('Resource deleted.');
}

if ($action === 'approve_request') {
    $id = (int)($_POST['id'] ?? 0);
    $admin_note = trim($_POST['admin_note'] ?? '');
    $adminId = res_current_admin_id();

    if ($id <= 0) {
        res_redirect('Invalid request selected.', 'error', 'requests');
    }

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $now = date('Y-m-d H:i:s');

    $hasApprovedBy = res_table_has_column($conn, 'resource_requests', 'approved_by');

    if ($hasApprovedBy) {
        $stmt = $conn->prepare("
            UPDATE resource_requests
            SET status='approved',
                admin_note=?,
                download_token=?,
                token_expires=?,
                approved_at=?,
                approved_by=?
            WHERE id=?
            LIMIT 1
        ");

        if (!$stmt) {
            res_redirect('DB error: ' . $conn->error, 'error', 'requests');
        }

        $stmt->bind_param('ssssii', $admin_note, $token, $expires, $now, $adminId, $id);
    } else {
        $stmt = $conn->prepare("
            UPDATE resource_requests
            SET status='approved',
                admin_note=?,
                download_token=?,
                token_expires=?,
                approved_at=?
            WHERE id=?
            LIMIT 1
        ");

        if (!$stmt) {
            res_redirect('DB error: ' . $conn->error, 'error', 'requests');
        }

        $stmt->bind_param('ssssi', $admin_note, $token, $expires, $now, $id);
    }

    if (!$stmt->execute()) {
        res_redirect('Failed to approve request.', 'error', 'requests');
    }

    $stmt->close();

    $rq = $conn->prepare("
        SELECT rr.*, r.title res_title, r.file_name
        FROM resource_requests rr
        LEFT JOIN resources r ON r.id = rr.resource_id
        WHERE rr.id = ?
        LIMIT 1
    ");

    if ($rq) {
        $rq->bind_param('i', $id);
        $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        $rq->close();

        if ($req && filter_var((string)$req['requester_email'], FILTER_VALIDATE_EMAIL)) {
            $dl_url = rtrim(SITE_URL, '/') . '/resources.php?download=' . urlencode($token);
            $subject = 'Your Access Request Has Been Approved';

            $body = "Hello {$req['requester_name']},\n\n";
            $body .= "Your request to access \"{$req['res_title']}\" has been approved.\n\n";
            $body .= "Download link valid for 48 hours:\n{$dl_url}\n\n";

            if ($admin_note !== '') {
                $body .= "Note: {$admin_note}\n\n";
            }

            $body .= "Thank you.";

            @mail(
                (string)$req['requester_email'],
                $subject,
                $body,
                "From: " . res_get_setting($conn, 'contact_email', 'no-reply@localhost')
            );
        }
    }

    res_redirect('Request approved and notification sent.', 'success', 'requests');
}

if ($action === 'reject_request') {
    $id = (int)($_POST['id'] ?? 0);
    $admin_note = trim($_POST['admin_note'] ?? '');

    if ($id <= 0) {
        res_redirect('Invalid request selected.', 'error', 'requests');
    }

    $stmt = $conn->prepare("
        UPDATE resource_requests
        SET status='rejected',
            admin_note=?
        WHERE id=?
        LIMIT 1
    ");

    if (!$stmt) {
        res_redirect('DB error: ' . $conn->error, 'error', 'requests');
    }

    $stmt->bind_param('si', $admin_note, $id);
    $stmt->execute();
    $stmt->close();

    $rq = $conn->prepare("
        SELECT rr.*, r.title res_title
        FROM resource_requests rr
        LEFT JOIN resources r ON r.id = rr.resource_id
        WHERE rr.id = ?
        LIMIT 1
    ");

    if ($rq) {
        $rq->bind_param('i', $id);
        $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        $rq->close();

        if ($req && filter_var((string)$req['requester_email'], FILTER_VALIDATE_EMAIL)) {
            $subject = 'Your Access Request Update';

            $body = "Hello {$req['requester_name']},\n\n";
            $body .= "Your request to access \"{$req['res_title']}\" could not be approved at this time.";

            if ($admin_note !== '') {
                $body .= "\n\nNote: {$admin_note}";
            }

            $body .= "\n\nThank you.";

            @mail(
                (string)$req['requester_email'],
                $subject,
                $body,
                "From: " . res_get_setting($conn, 'contact_email', 'no-reply@localhost')
            );
        }
    }

    res_redirect('Request rejected.', 'success', 'requests');
}

res_redirect('Unknown action.', 'error');