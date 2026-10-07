<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'auth.php';

/* =========================================================
   Helper Functions
========================================================= */

if (!function_exists('vp_flash')) {
    function vp_flash(string $message, string $type = 'success'): void
    {
        $_SESSION['vp_flash'] = [
            'message' => $message,
            'type'    => $type
        ];
    }
}

if (!function_exists('vp_redir')) {
    function vp_redir(string $url): void
    {
        header("Location: " . $url);
        exit;
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function profile_clean($value): string
{
    return trim((string)$value);
}

function profile_slug(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');

    return $text !== '' ? $text : 'venture';
}

function profile_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function profile_password_column(mysqli $conn): ?string
{
    foreach (['password_hash', 'password'] as $column) {
        if (profile_column_exists($conn, 'ventures', $column)) {
            return $column;
        }
    }

    return null;
}

function profile_verify_password(string $plain, string $stored): bool
{
    if ($stored === '') {
        return false;
    }

    if (password_verify($plain, $stored)) {
        return true;
    }

    return hash_equals($stored, md5($plain)) || hash_equals($stored, sha1($plain));
}

function profile_hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function profile_upload_image(string $field, string $folder = 'ventures'): ?string
{
    if (!isset($_FILES[$field]) || empty($_FILES[$field]['name'])) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Failed to upload {$field}.");
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $tmp = $_FILES[$field]['tmp_name'];
    $mime = mime_content_type($tmp);

    if (!isset($allowed[$mime])) {
        throw new RuntimeException("Only JPG, PNG, WEBP and GIF images are allowed for {$field}.");
    }

    if ((int)$_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException("{$field} must not exceed 5MB.");
    }

    $baseDir = dirname(__DIR__) . '/uploads/' . $folder;

    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0755, true);
    }

    $ext = $allowed[$mime];
    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destination = $baseDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException("Could not save uploaded {$field}.");
    }

    return 'uploads/' . $folder . '/' . $filename;
}

/* =========================================================
   Request Validation
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vp_flash('Invalid request method.', 'error');
    vp_redir('../profile.php');
}

if (($_POST['action'] ?? '') !== 'save_profile') {
    vp_flash('Invalid profile action.', 'error');
    vp_redir('../profile.php');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    vp_flash('Database connection was not found.', 'error');
    vp_redir('../profile.php');
}

$conn->set_charset('utf8mb4');

$venture_id = (int)($venture_id ?? ($_SESSION['venture_id'] ?? 0));

if ($venture_id <= 0) {
    vp_flash('Session expired. Please login again.', 'error');
    vp_redir('../login.php');
}

/* =========================================================
   Input
========================================================= */

$name               = profile_clean($_POST['name'] ?? '');
$tagline            = profile_clean($_POST['tagline'] ?? '');
$description        = profile_clean($_POST['description'] ?? '');
$founded_year       = profile_clean($_POST['founded_year'] ?? '');
$location           = profile_clean($_POST['location'] ?? '');
$impact_metric      = profile_clean($_POST['impact_metric'] ?? '');
$stage              = profile_clean($_POST['stage'] ?? 'idea');
$sector             = profile_clean($_POST['sector'] ?? '');
$country            = profile_clean($_POST['country'] ?? '');
$website            = profile_clean($_POST['website'] ?? '');
$linkedin_url       = profile_clean($_POST['linkedin_url'] ?? '');
$funding_raised     = profile_clean($_POST['funding_raised'] ?? '0');
$funding_sought     = profile_clean($_POST['funding_sought'] ?? '0');

$cofounder1_name    = profile_clean($_POST['cofounder1_name'] ?? '');
$cofounder1_email   = profile_clean($_POST['cofounder1_email'] ?? '');
$cofounder1_contact = profile_clean($_POST['cofounder1_contact'] ?? '');
$cofounder2_name    = profile_clean($_POST['cofounder2_name'] ?? '');
$cofounder2_email   = profile_clean($_POST['cofounder2_email'] ?? '');
$cofounder2_contact = profile_clean($_POST['cofounder2_contact'] ?? '');

$current_password   = (string)($_POST['current_password'] ?? '');
$new_password       = (string)($_POST['new_password'] ?? '');
$confirm_password   = (string)($_POST['confirm_password'] ?? '');

$allowedStages = ['idea', 'mvp', 'pre_seed', 'seed', 'series_a', 'growth'];

/* =========================================================
   Validation
========================================================= */

if ($name === '') {
    vp_flash('Venture name is required.', 'error');
    vp_redir('../profile.php');
}

if ($cofounder1_email !== '' && !filter_var($cofounder1_email, FILTER_VALIDATE_EMAIL)) {
    vp_flash('Enter a valid co-founder 1 email address.', 'error');
    vp_redir('../profile.php');
}

if ($cofounder2_email !== '' && !filter_var($cofounder2_email, FILTER_VALIDATE_EMAIL)) {
    vp_flash('Enter a valid co-founder 2 email address.', 'error');
    vp_redir('../profile.php');
}

if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
    vp_flash('Enter a valid website URL.', 'error');
    vp_redir('../profile.php');
}

