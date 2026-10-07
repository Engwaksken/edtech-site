<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

function redir(string $qs = ''): void {
    header('Location: ../mentor-assignments.php' . ($qs ? '?' . $qs : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redir();

$action = $_POST['action'] ?? '';

// --------------------------------------------------------------
//  CREATE  (handles cohort-wide OR one-per-venture)
// --------------------------------------------------------------
if ($action === 'create') {
    $mentor_id = (int)($_POST['mentor_id'] ?? 0);
    $cohort_id = (int)($_POST['cohort_id'] ?? 0);
    $scope     = $_POST['scope'] ?? 'cohort';
    $role      = trim($_POST['role']  ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if ($mentor_id <= 0) { flash('assignments', 'Please select a mentor.', 'error'); redir(); }
    if ($cohort_id <= 0) { flash('assignments', 'Please select a cohort.', 'error'); redir(); }

    // Validate mentor and cohort exist
    $chk = $conn->prepare("SELECT id FROM mentors WHERE id=? AND status='active' LIMIT 1");
    $chk->bind_param('i', $mentor_id); $chk->execute();
    if (!$chk->get_result()->num_rows) { flash('assignments', 'Mentor not found or inactive.', 'error'); redir(); }
    $chk->close();

    $chk2 = $conn->prepare("SELECT id FROM cohorts WHERE id=? LIMIT 1");
    $chk2->bind_param('i', $cohort_id); $chk2->execute();
    if (!$chk2->get_result()->num_rows) { flash('assignments', 'Cohort not found.', 'error'); redir(); }
    $chk2->close();

    $created = 0;
    $skipped = 0;

    if ($scope === 'venture') {
        // One or more specific ventures
        $venture_ids = array_filter(array_map('intval', (array)($_POST['venture_ids'] ?? [])));

        if (empty($venture_ids)) {
            // Fell back to cohort-wide if no ventures ticked
            $scope = 'cohort';
        } else {
            foreach ($venture_ids as $venture_id) {
                // Validate venture belongs to the cohort
                $vc = $conn->prepare("SELECT id FROM ventures WHERE id=? AND cohort_id=? LIMIT 1");
                $vc->bind_param('ii', $venture_id, $cohort_id); $vc->execute();
                if (!$vc->get_result()->num_rows) { $vc->close(); $skipped++; continue; }
                $vc->close();

                // Duplicate check
                $dup = $conn->prepare("SELECT id FROM mentor_assignments WHERE mentor_id=? AND cohort_id=? AND venture_id=? LIMIT 1");
                $dup->bind_param('iii', $mentor_id, $cohort_id, $venture_id); $dup->execute();
                if ($dup->get_result()->num_rows) { $dup->close(); $skipped++; continue; }
                $dup->close();

                $stmt = $conn->prepare("INSERT INTO mentor_assignments (mentor_id, cohort_id, venture_id, role, notes) VALUES (?,?,?,?,?)");
                $stmt->bind_param('iiiss', $mentor_id, $cohort_id, $venture_id, $role, $notes);
                if ($stmt->execute()) $created++;
                $stmt->close();
            }

            $msg = $created > 0
                ? $created . ' venture assignment' . ($created !== 1 ? 's' : '') . ' created.'
                  . ($skipped > 0 ? " $skipped skipped (already existed or invalid)." : '')
                : 'No assignments created — all already exist.';
            flash('assignments', $msg, $created > 0 ? 'success' : 'error');
            redir();
        }
    }

    if ($scope === 'cohort') {
        // Cohort-wide (venture_id = NULL)
        $dup = $conn->prepare("SELECT id FROM mentor_assignments WHERE mentor_id=? AND cohort_id=? AND venture_id IS NULL LIMIT 1");
        $dup->bind_param('ii', $mentor_id, $cohort_id); $dup->execute();
        if ($dup->get_result()->num_rows) {
            flash('assignments', 'This mentor is already assigned to that cohort.', 'error');
            redir();
        }
        $dup->close();

        $stmt = $conn->prepare("INSERT INTO mentor_assignments (mentor_id, cohort_id, venture_id, role, notes) VALUES (?,?,NULL,?,?)");
        $stmt->bind_param('iiss', $mentor_id, $cohort_id, $role, $notes);
        flash('assignments',
            $stmt->execute() ? 'Cohort-wide assignment created.' : 'Failed: ' . $stmt->error,
            $stmt->execute() ? 'success' : 'error');
        $stmt->close();
        redir();
    }
}

// --------------------------------------------------------------
//  UPDATE  (role and notes only)
// --------------------------------------------------------------
if ($action === 'update') {
    $id    = (int)($_POST['id']    ?? 0);
    $role  = trim($_POST['role']   ?? '');
    $notes = trim($_POST['notes']  ?? '');

    if ($id <= 0) { flash('assignments', 'Invalid assignment.', 'error'); redir(); }

    $stmt = $conn->prepare("UPDATE mentor_assignments SET role=?, notes=? WHERE id=? LIMIT 1");
    $stmt->bind_param('ssi', $role, $notes, $id);
    flash('assignments',
        $stmt->execute() ? 'Assignment updated.' : 'Update failed: ' . $stmt->error,
        $stmt->execute() ? 'success' : 'error');
    $stmt->close();
    redir();
}

// --------------------------------------------------------------
//  DELETE
// --------------------------------------------------------------
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash('assignments', 'Invalid assignment.', 'error'); redir(); }

    $stmt = $conn->prepare("DELETE FROM mentor_assignments WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    flash('assignments',
        $stmt->execute() ? 'Assignment removed.' : 'Delete failed: ' . $stmt->error,
        $stmt->execute() ? 'success' : 'error');
    $stmt->close();
    redir();
}

flash('assignments', 'Invalid action.', 'error');
redir();