<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

site_require_csrf();

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';

// Validate
if (!$name || !$email || !$subject || !$message) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($name) > 200 || strlen($email) > 254 || strlen($subject) > 255 || strlen($message) > 10000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please shorten your message or contact details and try again.']);
    exit;
}

// Rate limit: max 3 messages per IP per hour
$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM contact_messages WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$stmt->bind_param('s', $ip);
$stmt->execute();
$count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($count >= 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many messages. Please try again in an hour.']);
    exit;
}

$stmt = $conn->prepare('INSERT INTO contact_messages (full_name,email,subject,message,ip_address) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sssss', $name, $email, $subject, $message, $ip);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'We could not save your message. Please try again shortly.']);
    exit;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Thank you, ' . $name . '. Your message has been received. Our team will get back to you soon.'
]);
