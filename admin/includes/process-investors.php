<?php
declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

/**
 * Redirect to admin page.
 */
function redir(string $page = 'investors'): void
{
    header("Location: ../{$page}.php");
    exit;
}

/**
 * Redirect with query string.
 */
function redir_qs(string $page, string $qs = ''): void
{
    header("Location: ../{$page}.php" . ($qs ? "?{$qs}" : ''));
    exit;
}

/**
 * Safe integer nullable helper.
 */
function post_int_or_null(string $key): ?int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }

    return (int)$_POST[$key];
}

/**
 * Check duplicate investor email.
 */
function investor_email_exists(mysqli $conn, string $email, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $stmt = $conn->prepare("
            SELECT id 
            FROM investors 
            WHERE email = ? 
              AND id != ? 
            LIMIT 1
        ");
        $stmt->bind_param('si', $email, $excludeId);
    } else {
        $stmt = $conn->prepare("
            SELECT id 
            FROM investors 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
    }

    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/**
 * Check duplicate investor venture match.
 */
function investor_match_exists(
    mysqli $conn,
    int $investorId,
    int $ventureId,
    int $excludeId = 0
): bool {
    if ($excludeId > 0) {
        $stmt = $conn->prepare("
            SELECT id
            FROM investor_matches
            WHERE investor_id = ?
              AND venture_id = ?
              AND id != ?
            LIMIT 1
        ");
        $stmt->bind_param('iii', $investorId, $ventureId, $excludeId);
    } else {
        $stmt = $conn->prepare("
            SELECT id
            FROM investor_matches
            WHERE investor_id = ?
              AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $investorId, $ventureId);
    }

    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir();
}

$action = $_POST['action'] ?? '';

/* ==============================================================
   SAVE INVESTOR
   ============================================================== */
if ($action === 'save') {

    $id           = (int)($_POST['id'] ?? 0);
    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $firm_name    = trim($_POST['firm_name'] ?? '');
    $job_title    = trim($_POST['job_title'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $sector_focus = trim($_POST['sector_focus'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $website      = trim($_POST['website'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $admin_notes  = trim($_POST['admin_notes'] ?? '');

    $min_ticket = post_int_or_null('min_ticket');
    $max_ticket = post_int_or_null('max_ticket');

    $allowed_types = [
        'angel',
        'vc',
        'corporate',
        'family_office',
        'impact',
        'accelerator'
    ];

    $allowed_statuses = [
        'pending',
        'approved',
        'rejected',
        'suspended'
    ];

    $investor_type = in_array($_POST['investor_type'] ?? '', $allowed_types, true)
        ? $_POST['investor_type']
        : 'angel';

    $status = in_array($_POST['status'] ?? '', $allowed_statuses, true)
        ? $_POST['status']
        : 'pending';

    if ($name === '') {
        flash('investors', 'Investor name is required.', 'error');
        redir();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('investors', 'Valid email is required.', 'error');
        redir();
    }

    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        flash('investors', 'Enter a valid LinkedIn URL.', 'error');
        redir();
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        flash('investors', 'Enter a valid website URL.', 'error');
        redir();
    }

    if ($min_ticket !== null && $max_ticket !== null && $min_ticket > $max_ticket) {
        flash('investors', 'Minimum ticket cannot be greater than maximum ticket.', 'error');
        redir();
    }

    $allowed_stages = [
        'pre_seed',
        'seed',
        'series_a',
        'series_b',
        'growth'
    ];

    $stages_arr = array_filter(
        array_map('trim', (array)($_POST['investment_stages'] ?? []))
    );

    $stages_arr = array_values(array_intersect($stages_arr, $allowed_stages));
    $investment_stages = implode(',', $stages_arr);

    $photo = trim($_POST['existing_photo'] ?? '');
    $upload_err = '';

    if (function_exists('upload_image')) {
        $new_photo = upload_image('photo', 'investors', $upload_err);

        if ($new_photo) {
            if ($photo !== '' && function_exists('delete_image')) {
                delete_image($photo);
            }

            $photo = $new_photo;
        }
    }

    if (investor_email_exists($conn, $email, $id)) {
        flash('investors', 'An investor with that email already exists.', 'error');
        redir();
    }

    if ($id > 0) {

        $stmt = $conn->prepare("
            UPDATE investors SET
                name = ?,
                email = ?,
                firm_name = ?,
                job_title = ?,
                investor_type = ?,
                investment_stages = ?,
                sector_focus = ?,
                min_ticket = ?,
                max_ticket = ?,
                bio = ?,
                linkedin_url = ?,
                website = ?,
                phone = ?,
                location = ?,
                photo = ?,
                status = ?,
                admin_notes = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('investors', 'DB error: ' . $conn->error, 'error');
            redir();
        }

        $stmt->bind_param(
            'sssssssiiisssssssi',
            $name,
            $email,
            $firm_name,
            $job_title,
            $investor_type,
            $investment_stages,
            $sector_focus,
            $min_ticket,
            $max_ticket,
            $bio,
            $linkedin_url,
            $website,
            $phone,
            $location,
            $photo,
            $status,
            $admin_notes,
            $id
        );

        $ok = $stmt->execute();

        flash(
            'investors',
            $ok ? 'Investor updated successfully.' : 'Update failed: ' . $stmt->error,
            $ok ? 'success' : 'error'
        );

        $stmt->close();
        redir();
    }

    $stmt = $conn->prepare("
        INSERT INTO investors (
            name,
            email,
            firm_name,
            job_title,
            investor_type,
            investment_stages,
            sector_focus,
            min_ticket,
            max_ticket,
            bio,
            linkedin_url,
            website,
            phone,
            location,
            photo,
            status,
            admin_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('investors', 'DB error: ' . $conn->error, 'error');
        redir();
    }

    $stmt->bind_param(
        'sssssssiiisssssss',
        $name,
        $email,
        $firm_name,
        $job_title,
        $investor_type,
        $investment_stages,
        $sector_focus,
        $min_ticket,
        $max_ticket,
        $bio,
        $linkedin_url,
        $website,
        $phone,
        $location,
        $photo,
        $status,
        $admin_notes
    );

    $ok = $stmt->execute();

    flash(
        'investors',
        $ok ? 'Investor created successfully.' : 'Create failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir();
}

/* ==============================================================
   MODERATE INVESTOR
   ============================================================== */
if ($action === 'moderate') {

    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    $allowed = [
        'approved',
        'rejected',
        'suspended',
        'pending'
    ];

    if ($id <= 0 || !in_array($status, $allowed, true)) {
        flash('investors', 'Invalid moderation request.', 'error');
        redir();
    }

    $stmt = $conn->prepare("
        UPDATE investors 
        SET status = ? 
        WHERE id = ?
    ");

    if (!$stmt) {
        flash('investors', 'DB error: ' . $conn->error, 'error');
        redir();
    }

    $stmt->bind_param('si', $status, $id);
    $ok = $stmt->execute();

    flash(
        'investors',
        $ok ? 'Investor status set to ' . ucfirst($status) . '.' : 'Failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir();
}

/* ==============================================================
   DELETE INVESTOR
   ============================================================== */
if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('investors', 'Invalid investor.', 'error');
        redir();
    }

    $stmt = $conn->prepare("
        SELECT photo 
        FROM investors 
        WHERE id = ? 
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['photo']) && function_exists('delete_image')) {
        delete_image($row['photo']);
    }

    $stmt = $conn->prepare("
        DELETE FROM investors 
        WHERE id = ? 
        LIMIT 1
    ");

    if (!$stmt) {
        flash('investors', 'DB error: ' . $conn->error, 'error');
        redir();
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();

    flash(
        'investors',
        $ok ? 'Investor deleted successfully.' : 'Delete failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir();
}

/* ==============================================================
   SAVE INVESTOR MATCH
   ============================================================== */
if ($action === 'save_match') {

    $id          = (int)($_POST['id'] ?? 0);
    $investor_id = (int)($_POST['investor_id'] ?? 0);
    $venture_id  = (int)($_POST['venture_id'] ?? 0);

    $match_score = ($_POST['match_score'] ?? '') !== ''
        ? max(0, min(100, (int)$_POST['match_score']))
        : null;

    $notes = trim($_POST['notes'] ?? '');

    $allowed_ms = [
        'pending',
        'interested',
        'meeting_scheduled',
        'passed',
        'invested'
    ];

    $status = in_array($_POST['status'] ?? '', $allowed_ms, true)
        ? $_POST['status']
        : 'pending';

    if ($investor_id <= 0 || $venture_id <= 0) {
        flash('matches', 'Investor and venture are required.', 'error');
        redir('investor-matches');
    }

    if (investor_match_exists($conn, $investor_id, $venture_id, $id)) {
        flash('matches', 'This investor–venture match already exists.', 'error');
        redir('investor-matches');
    }

    try {

        if ($id > 0) {

            $stmt = $conn->prepare("
                UPDATE investor_matches SET
                    investor_id = ?,
                    venture_id = ?,
                    status = ?,
                    match_score = ?,
                    notes = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                flash('matches', 'DB error: ' . $conn->error, 'error');
                redir('investor-matches');
            }

            $stmt->bind_param(
                'iisisi',
                $investor_id,
                $venture_id,
                $status,
                $match_score,
                $notes,
                $id
            );

        } else {

            $stmt = $conn->prepare("
                INSERT INTO investor_matches (
                    investor_id,
                    venture_id,
                    status,
                    match_score,
                    notes
                ) VALUES (?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                flash('matches', 'DB error: ' . $conn->error, 'error');
                redir('investor-matches');
            }

            $stmt->bind_param(
                'iisis',
                $investor_id,
                $venture_id,
                $status,
                $match_score,
                $notes
            );
        }

        $ok = $stmt->execute();

        flash(
            'matches',
            $ok ? 'Match saved successfully.' : 'Failed: ' . $stmt->error,
            $ok ? 'success' : 'error'
        );

        $stmt->close();

    } catch (mysqli_sql_exception $e) {

        if (str_contains($e->getMessage(), 'uq_investor_venture') || str_contains($e->getMessage(), 'Duplicate entry')) {
            flash('matches', 'This investor–venture match already exists.', 'error');
        } else {
            flash('matches', 'Database error: ' . $e->getMessage(), 'error');
        }
    }

    redir('investor-matches');
}

/* ==============================================================
   DELETE INVESTOR MATCH
   ============================================================== */
if ($action === 'delete_match') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('matches', 'Invalid match.', 'error');
        redir('investor-matches');
    }

    $stmt = $conn->prepare("
        DELETE FROM investor_matches 
        WHERE id = ? 
        LIMIT 1
    ");

    if (!$stmt) {
        flash('matches', 'DB error: ' . $conn->error, 'error');
        redir('investor-matches');
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();

    flash(
        'matches',
        $ok ? 'Match removed successfully.' : 'Failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir('investor-matches');
}

flash('investors', 'Invalid action.', 'error');
redir();