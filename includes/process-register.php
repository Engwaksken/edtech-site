<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function reg_redirect(int $event_id): void
{
    header('Location: ../register-event.php?event_id=' . $event_id);
    exit;
}

function reg_fail(int $event_id, string $message, array $old = []): void
{
    $_SESSION['register_message'] = $message;
    $_SESSION['register_message_type'] = 'danger';
    $_SESSION['register_old'] = $old;
    reg_redirect($event_id);
}

function reg_success(int $event_id, string $message): void
{
    $_SESSION['register_message'] = $message;
    $_SESSION['register_message_type'] = 'success';
    unset($_SESSION['register_old']);
    reg_redirect($event_id);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../');
    exit;
}

$event_id      = (int)($_POST['event_id'] ?? 0);
$venture_id    = (int)($_POST['venture_id'] ?? 0);
$founder_type  = trim($_POST['founder_type'] ?? 'cofounder1');
$name          = trim($_POST['name'] ?? '');
$email         = trim($_POST['email'] ?? '');
$organisation  = trim($_POST['organisation'] ?? '');
$role          = trim($_POST['role'] ?? '');
$contact       = trim($_POST['contact'] ?? '');
$website       = trim($_POST['website'] ?? '');

$old = [
    'event_id'      => $event_id,
    'venture_id'    => $venture_id,
    'founder_type'  => $founder_type,
    'name'          => $name,
    'email'         => $email,
    'organisation'  => $organisation,
    'role'          => $role,
    'contact'       => $contact,
    'website'       => $website,
];

if ($event_id <= 0) {
    die('Invalid event.');
}

if (!in_array($founder_type, ['cofounder1', 'cofounder2'], true)) {
    $founder_type = 'cofounder1';
}

$stmt = $conn->prepare("
    SELECT 
        e.*,
        (
            SELECT COUNT(*) 
            FROM event_registrations r 
            WHERE r.event_id = e.id
        ) AS reg_count
    FROM events e
    WHERE e.id = ?
    LIMIT 1
");

$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    reg_fail($event_id, 'Event not found.', $old);
}

if ((int)$event['is_public'] !== 1) {
    reg_fail($event_id, 'This event is not open for public registration.', $old);
}

if (($event['status'] ?? '') === 'cancelled') {
    reg_fail($event_id, 'This event has been cancelled.', $old);
}

$capacity = (int)($event['capacity'] ?? 0);
$reg_count = (int)($event['reg_count'] ?? 0);

if ($capacity > 0 && $reg_count >= $capacity) {
    reg_fail($event_id, 'Sorry, this event is already full.', $old);
}

if (!empty($event['registration_deadline']) && strtotime($event['registration_deadline']) < time()) {
    reg_fail($event_id, 'Registration deadline has passed.', $old);
}


if ($venture_id > 0) {
    $vstmt = $conn->prepare("
        SELECT 
            id,
            name,
            cofounder1_name,
            cofounder1_email,
            cofounder1_contact,
            cofounder2_name,
            cofounder2_email,
            cofounder2_contact,
            sector,
            website
        FROM ventures
        WHERE id = ?
        LIMIT 1
    ");

    $vstmt->bind_param('i', $venture_id);
    $vstmt->execute();
    $venture = $vstmt->get_result()->fetch_assoc();
    $vstmt->close();

    if ($venture) {
        $organisation = $organisation !== '' ? $organisation : (string)$venture['name'];
        $website = $website !== '' ? $website : (string)$venture['website'];

        if ($founder_type === 'cofounder2') {
            $name = $name !== '' ? $name : (string)$venture['cofounder2_name'];
            $email = $email !== '' ? $email : (string)$venture['cofounder2_email'];
            $contact = $contact !== '' ? $contact : (string)$venture['cofounder2_contact'];

            if ($name === '' && $email === '') {
                $name = (string)$venture['cofounder1_name'];
                $email = (string)$venture['cofounder1_email'];
                $contact = (string)$venture['cofounder1_contact'];
                $founder_type = 'cofounder1';
            }
        } else {
            $name = $name !== '' ? $name : (string)$venture['cofounder1_name'];
            $email = $email !== '' ? $email : (string)$venture['cofounder1_email'];
            $contact = $contact !== '' ? $contact : (string)$venture['cofounder1_contact'];
        }

        if ($role === '') {
            $role = !empty($venture['sector'])
                ? 'Founder - ' . $venture['sector']
                : 'Founder';
        }
    }
}

$old['name'] = $name;
$old['email'] = $email;
$old['organisation'] = $organisation;
$old['role'] = $role;
$old['contact'] = $contact;
$old['website'] = $website;
$old['founder_type'] = $founder_type;

if ($name === '') {
    reg_fail($event_id, 'Please enter your full name.', $old);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reg_fail($event_id, 'Please enter a valid email address.', $old);
}

$dup = $conn->prepare("
    SELECT id 
    FROM event_registrations 
    WHERE event_id = ? AND email = ? 
    LIMIT 1
");
$dup->bind_param('is', $event_id, $email);
$dup->execute();
$exists = $dup->get_result()->num_rows > 0;
$dup->close();

if ($exists) {
    $_SESSION['register_message'] = 'This email is already registered for this event.';
    $_SESSION['register_message_type'] = 'warning';
    $_SESSION['register_old'] = $old;
    reg_redirect($event_id);
}

$checkin_code = strtoupper(substr(md5(uniqid($email, true)), 0, 8));


$stmt = $conn->prepare("
    INSERT INTO event_registrations 
        (event_id, name, email, organisation, role, attended, checkin_code, registered_at)
    VALUES 
        (?, ?, ?, ?, ?, 0, ?, NOW())
");

if (!$stmt) {
    reg_fail($event_id, 'Database error: ' . $conn->error, $old);
}

$stmt->bind_param(
    'isssss',
    $event_id,
    $name,
    $email,
    $organisation,
    $role,
    $checkin_code
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    reg_fail($event_id, 'Registration failed: ' . $error, $old);
}

$stmt->close();

reg_success(
    $event_id,
    'Registration successful. Your check-in code is: ' . $checkin_code
);