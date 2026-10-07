<?php
require_once __DIR__ . '/security.php';


const AUTH_PRIVILEGED_ROLES = [
    'admin', 'super_admin', 'finance', 'accountant',
    'program_manager', 'program_director', 'program_lead','consultant',
];

function auth_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    $role = str_replace(['-', ' '], '_', $role);
    $role = preg_replace('/[^a-z0-9_]/', '', $role) ?? '';
    return trim($role, '_');
}

function auth_current_role(): string
{
    return auth_normalize_role((string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? ''));
}

function auth_is_privileged_staff(): bool
{
    return in_array(auth_current_role(), AUTH_PRIVILEGED_ROLES, true);
}

$venture_id = (int)($_SESSION['venture_id'] ?? 0);
$is_privileged_staff = auth_is_privileged_staff();

if ($venture_id <= 0 && !$is_privileged_staff) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header("Location: /index" . ($redirect ? "?next={$redirect}" : ''));
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$VENTURE = null;

if ($venture_id > 0) {
    $va_stmt = $conn->prepare("
        SELECT v.*,
               c.name  AS cohort_name,
               c.start_date AS cohort_start,
               c.end_date   AS cohort_end
        FROM ventures v
        LEFT JOIN cohorts c ON c.id = v.cohort_id
        WHERE v.id = ?
        LIMIT 1
    ");
    $va_stmt->bind_param('i', $venture_id);
    $va_stmt->execute();
    $VENTURE = $va_stmt->get_result()->fetch_assoc();
    $va_stmt->close();

    if (!$VENTURE || $VENTURE['status'] === 'inactive') {
        if ($is_privileged_staff) {
            // A staff session with no venture of its own (or one that
            // happens to be inactive) isn't an account problem for
            // them � only real venture sessions get logged out for
            // this. Just proceed without a $VENTURE.
            $VENTURE = null;
        } else {
            session_destroy();
            header('Location: /index?err=inactive');
            exit;
        }
    }
}

function portal_url(string $page, array $qs = []): string {
    $base = $page;
    return $qs ? $base . '?' . http_build_query($qs) : $base;
}

function venture_logout(): void {
    header('Location: /logout');
    exit;
}

if (($_GET['action'] ?? '') === 'logout') {
    venture_logout();
}
