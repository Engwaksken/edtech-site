<?php
declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function ms_redirect(string $url, string $msg = '', string $type = 'success', array $old = []): void
{
    if ($msg !== '') {
        if (function_exists('flash')) {
            flash('milestones', $msg, $type);
        } else {
            $_SESSION['milestones_flash'] = [
                'msg'  => $msg,
                'type' => $type
            ];
        }
    }

    if (!empty($old)) {
        $_SESSION['milestones_old'] = $old;
    }

    header('Location: ' . $url);
    exit;
}

function ms_post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function ms_entry_url(int $id = 0): string
{
    return '../milestones.php?tab=entry' . ($id > 0 ? '&edit=' . $id : '');
}

function ms_date(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') return null;

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : null;
}

function ms_status(string $status): string
{
    return in_array($status, ['not_started', 'in_progress', 'resolved'], true)
        ? $status
        : 'not_started';
}

function ms_hml(string $value): string
{
    return in_array($value, ['H', 'M', 'L'], true) ? $value : '';
}

function ms_category(string $category): string
{
    $allowed = [
        'business_model',
        'pedagogy',
        'technology',
        'marketing_sales',
        'team_talent',
        'finance',
        'monitoring_eval',
        'scale_partnerships',
        'sops'
    ];

    return in_array($category, $allowed, true) ? $category : 'business_model';
}

function ms_admin_id(): int
{
    global $ADMIN;

    return (int)($ADMIN['id'] ?? $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
}

function ms_exists(mysqli $conn, string $table, int $id): bool
{
    $allowed_tables = ['gap_analyses', 'gap_items'];

    if (!in_array($table, $allowed_tables, true)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");

    if (!$stmt) return false;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found;
}

function ms_table_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return $cache[$key] = false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$key] = ((int)($row['total'] ?? 0) > 0);
}

function ms_normalize_post_for_old(array $post): array
{
    $old = $post;

    if (!isset($old['id']) || $old['id'] === '') {
        $old['id'] = 0;
    }

    if (!isset($old['gaps_json']) || trim((string)$old['gaps_json']) === '') {
        $old['gaps_json'] = '[]';
    }

    return $old;
}

function ms_clean_gaps(string $json): array
{
    $rows = json_decode($json, true);

    if (!is_array($rows)) {
        return [];
    }

    $clean = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $challenge = trim((string)($row['challenge'] ?? ''));
        if ($challenge === '') continue;

        $status = ms_status((string)($row['status'] ?? 'not_started'));
        $pct = max(0, min(100, (int)($row['pct_complete'] ?? 0)));

        if ($status === 'resolved') {
            $pct = 100;
        }

        if ($pct > 0 && $status === 'not_started') {
            $status = 'in_progress';
        }

        $target_month_raw = trim((string)($row['target_month'] ?? ''));
        $target_month = $target_month_raw === '' ? null : max(1, min(12, (int)$target_month_raw));

        $clean[] = [
            'gap_category'     => ms_category((string)($row['gap_category'] ?? 'business_model')),
            'challenge'        => $challenge,
            'root_cause'       => trim((string)($row['root_cause'] ?? '')),
            'impact'           => ms_hml((string)($row['impact'] ?? '')),
            'urgency'          => ms_hml((string)($row['urgency'] ?? '')),
            'priority_score'   => max(1, min(5, (int)($row['priority_score'] ?? 1))),
            'intervention'     => trim((string)($row['intervention'] ?? '')),
            'responsible'      => trim((string)($row['responsible'] ?? '')),
            'target_month'     => $target_month,
            'resources'        => trim((string)($row['resources'] ?? '')),
            'status'           => $status,
            'pct_complete'     => $pct,
            'linked_milestone' => trim((string)($row['linked_milestone'] ?? '')),
            'notes'            => trim((string)($row['notes'] ?? '')),
        ];
    }

    return $clean;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_redirect('../milestones.php', 'Invalid request.', 'error');
}

$action = ms_post('action');

