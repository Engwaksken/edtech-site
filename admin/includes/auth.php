<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

/* --------------------------------------------------------------------------
   Shared helpers
   -------------------------------------------------------------------------- */

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../../includes/config.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('auth_normalize_role')) {
    function auth_normalize_role(string $role): string {
        $role = strtolower(trim($role));
        $role = str_replace('_', '-', $role);
        return (string)preg_replace('/[^a-z0-9\-]/', '', $role);
    }
}

if (!function_exists('auth_is_active')) {
    function auth_is_active($status): bool {
        return in_array(strtolower((string)$status), ['1', 'active'], true);
    }
}

if (!function_exists('auth_role_variants')) {
    function auth_role_variants(string $role): array {
        $role = strtolower(trim($role));
        $dash = str_replace('_', '-', $role);
        $under = str_replace('-', '_', $role);

        return array_values(array_unique(array_filter([$role, $dash, $under])));
    }
}

/* --------------------------------------------------------------------------
   Login guard
   -------------------------------------------------------------------------- */

if (!function_exists('require_admin_login')) {
    function require_admin_login(): void {
        if (empty($_SESSION['admin_id'])) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('admin_is_super')) {
    function admin_is_super(): bool {
        $role = auth_normalize_role((string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? ''));

        return in_array($role, [
            'super-admin',
            'superadmin',
            'sup-admin',
            'supper-admin',
            'supperadmin'
        ], true);
    }
}

require_admin_login();

/* --------------------------------------------------------------------------
   Load logged-in admin user
   -------------------------------------------------------------------------- */

$ADMIN = [];
$uid = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT id, full_name, username, email, role, photo, status, can_manage_permissions
    FROM admin_users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Failed to prepare admin lookup.');
}

$stmt->bind_param('i', $uid);
$stmt->execute();
$ADMIN = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

if (!$ADMIN || !auth_is_active($ADMIN['status'] ?? '')) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?error=inactive');
    exit;
}

$_SESSION['admin_role'] = auth_normalize_role((string)($ADMIN['role'] ?? ''));
$_SESSION['admin_email'] = (string)($ADMIN['email'] ?? '');
$_SESSION['admin_name'] = (string)($ADMIN['full_name'] ?? '');

/* --------------------------------------------------------------------------
   Permission helpers
   -------------------------------------------------------------------------- */

if (!function_exists('admin_can_manage_permissions')) {
    function admin_can_manage_permissions(mysqli $conn): bool {
        global $ADMIN;

        if (admin_is_super()) {
            return true;
        }

        if ((int)($ADMIN['can_manage_permissions'] ?? 0) === 1) {
            return true;
        }

        $roleRaw = (string)($ADMIN['role'] ?? $_SESSION['admin_role'] ?? '');
        $roleVariants = auth_role_variants($roleRaw);

        if (empty($roleVariants)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($roleVariants), '?'));
        $types = str_repeat('s', count($roleVariants));

        $sql = "
            SELECT can_manage_permissions
            FROM roles
            WHERE role_key IN ($placeholders)
              AND status = 'active'
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$roleVariants);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['can_manage_permissions'] ?? 0) === 1;
    }
}

if (!function_exists('admin_has_permission')) {
    function admin_has_permission(mysqli $conn, string $page_key): bool {
        global $ADMIN;

        if (admin_is_super()) {
            return true;
        }

        $roleRaw = (string)($ADMIN['role'] ?? $_SESSION['admin_role'] ?? '');
        $roleVariants = auth_role_variants($roleRaw);

        if (empty($roleVariants)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($roleVariants), '?'));
        $types = str_repeat('s', count($roleVariants)) . 's';
        $params = array_merge($roleVariants, [$page_key]);

        $sql = "
            SELECT can_access
            FROM role_permissions
            WHERE role_key IN ($placeholders)
              AND page_key = ?
              AND can_access = 1
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row);
    }
}

if (!function_exists('require_page_access')) {
    function require_page_access(mysqli $conn, string $page_key): void {
        require_admin_login();

        if (!admin_has_permission($conn, $page_key)) {
            http_response_code(403);
            echo '<h2 style="font-family:Arial;margin:40px">Access denied</h2>';
            echo '<p style="font-family:Arial;margin:40px">Your role is not allowed to access this page.</p>';
            exit;
        }
    }
}


