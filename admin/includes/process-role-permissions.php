<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');


if (!function_exists('admin_can_manage_permissions') || !admin_can_manage_permissions($conn)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Access denied.']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../role-permissions.php'); exit;
}



function json_ok(string $msg = '', array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success'=>true,'message'=>$msg], $extra));
    exit;
}

function json_fail(string $msg, int $status = 200): void {
    if ($status !== 200) http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}

function redir_back(string $msg, string $type = 'success'): void {
    if (function_exists('flash')) flash('permissions', $msg, $type);
    header('Location: ../role-permissions.php'); exit;
}


function to_key(string $v): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($v))), '_');
}

function is_super(string $role): bool {
    $role = strtolower(trim(str_replace('_', '-', $role)));
    return in_array($role, ['super-admin','superadmin','sup-admin'], true);
}

function load_roles(mysqli $c): array {
    $out = []; $r = $c->query("SELECT role_key FROM roles WHERE status='active'");
    while ($r && $row = $r->fetch_assoc()) $out[] = $row['role_key'];
    return $out;
}

function load_pages(mysqli $c): array {
    $out = []; $r = $c->query("SELECT page_key, page_title, page_url FROM admin_pages");
    while ($r && $row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}

function load_page_keys(mysqli $c): array {
    return array_column(load_pages($c), 'page_key');
}



$action = trim($_POST['action'] ?? '');



if ($action === 'save_permissions_ajax') {
    $payload = json_decode($_POST['permissions'] ?? '', true);
    if (!is_array($payload)) json_fail('Invalid permissions payload.');

    $valid_roles = array_filter(load_roles($conn), fn($r) => !is_super($r));
    $valid_pages = load_page_keys($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO role_permissions (role_key, page_key, can_access) VALUES (?,?,?) ON DUPLICATE KEY UPDATE can_access=VALUES(can_access)");
        if (!$stmt) throw new RuntimeException('Prepare: '.$conn->error);

        foreach ($payload as $rk => $pages) {
            if (!in_array($rk, $valid_roles, true) || is_super($rk)) continue;
            if (!is_array($pages)) continue;
            foreach ($pages as $pk => $access) {
                if (!in_array($pk, $valid_pages, true)) continue;
                $a = $access ? 1 : 0;
                $stmt->bind_param('ssi', $rk, $pk, $a);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            }
        }
        $stmt->close();
        $conn->commit();
        json_ok('Permissions saved successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Save failed: '.$e->getMessage());
    }
}



if ($action === 'save_permissions') {
    $posted    = $_POST['permissions'] ?? [];
    $all_roles = load_roles($conn);
    $all_pages = load_pages($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO role_permissions (role_key, page_key, page_title, page_url, can_access) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE page_title=VALUES(page_title), page_url=VALUES(page_url), can_access=VALUES(can_access)");
        if (!$stmt) throw new RuntimeException('Prepare: '.$conn->error);

        foreach ($all_roles as $rk) {
            foreach ($all_pages as $pg) {
                $pk     = $pg['page_key'];
                $access = is_super($rk) ||
                          (is_array($posted[$rk] ?? null) && in_array($pk, $posted[$rk], true)) ? 1 : 0;
                $stmt->bind_param('ssssi', $rk, $pk, $pg['page_title'], $pg['page_url'], $access);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            }
        }
        $stmt->close();
        $conn->commit();
        redir_back('Permissions updated successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        redir_back('Failed: '.$e->getMessage(), 'error');
    }
}



if ($action === 'save_sidebar_order') {
    $order = json_decode($_POST['sidebar_order'] ?? '', true);
    if (!is_array($order)) json_fail('Invalid order data.');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE admin_pages SET group_name=?, section_order=?, sort_order=? WHERE page_key=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Prepare: '.$conn->error);

        foreach ($order as $si => $section) {
            $gn = trim($section['group_name'] ?? '');
            if ($gn === '' || !is_array($section['pages'] ?? null)) continue;
            $sec_ord = ((int)$si + 1) * 10;
            foreach ($section['pages'] as $pi => $pk) {
                $pk     = trim((string)$pk);
                $srt    = ((int)$pi + 1) * 10;
                if ($pk === '') continue;
                $stmt->bind_param('siis', $gn, $sec_ord, $srt, $pk);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            }
        }
        $stmt->close();
        $conn->commit();
        json_ok('Sidebar order saved.');
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Save failed: '.$e->getMessage());
    }
}



if ($action === 'add_page') {
    $pt   = trim($_POST['page_title'] ?? '');
    $pk   = to_key(trim($_POST['page_key'] ?? '') ?: $pt);
    $pu   = trim($_POST['page_url']   ?? '');
    $gn   = trim($_POST['group_name'] ?? '') ?: 'General';
    $ico  = trim($_POST['icon']       ?? '') ?: 'fa-circle';
    $sort = max(0,(int)($_POST['sort_order'] ?? 100));
    $act  = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    $rls  = array_values(array_filter(array_map('trim',(array)($_POST['roles'] ?? []))));

    if ($pt === '') json_fail('Page title is required.');
    if ($pu === '') json_fail('Page URL is required.');
    if ($pk === '') json_fail('Page key could not be generated.');

    // Duplicate check
    $chk = $conn->prepare("SELECT page_key FROM admin_pages WHERE page_key=? LIMIT 1");
    $chk->bind_param('s',$pk); $chk->execute();
    if ($chk->get_result()->num_rows) { $chk->close(); json_fail("Key \"$pk\" already exists."); }
    $chk->close();

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT INTO admin_pages (page_key, page_title, page_url, group_name, icon, sort_order, section_order, is_active) VALUES (?,?,?,?,?,?,990,?)");
        $ins->bind_param('sssssii', $pk, $pt, $pu, $gn, $ico, $sort, $act);
        if (!$ins->execute()) throw new RuntimeException($ins->error);
        $ins->close();

        $all = $conn->query("SELECT role_key FROM roles WHERE status='active'");
        $ps  = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, page_key, page_title, page_url, can_access) VALUES (?,?,?,?,?)");
        while ($all && $row = $all->fetch_assoc()) {
            $rk  = $row['role_key'];
            $acc = (is_super($rk) || in_array($rk, $rls, true)) ? 1 : 0;
            $ps->bind_param('ssssi', $rk, $pk, $pt, $pu, $acc);
            $ps->execute();
        }
        $ps->close();

        $conn->commit();
        json_ok('Page added.', ['page_key' => $pk]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Add failed: '.$e->getMessage());
    }
}