/* --------------------------------------------------
   SAVE / UPDATE ANALYSIS
-------------------------------------------------- */
if ($action === 'save_analysis') {
    $old_input = ms_normalize_post_for_old($_POST);

    $id = (int)($_POST['id'] ?? 0);
    $venture_id = (int)($_POST['venture_id'] ?? 0);

    $stage         = ms_post('stage');
    $founders      = ms_post('founders');
    $sector        = ms_post('sector', 'EdTech');
    $region        = ms_post('region');
    $analysis_date = ms_date(ms_post('analysis_date')) ?? date('Y-m-d');
    $analyst_name  = ms_post('analyst_name');
    $review_date   = ms_date(ms_post('review_date'));
    $key_risk      = ms_post('key_risk');
    $next_action   = ms_post('next_action');
    $created_by    = ms_admin_id();

    $gaps_json = (string)($_POST['gaps_json'] ?? '[]');
    $gaps = ms_clean_gaps($gaps_json);

    if ($venture_id <= 0) {
        ms_redirect(
            ms_entry_url($id),
            'Please select a valid venture.',
            'error',
            $old_input
        );
    }

    if ($analyst_name === '') {
        ms_redirect(
            ms_entry_url($id),
            'Analyst / coach name is required.',
            'error',
            $old_input
        );
    }

    if ($id > 0 && !ms_exists($conn, 'gap_analyses', $id)) {
        ms_redirect(
            '../milestones.php?tab=analyses',
            'Analysis not found. Cannot update.',
            'error',
            $old_input
        );
    }

    $conn->begin_transaction();

    try {
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE gap_analyses SET
                    venture_id = ?,
                    stage = ?,
                    founders = ?,
                    sector = ?,
                    region = ?,
                    analysis_date = ?,
                    analyst_name = ?,
                    review_date = ?,
                    key_risk = ?,
                    next_action = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare update failed: ' . $conn->error);
            }

            $stmt->bind_param(
                'isssssssssi',
                $venture_id,
                $stage,
                $founders,
                $sector,
                $region,
                $analysis_date,
                $analyst_name,
                $review_date,
                $key_risk,
                $next_action,
                $id
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Update analysis failed: ' . $stmt->error);
            }

            $stmt->close();

            $analysis_id = $id;

            $stmt = $conn->prepare("DELETE FROM gap_items WHERE analysis_id = ?");

            if (!$stmt) {
                throw new RuntimeException('Prepare old gaps delete failed: ' . $conn->error);
            }

            $stmt->bind_param('i', $analysis_id);

            if (!$stmt->execute()) {
                throw new RuntimeException('Delete old gaps failed: ' . $stmt->error);
            }

            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO gap_analyses (
                    venture_id,
                    stage,
                    founders,
                    sector,
                    region,
                    analysis_date,
                    analyst_name,
                    review_date,
                    key_risk,
                    next_action,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare insert failed: ' . $conn->error);
            }

            $stmt->bind_param(
                'isssssssssi',
                $venture_id,
                $stage,
                $founders,
                $sector,
                $region,
                $analysis_date,
                $analyst_name,
                $review_date,
                $key_risk,
                $next_action,
                $created_by
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Insert analysis failed: ' . $stmt->error);
            }

            $analysis_id = (int)$conn->insert_id;
            $stmt->close();
        }

        if (!empty($gaps)) {
            $stmt = $conn->prepare("
                INSERT INTO gap_items (
                    analysis_id,
                    gap_category,
                    challenge,
                    root_cause,
                    impact,
                    urgency,
                    priority_score,
                    intervention,
                    responsible,
                    target_month,
                    resources,
                    status,
                    pct_complete,
                    linked_milestone,
                    notes,
                    sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare gap insert failed: ' . $conn->error);
            }

            foreach ($gaps as $sort_order_index => $gap) {
                $gap_category     = $gap['gap_category'];
                $challenge        = $gap['challenge'];
                $root_cause       = $gap['root_cause'];
                $impact           = $gap['impact'];
                $urgency          = $gap['urgency'];
                $priority_score   = (int)$gap['priority_score'];
                $intervention     = $gap['intervention'];
                $responsible      = $gap['responsible'];
                $target_month     = $gap['target_month'];
                $resources        = $gap['resources'];
                $status           = $gap['status'];
                $pct_complete     = (int)$gap['pct_complete'];
                $linked_milestone = $gap['linked_milestone'];
                $notes            = $gap['notes'];
                $sort_order       = (int)$sort_order_index;

                $stmt->bind_param(
                    'isssssissississi',
                    $analysis_id,
                    $gap_category,
                    $challenge,
                    $root_cause,
                    $impact,
                    $urgency,
                    $priority_score,
                    $intervention,
                    $responsible,
                    $target_month,
                    $resources,
                    $status,
                    $pct_complete,
                    $linked_milestone,
                    $notes,
                    $sort_order
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException('Insert gap failed: ' . $stmt->error);
                }
            }

            $stmt->close();
        }

        $conn->commit();

        unset($_SESSION['milestones_old']);

        ms_redirect(
            '../milestones.php?tab=entry&edit=' . $analysis_id,
            $id > 0 ? 'Analysis updated successfully.' : 'Analysis saved successfully.'
        );

    } catch (Throwable $e) {
        $conn->rollback();

        error_log('[process-milestones] Save/update failed: ' . $e->getMessage());

        ms_redirect(
            ms_entry_url($id),
            'Save failed: ' . $e->getMessage(),
            'error',
            $old_input
        );
    }
}