if (!defined('MS_PRIVILEGED_ROLES')) {
    define('MS_PRIVILEGED_ROLES', [
        'admin',
        'super-admin',
        'superadmin',
        'sup-admin',
        'supper-admin',
        'supperadmin',
        'programs-lead',
        'program-lead',
        'program-director',
        'program-manager',
        'meal-officer',
        'consultant'
    ]);
}

if (!function_exists('ms_current_role')) {
    function ms_current_role(): string {
        $role = (string)($_SESSION['admin_role'] ?? '');

        if ($role !== '') {
            return auth_normalize_role($role);
        }

        return auth_normalize_role((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? ''));
    }
}

if (!function_exists('ms_auth_gate')) {
    function ms_auth_gate(): void {
        require_admin_login();
    }
}

if (!function_exists('ms_can_manage')) {
    function ms_can_manage(): bool {
        return in_array(ms_current_role(), MS_PRIVILEGED_ROLES, true);
    }
}

if (!function_exists('ms_is_mentor')) {
    function ms_is_mentor(): bool {
        return ms_current_role() === 'mentor';
    }
}

if (!function_exists('ms_mentor_record_id')) {
    function ms_mentor_record_id(mysqli $conn): int {
        if (!ms_is_mentor()) {
            return 0;
        }

        if (!empty($_SESSION['mentor_id'])) {
            return (int)$_SESSION['mentor_id'];
        }

        $uid = (int)($_SESSION['admin_id'] ?? 0);

        if ($uid > 0) {
            $st = $conn->prepare("
                SELECT id
                FROM mentors
                WHERE user_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();

                if ($row) {
                    $_SESSION['mentor_id'] = (int)$row['id'];
                    return (int)$row['id'];
                }
            }
        }

        global $ADMIN;

        $email = trim((string)(
            $ADMIN['email']
            ?? $_SESSION['admin_email']
            ?? ''
        ));

        if ($email !== '') {
            $st = $conn->prepare("
                SELECT id
                FROM mentors
                WHERE email = ?
                  AND status = 'active'
                LIMIT 1
            ");

            if ($st) {
                $st->bind_param('s', $email);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();

                if ($row) {
                    $_SESSION['mentor_id'] = (int)$row['id'];
                    return (int)$row['id'];
                }
            }
        }

        return 0;
    }
}


if (!function_exists('ms_owns_session')) {
    function ms_owns_session(mysqli $conn, int $session_id, int $mentor_record_id): bool {
        if (ms_can_manage()) {
            return true;
        }

        if ($session_id <= 0 || $mentor_record_id <= 0) {
            return false;
        }

        $st = $conn->prepare("
            SELECT 1
            FROM session_mentors
            WHERE session_id = ?
              AND mentor_id = ?
            LIMIT 1
        ");

        if (!$st) {
            return false;
        }

        $st->bind_param('ii', $session_id, $mentor_record_id);
        $st->execute();
        $found = $st->get_result()->num_rows > 0;
        $st->close();

        return $found;
    }
}


if (!function_exists('ms_is_session_organizer')) {
    function ms_is_session_organizer(mysqli $conn, int $session_id, int $mentor_record_id): bool {
        if (ms_can_manage()) {
            return true;
        }

        if ($session_id <= 0 || $mentor_record_id <= 0) {
            return false;
        }

        $st = $conn->prepare("
            SELECT 1
            FROM session_mentors
            WHERE session_id = ?
              AND mentor_id = ?
              AND role = 'organizer'
            LIMIT 1
        ");

        if (!$st) {
            return false;
        }

        $st->bind_param('ii', $session_id, $mentor_record_id);
        $st->execute();
        $found = $st->get_result()->num_rows > 0;
        $st->close();

        return $found;
    }
}

if (!function_exists('ms_has_page_permission')) {
    function ms_has_page_permission(mysqli $conn, string $page_key): bool {
        if (ms_can_manage()) {
            return true;
        }

        if (function_exists('admin_has_permission')) {
            return admin_has_permission($conn, $page_key);
        }

        return false;
    }
}

if (!function_exists('admin_is_mentor')) {
    function admin_is_mentor(): bool {
        return ms_is_mentor();
    }
}

if (!function_exists('admin_current_mentor_id')) {
    function admin_current_mentor_id(mysqli $conn): int {
        return ms_mentor_record_id($conn);
    }
}
