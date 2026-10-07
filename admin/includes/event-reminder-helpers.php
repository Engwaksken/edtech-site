<?php
declare(strict_types=1);

if (!function_exists('event_csrf_token')) {
    function event_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['event_csrf_token'])) $_SESSION['event_csrf_token'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['event_csrf_token'];
    }
}
if (!function_exists('event_verify_csrf')) {
    function event_verify_csrf(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return !empty($_SESSION['event_csrf_token']) && is_string($token) && hash_equals((string)$_SESSION['event_csrf_token'], $token);
    }
}
if (!function_exists('event_calculate_reminder_at')) {
    function event_calculate_reminder_at(string $eventDate, int $value, string $unit, ?string $time = null): ?string {
        if ($eventDate === '' || $value < 0 || !in_array($unit, ['minutes','hours','days','weeks'], true)) return null;
        try { $event = new DateTimeImmutable($eventDate); } catch (Throwable $e) { return null; }
        if ($time && in_array($unit, ['days','weeks'], true)) {
            $parts = explode(':', $time);
            $event = $event->setTime((int)($parts[0] ?? 9), (int)($parts[1] ?? 0), 0);
        }
        if ($value === 0) return $event->format('Y-m-d H:i:s');
        return $event->sub(new DateInterval('PT' . ($unit === 'minutes' ? $value.'M' : ($unit === 'hours' ? $value.'H' : ($unit === 'days' ? $value.'D' : ($value*7).'D')))))->format('Y-m-d H:i:s');
    }
}
if (!function_exists('event_reminder_status')) {
    function event_reminder_status(array $event): array {
        if (empty($event['reminder_enabled'])) return ['disabled','Disabled','fa-bell-slash'];
        $eventAt = strtotime((string)($event['event_date'] ?? ''));
        if (($event['reminder_status'] ?? '') === 'sent' || !empty($event['reminder_sent_at'])) return ['sent','Sent','fa-check-circle'];
        if (($event['reminder_status'] ?? '') === 'failed') return ['failed','Failed','fa-circle-exclamation'];
        if ($eventAt && $eventAt < time()) return ['passed','Event passed','fa-clock'];
        return ['scheduled','Scheduled','fa-calendar-check'];
    }
}
if (!function_exists('event_log_reminder')) {
    function event_log_reminder(mysqli $conn, int $eventId, ?int $registrationId, string $recipient, string $method, string $status, ?string $error = null): void {
        $stmt=$conn->prepare("INSERT INTO event_reminder_logs(event_id,registration_id,recipient,delivery_method,status,error_message,attempted_at) VALUES(?,?,?,?,?,?,NOW())");
        if ($stmt) { $stmt->bind_param('iissss',$eventId,$registrationId,$recipient,$method,$status,$error); $stmt->execute(); $stmt->close(); }
    }
}