/* --------------------------------------------------
   DELETE ANALYSIS
-------------------------------------------------- */
if ($action === 'delete_analysis') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ms_redirect('../milestones.php?tab=analyses', 'Invalid analysis.', 'error');
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("DELETE FROM gap_items WHERE analysis_id = ?");
        if (!$stmt) throw new RuntimeException($conn->error);

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM gap_analyses WHERE id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException($conn->error);

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $stmt->close();

        $conn->commit();

        unset($_SESSION['milestones_old']);

        ms_redirect('../milestones.php?tab=analyses', 'Analysis deleted successfully.');

    } catch (Throwable $e) {
        $conn->rollback();

        ms_redirect('../milestones.php?tab=analyses', 'Delete failed: ' . $e->getMessage(), 'error');
    }
}

/* --------------------------------------------------
   DELETE GAP
-------------------------------------------------- */
if ($action === 'delete_gap') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ms_redirect('../milestones.php?tab=tracker', 'Invalid gap.', 'error');
    }

    $stmt = $conn->prepare("DELETE FROM gap_items WHERE id = ? LIMIT 1");

    if (!$stmt) {
        ms_redirect('../milestones.php?tab=tracker', 'DB error: ' . $conn->error, 'error');
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ms_redirect(
        '../milestones.php?tab=tracker',
        $ok ? 'Gap deleted successfully.' : 'Delete failed: ' . $err,
        $ok ? 'success' : 'error'
    );
}

/* --------------------------------------------------
   UPDATE GAP STATUS
-------------------------------------------------- */
if ($action === 'update_gap_status') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ms_redirect('../milestones.php?tab=tracker', 'Invalid gap.', 'error');
    }

    $status = ms_status(ms_post('status'));
    $pct_complete = max(0, min(100, (int)($_POST['pct_complete'] ?? 0)));

    if ($status === 'resolved') {
        $pct_complete = 100;
    }

    if ($pct_complete > 0 && $status === 'not_started') {
        $status = 'in_progress';
    }

    $fields = ['status = ?', 'pct_complete = ?'];
    $types = 'si';
    $params = [$status, $pct_complete];

    if (ms_table_has_column($conn, 'gap_items', 'evidence')) {
        $fields[] = 'evidence = ?';
        $types .= 's';
        $params[] = ms_post('evidence');
    }

    if (ms_table_has_column($conn, 'gap_items', 'date_resolved')) {
        $fields[] = 'date_resolved = ?';
        $types .= 's';
        $params[] = ms_date(ms_post('date_resolved'));
    }

    if (ms_table_has_column($conn, 'gap_items', 'verified_by')) {
        $fields[] = 'verified_by = ?';
        $types .= 's';
        $params[] = ms_post('verified_by');
    }

    $types .= 'i';
    $params[] = $id;

    $sql = "UPDATE gap_items SET " . implode(', ', $fields) . " WHERE id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        ms_redirect('../milestones.php?tab=tracker', 'DB error: ' . $conn->error, 'error');
    }

    $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    ms_redirect(
        '../milestones.php?tab=tracker',
        $ok ? 'Gap updated successfully.' : 'Update failed: ' . $err,
        $ok ? 'success' : 'error'
    );
}

ms_redirect('../milestones.php', 'Unknown action.', 'error');