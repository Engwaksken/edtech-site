<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_faqs(): void
{
    header('Location: ../faqs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_faqs();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {

    $id          = (int)($_POST['id'] ?? 0);
    $question    = trim($_POST['question'] ?? '');
    $answer      = trim($_POST['answer'] ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $status      = (int)($_POST['status'] ?? 1);

    $status = $status === 1 ? 1 : 0;

    if ($question === '') {
        flash('faqs', 'Question is required.', 'error');

        header('Location: ../faqs.php' . ($id > 0 ? '?edit=' . $id : ''));
        exit;
    }

    if ($id > 0) {

        $stmt = $conn->prepare("
            UPDATE faqs
            SET question = ?,
                answer = ?,
                sort_order = ?,
                status = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('faqs', 'Database error: ' . $conn->error, 'error');
            redirect_faqs();
        }

        $stmt->bind_param(
            "ssiii",
            $question,
            $answer,
            $sort_order,
            $status,
            $id
        );

        if ($stmt->execute()) {
            flash('faqs', 'FAQ updated successfully.');
        } else {
            flash('faqs', 'Failed to update FAQ: ' . $stmt->error, 'error');
        }

        $stmt->close();

        redirect_faqs();
    }

    $stmt = $conn->prepare("
        INSERT INTO faqs
            (question, answer, sort_order, status)
        VALUES
            (?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('faqs', 'Database error: ' . $conn->error, 'error');
        redirect_faqs();
    }

    $stmt->bind_param(
        "ssii",
        $question,
        $answer,
        $sort_order,
        $status
    );

    if ($stmt->execute()) {
        flash('faqs', 'FAQ added successfully.');
    } else {
        flash('faqs', 'Failed to add FAQ: ' . $stmt->error, 'error');
    }

    $stmt->close();

    redirect_faqs();
}

if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('faqs', 'Invalid FAQ selected.', 'error');
        redirect_faqs();
    }

    $stmt = $conn->prepare("
        DELETE FROM faqs
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        flash('faqs', 'Database error: ' . $conn->error, 'error');
        redirect_faqs();
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('faqs', 'FAQ deleted successfully.');
    } else {
        flash('faqs', 'Failed to delete FAQ: ' . $stmt->error, 'error');
    }

    $stmt->close();

    redirect_faqs();
}

flash('faqs', 'Invalid request action.', 'error');
redirect_faqs();