if ($action === 'update_page') {
    $old = trim($_POST['old_page_key'] ?? '');
    $pt  = trim($_POST['page_title']   ?? '');
    $pk  = to_key(trim($_POST['page_key'] ?? '') ?: $old);
    $pu  = trim($_POST['page_url']     ?? '');
    $gn  = trim($_POST['group_name']   ?? '') ?: 'General';
    $ico = trim($_POST['icon']         ?? '') ?: 'fa-circle';
    $srt = max(0,(int)($_POST['sort_order'] ?? 100));
    $act = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;

    if ($old === '') json_fail('Original page key missing.');
    if ($pt  === '') json_fail('Page title is required.');
    if ($pu  === '') json_fail('Page URL is required.');
    if ($pk  === '') json_fail('Page key could not be generated.');

    if ($pk !== $old) {
        $chk = $conn->prepare("SELECT page_key FROM admin_pages WHERE page_key=? AND page_key!=? LIMIT 1");
        $chk->bind_param('ss',$pk,$old); $chk->execute();
        if ($chk->get_result()->num_rows) { $chk->close(); json_fail("Key \"$pk\" is already taken."); }
        $chk->close();
    }

    $conn->begin_transaction();
    try {
        if ($pk !== $old) {
            // Cascade rename in permissions
            $u = $conn->prepare("UPDATE role_permissions SET page_key=? WHERE page_key=?");
            $u->bind_param('ss',$pk,$old); $u->execute(); $u->close();

            // Delete old page row, insert renamed one
            $d = $conn->prepare("DELETE FROM admin_pages WHERE page_key=? LIMIT 1");
            $d->bind_param('s',$old); $d->execute(); $d->close();

            $i = $conn->prepare("INSERT INTO admin_pages (page_key, page_title, page_url, group_name, icon, sort_order, section_order, is_active) VALUES (?,?,?,?,?,?,990,?)");
            $i->bind_param('sssssii',$pk,$pt,$pu,$gn,$ico,$srt,$act); $i->execute(); $i->close();
        } else {
            $u = $conn->prepare("UPDATE admin_pages SET page_title=?, page_url=?, group_name=?, icon=?, sort_order=?, is_active=? WHERE page_key=? LIMIT 1");
            $u->bind_param('ssssiis',$pt,$pu,$gn,$ico,$srt,$act,$pk); $u->execute(); $u->close();
        }

     
        $conn->query("UPDATE role_permissions SET page_title='".($conn->real_escape_string($pt))."', page_url='".($conn->real_escape_string($pu))."' WHERE page_key='".$conn->real_escape_string($pk)."'");

        $conn->commit();
        json_ok('Page updated.', ['page_key' => $pk]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Update failed: '.$e->getMessage());
    }
}



if ($action === 'delete_page') {
    $pk = trim($_POST['page_key'] ?? '');
    if ($pk === '') json_fail('Page key is required.');

    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM role_permissions WHERE page_key=?");
        $d1->bind_param('s',$pk); $d1->execute(); $d1->close();
        $d2 = $conn->prepare("DELETE FROM admin_pages WHERE page_key=? LIMIT 1");
        $d2->bind_param('s',$pk); $d2->execute(); $d2->close();
        $conn->commit();
        json_ok('Page deleted.', ['page_key' => $pk]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Delete failed: '.$e->getMessage());
    }
}


if ($action === 'add_role') {
    $rn  = trim($_POST['role_name'] ?? '');
    $rk  = to_key(trim($_POST['role_key'] ?? '') ?: $rn);
    $cmp = ($_POST['can_manage_permissions'] ?? '0') === '1' ? 1 : 0;

    if ($rn === '') json_fail('Role name is required.');
    if ($rk === '') json_fail('Role key could not be generated.');
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $rk)) json_fail('Role key must start with a letter and use only lowercase letters, digits, and underscores.');
    if (is_super($rk)) json_fail('That key matches a protected super-admin pattern.');

    $chk = $conn->prepare("SELECT role_key FROM roles WHERE role_key=? LIMIT 1");
    $chk->bind_param('s',$rk); $chk->execute();
    if ($chk->get_result()->num_rows) { $chk->close(); json_fail("Role key \"$rk\" already exists."); }
    $chk->close();

    $ins = $conn->prepare("INSERT INTO roles (role_key, role_name, can_manage_permissions, status) VALUES (?,?,?,'active')");
    $ins->bind_param('ssi',$rk,$rn,$cmp);
    if (!$ins->execute()) json_fail('Insert failed: '.$ins->error);
    $ins->close();

  
    $pages = load_pages($conn);
    $ps = $conn->prepare("INSERT IGNORE INTO role_permissions (role_key, page_key, page_title, page_url, can_access) VALUES (?,?,?,?,0)");
    foreach ($pages as $pg) {
        $ps->bind_param('ssss', $rk, $pg['page_key'], $pg['page_title'], $pg['page_url']);
        $ps->execute();
    }
    $ps->close();

    json_ok('Role created.', ['role_key' => $rk]);
}



