<?php

require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function iv_redirect(string $url, string $msg = '', string $type = 'success'): void
{
    if ($msg !== '') {
        if (function_exists('flash')) {
            flash('interviews', $msg, $type);
        } else {
            $_SESSION['interviews_flash'] = [
                'msg'  => $msg,
                'type' => $type
            ];
        }
    }

    header('Location: ' . $url);
    exit;
}

function iv_token(): string
{
    return bin2hex(random_bytes(20));
}

function iv_nullable_int($value): ?int
{
    $value = (int)$value;
    return $value > 0 ? $value : null;
}

function iv_datetime_or_null(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);

    if (strlen($value) === 16) {
        $value .= ':00';
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    iv_redirect('../interviews.php', 'Invalid request.', 'error');
}

$action = $_POST['action'] ?? '';

/* -------------------------------------------------------
   SAVE / UPDATE INTERVIEW
------------------------------------------------------- */
if ($action === 'save_interview') {

    $id             = (int)($_POST['id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $venture_id     = iv_nullable_int($_POST['venture_id'] ?? 0);
    $assigned_to    = trim($_POST['assigned_to'] ?? '');
    $interview_type = trim($_POST['interview_type'] ?? 'gap_analysis');
    $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
    $access_pin     = trim($_POST['access_pin'] ?? '');
    $expires_at     = iv_datetime_or_null($_POST['expires_at'] ?? '');
    $sections_json  = $_POST['sections_json'] ?? '[]';
    $questions_json = $_POST['questions_json'] ?? '[]';

    if ($title === '') {
        iv_redirect('../interviews.php?tab=create', 'Title is required.', 'error');
    }

    $allowed_types = [
        'gap_analysis',
        'venture_assessment',
        'progress_check',
        'onboarding',
        'exit_interview',
        'custom'
    ];

    if (!in_array($interview_type, $allowed_types, true)) {
        $interview_type = 'gap_analysis';
    }

    $sections_arr = json_decode($sections_json, true);
    if (!is_array($sections_arr)) {
        $sections_json = '[]';
    }

    $questions_arr = json_decode($questions_json, true);
    if (!is_array($questions_arr)) {
        $questions_json = '[]';
    }

    $created_by = (int)($_SESSION['admin_id'] ?? ($ADMIN['id'] ?? 0));

    if ($id > 0) {

        $stmt = $conn->prepare("
            UPDATE interviews
            SET 
                title = ?,
                description = ?,
                venture_id = ?,
                assigned_to = ?,
                interview_type = ?,
                allow_multiple = ?,
                access_pin = ?,
                expires_at = ?,
                sections_json = ?,
                questions_json = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            iv_redirect(
                '../interviews.php?tab=create&edit=' . $id,
                'DB error: ' . $conn->error,
                'error'
            );
        }

        $stmt->bind_param(
            'ssississssi',
            $title,
            $description,
            $venture_id,
            $assigned_to,
            $interview_type,
            $allow_multiple,
            $access_pin,
            $expires_at,
            $sections_json,
            $questions_json,
            $id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            iv_redirect(
                '../interviews.php?tab=create&edit=' . $id,
                'Update failed: ' . $error,
                'error'
            );
        }

        $stmt->close();

        iv_redirect('../interviews.php?tab=list', 'Interview session updated successfully.');
    }

    $token = iv_token();

    $stmt = $conn->prepare("
        INSERT INTO interviews
            (
                title,
                description,
                venture_id,
                assigned_to,
                interview_type,
                allow_multiple,
                access_pin,
                expires_at,
                token,
                sections_json,
                questions_json,
                status,
                created_by
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");

    if (!$stmt) {
        iv_redirect('../interviews.php?tab=create', 'DB error: ' . $conn->error, 'error');
    }

    $stmt->bind_param(
        'ssississsssi',
        $title,
        $description,
        $venture_id,
        $assigned_to,
        $interview_type,
        $allow_multiple,
        $access_pin,
        $expires_at,
        $token,
        $sections_json,
        $questions_json,
        $created_by
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        iv_redirect('../interviews.php?tab=create', 'Insert failed: ' . $error, 'error');
    }

    $new_id = (int)$conn->insert_id;
    $stmt->close();

    iv_redirect(
        '../interviews.php?tab=create&edit=' . $new_id,
        'Session created successfully. Share the generated link below.'
    );
}

/* -------------------------------------------------------
   DELETE INTERVIEW
------------------------------------------------------- */
if ($action === 'delete_interview') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id < 1) {
        iv_redirect('../interviews.php?tab=list', 'Invalid interview selected.', 'error');
    }

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("
            DELETE ia
            FROM interview_answers ia
            INNER JOIN interview_responses ir ON ir.id = ia.response_id
            WHERE ir.interview_id = ?
        ");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM interview_responses WHERE interview_id = ?");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM interviews WHERE id = ? LIMIT 1");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        iv_redirect('../interviews.php?tab=list', 'Interview session deleted successfully.');

    } catch (Throwable $e) {

        $conn->rollback();

        iv_redirect(
            '../interviews.php?tab=list',
            'Delete failed: ' . $e->getMessage(),
            'error'
        );
    }
}

/* -------------------------------------------------------
   DELETE SINGLE RESPONSE
------------------------------------------------------- */
if ($action === 'delete_response') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id < 1) {
        iv_redirect('../interviews.php?tab=responses', 'Invalid response selected.', 'error');
    }

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("DELETE FROM interview_answers WHERE response_id = ?");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM interview_responses WHERE id = ? LIMIT 1");

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        iv_redirect('../interviews.php?tab=responses', 'Response deleted successfully.');

    } catch (Throwable $e) {

        $conn->rollback();

        iv_redirect(
            '../interviews.php?tab=responses',
            'Delete failed: ' . $e->getMessage(),
            'error'
        );
    }
}

iv_redirect('../interviews.php', 'Unknown action.', 'error');