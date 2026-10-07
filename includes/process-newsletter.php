<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

$flash_msg = '';
$flash_type = '';

/* Unsubscribe */
if (!empty($_GET['unsubscribe'])) {
    $email = trim(urldecode((string)$_GET['unsubscribe']));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("
            UPDATE newsletter_subscribers 
            SET status = 'unsubscribed' 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();

        $flash_msg = 'You have been unsubscribed successfully.';
        $flash_type = 'success';
    } else {
        $flash_msg = 'Invalid unsubscribe link.';
        $flash_type = 'error';
    }
}

/* Subscribe */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'subscribe') {
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash_msg = 'Please enter a valid email address.';
        $flash_type = 'error';
    } else {
        $chk = $conn->prepare("
            SELECT id, status 
            FROM newsletter_subscribers 
            WHERE email = ? 
            LIMIT 1
        ");
        $chk->bind_param('s', $email);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing && $existing['status'] === 'active') {
            $flash_msg = 'You are already subscribed.';
            $flash_type = 'info';
        } elseif ($existing) {
            $subscriber_id = (int)$existing['id'];

            $upd = $conn->prepare("
                UPDATE newsletter_subscribers 
                SET status = 'active',
                    name = ?
                WHERE id = ?
                LIMIT 1
            ");
            $upd->bind_param('si', $name, $subscriber_id);
            $upd->execute();
            $upd->close();

            $flash_msg = 'Welcome back. You have been re-subscribed.';
            $flash_type = 'success';
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            $stmt = $conn->prepare("
                INSERT INTO newsletter_subscribers 
                    (email, name, ip)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('sss', $email, $name, $ip);
            $stmt->execute();
            $stmt->close();

            $flash_msg = 'Thank you for subscribing.';
            $flash_type = 'success';
        }
    }
}

/* Archive */
$archive = $conn->query("
    SELECT 
        id,
        subject,
        preheader,
        from_name,
        sent_at
    FROM newsletters
    WHERE status = 'sent'
    ORDER BY sent_at DESC
    LIMIT 12
");