if ($action === 'update_role') {
    $old = trim($_POST['old_role_key'] ?? '');
    $rn  = trim($_POST['role_name']    ?? '');
    $rk  = to_key(trim($_POST['role_key'] ?? '') ?: $old);
    $cmp = ($_POST['can_manage_permissions'] ?? '0') === '1' ? 1 : 0;

    if ($old === '') json_fail('Original role key missing.');
    if ($rn  === '') json_fail('Role name is required.');
    if ($rk  === '') json_fail('Role key could not be generated.');
    if (is_super($old)) json_fail('Super-admin role cannot be modified.');
    if (is_super($rk))  json_fail('Cannot rename to a protected super-admin key.');
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $rk)) json_fail('Role key must start with a letter and use only lowercase letters, digits, and underscores.');

    if ($rk !== $old) {
        $chk = $conn->prepare("SELECT role_key FROM roles WHERE role_key=? AND role_key!=? LIMIT 1");
        $chk->bind_param('ss',$rk,$old); $chk->execute();
        if ($chk->get_result()->num_rows) { $chk->close(); json_fail("Key \"$rk\" is already taken."); }
        $chk->close();
    }

    $conn->begin_transaction();
    try {
        if ($rk !== $old) {
            $u1 = $conn->prepare("UPDATE role_permissions SET role_key=? WHERE role_key=?");
            $u1->bind_param('ss',$rk,$old); $u1->execute(); $u1->close();
            $u2 = $conn->prepare("UPDATE admin_users SET role=? WHERE role=?");
            $u2->bind_param('ss',$rk,$old); $u2->execute(); $u2->close();
        }
        $u = $conn->prepare("UPDATE roles SET role_key=?, role_name=?, can_manage_permissions=? WHERE role_key=? LIMIT 1");
        $u->bind_param('ssis',$rk,$rn,$cmp,$old);
        if (!$u->execute()) throw new RuntimeException($u->error);
        $u->close();
        $conn->commit();
        json_ok('Role updated.', ['role_key' => $rk]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Update failed: '.$e->getMessage());
    }
}



if ($action === 'delete_role') {
    $rk = trim($_POST['role_key'] ?? '');
    if ($rk === '')    json_fail('Role key is required.');
    if (is_super($rk)) json_fail('Super-admin role cannot be deleted.');

    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM role_permissions WHERE role_key=?");
        $d1->bind_param('s',$rk); $d1->execute(); $d1->close();
        $d2 = $conn->prepare("DELETE FROM roles WHERE role_key=? LIMIT 1");
        $d2->bind_param('s',$rk); $d2->execute(); $d2->close();
        $conn->commit();
        json_ok('Role deleted.', ['role_key' => $rk]);
    } catch (Throwable $e) {
        $conn->rollback();
        json_fail('Delete failed: '.$e->getMessage());
    }
}



json_fail('Unknown action: '.htmlspecialchars($action, ENT_QUOTES, 'UTF-8'), 400);