if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
    vp_flash('Enter a valid LinkedIn URL.', 'error');
    vp_redir('../profile.php');
}

if (!in_array($stage, $allowedStages, true)) {
    $stage = 'idea';
}

$founded_year = $founded_year !== '' ? (int)$founded_year : null;

if ($founded_year !== null && ($founded_year < 1900 || $founded_year > (int)date('Y'))) {
    vp_flash('Enter a valid founded year.', 'error');
    vp_redir('../profile.php');
}

$funding_raised = $funding_raised !== '' ? (float)$funding_raised : 0;
$funding_sought = $funding_sought !== '' ? (float)$funding_sought : 0;

if ($funding_raised < 0 || $funding_sought < 0) {
    vp_flash('Funding values cannot be negative.', 'error');
    vp_redir('../profile.php');
}

$wantsPasswordChange = $current_password !== '' || $new_password !== '' || $confirm_password !== '';

if ($wantsPasswordChange) {
    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        vp_flash('To change password, enter current password, new password and confirm password.', 'error');
        vp_redir('../profile.php');
    }

    if (strlen($new_password) < 8) {
        vp_flash('New password must be at least 8 characters.', 'error');
        vp_redir('../profile.php');
    }

    if ($new_password !== $confirm_password) {
        vp_flash('New password and confirmation do not match.', 'error');
        vp_redir('../profile.php');
    }

    if ($current_password === $new_password) {
        vp_flash('New password must be different from the current password.', 'error');
        vp_redir('../profile.php');
    }
}

/* =========================================================
   Save
========================================================= */

try {
    $passwordColumn = profile_password_column($conn);

    $selectFields = "logo, featured_image, email";
    if ($passwordColumn !== null) {
        $selectFields .= ", {$passwordColumn} AS current_password_hash";
    }

    $stmt = $conn->prepare("SELECT {$selectFields} FROM ventures WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $venture_id);
    $stmt->execute();

    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        vp_flash('Venture profile was not found.', 'error');
        vp_redir('../profile.php');
    }

    if ($wantsPasswordChange) {
        if ($passwordColumn === null) {
            throw new RuntimeException('No password column found on ventures table. Add password_hash or password column.');
        }

        $storedPassword = (string)($current['current_password_hash'] ?? '');

        if (!profile_verify_password($current_password, $storedPassword)) {
            vp_flash('Current password is incorrect.', 'error');
            vp_redir('../profile.php');
        }
    }

    $logo = $current['logo'] ?? '';
    $featured_image = $current['featured_image'] ?? '';

    $newLogo = profile_upload_image('logo', 'ventures');
    if ($newLogo !== null) {
        $logo = $newLogo;
    }

    $newFeatured = profile_upload_image('featured_image', 'ventures');
    if ($newFeatured !== null) {
        $featured_image = $newFeatured;
    }

    $baseSlug = profile_slug($name);
    $slug = $baseSlug;

    $check = $conn->prepare("SELECT id FROM ventures WHERE slug = ? AND id <> ? LIMIT 1");
    $check->bind_param("si", $slug, $venture_id);
    $check->execute();

    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        $slug = $baseSlug . '-' . $venture_id;
    }

    $conn->begin_transaction();

    $sql = "
        UPDATE ventures SET
            name = ?,
            slug = ?,
            cofounder1_name = ?,
            cofounder1_email = ?,
            cofounder1_contact = ?,
            cofounder2_name = ?,
            cofounder2_email = ?,
            cofounder2_contact = ?,
            tagline = ?,
            description = ?,
            founded_year = ?,
            location = ?,
            impact_metric = ?,
            featured_image = ?,
            stage = ?,
            sector = ?,
            country = ?,
            website = ?,
            linkedin_url = ?,
            logo = ?,
            funding_raised = ?,
            funding_sought = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "ssssssssssisssssssssddi",
        $name,
        $slug,
        $cofounder1_name,
        $cofounder1_email,
        $cofounder1_contact,
        $cofounder2_name,
        $cofounder2_email,
        $cofounder2_contact,
        $tagline,
        $description,
        $founded_year,
        $location,
        $impact_metric,
        $featured_image,
        $stage,
        $sector,
        $country,
        $website,
        $linkedin_url,
        $logo,
        $funding_raised,
        $funding_sought,
        $venture_id
    );

    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }

    $stmt->close();

    if ($wantsPasswordChange && $passwordColumn !== null) {
        $newHash = profile_hash_password($new_password);

        $passSql = "UPDATE ventures SET {$passwordColumn} = ?, updated_at = NOW() WHERE id = ? LIMIT 1";
        $passStmt = $conn->prepare($passSql);
        $passStmt->bind_param("si", $newHash, $venture_id);

        if (!$passStmt->execute()) {
            throw new RuntimeException($passStmt->error);
        }

        $passStmt->close();
    }

    $conn->commit();

    $_SESSION['venture_name'] = $name;

    vp_flash(
        $wantsPasswordChange
            ? 'Profile and password updated successfully.'
            : 'Profile updated successfully.',
        'success'
    );

    vp_redir('../profile.php');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }

    vp_flash('Failed to update profile: ' . $e->getMessage(), 'error');
    vp_redir('../profile.php');
}