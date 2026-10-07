<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['HTTPS'] = 'on';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/private-files.php';
require_once __DIR__ . '/../includes/encryption.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$scenario = $argv[1] ?? '';
if ($scenario !== '') {
    // Rejection helpers intentionally exit; verify their status at shutdown.
    register_shutdown_function(static function () use ($scenario): void {
        if (http_response_code() !== 403) {
            fwrite(STDERR, "FAIL: {$scenario} was not rejected\n");
            exit(1);
        }
        echo "\nPASS: {$scenario}\n";
    });
    $_SERVER['REQUEST_METHOD'] = 'POST';
    if ($scenario === 'cross-origin') {
        $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
        site_validate_request_origin('https://www.edtech.hivecolab.com');
    } elseif ($scenario === 'null-origin') {
        $_SERVER['HTTP_ORIGIN'] = 'null';
        site_validate_request_origin('https://www.edtech.hivecolab.com');
    } elseif ($scenario === 'cross-site') {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        site_validate_request_origin('https://www.edtech.hivecolab.com');
    } elseif ($scenario === 'cross-referer') {
        $_SERVER['HTTP_REFERER'] = 'https://attacker.example/form';
        site_validate_request_origin('https://www.edtech.hivecolab.com');
    } elseif ($scenario === 'missing-token') {
        site_require_csrf();
    } elseif ($scenario === 'wrong-token') {
        site_csrf_token();
        $_POST['site_csrf_token'] = str_repeat('0', 64);
        site_require_csrf();
    } elseif ($scenario === 'array-token') {
        site_csrf_token();
        $_POST['site_csrf_token'] = ['invalid'];
        site_require_csrf();
    }
    exit(1);
}

$params = session_get_cookie_params();
check($params['secure'] && $params['httponly'] && $params['samesite'] === 'Lax', 'Secure session cookie settings');
check(ini_get('session.use_strict_mode') === '1', 'Strict sessions');
$token = site_csrf_token();
check(strlen($token) === 64 && ctype_xdigit($token), 'Random CSRF token format');
check($token === site_csrf_token(), 'Token stable across forms in one session');
$_POST['site_csrf_token'] = $token;
site_require_csrf();
check(site_origin('https://EXAMPLE.com/path') === site_origin('https://example.com:443/other'), 'Normalize default ports and host case');
check(site_origin('https://example.com') !== site_origin('http://example.com'), 'Reject scheme changes');
check(site_origin('null') === '', 'Reject opaque origins');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'https://www.edtech.hivecolab.com';
site_validate_request_origin('https://www.edtech.hivecolab.com');
unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_REFERER'] = 'https://www.edtech.hivecolab.com/contact';
site_validate_request_origin('https://www.edtech.hivecolab.com');
unset($_SERVER['HTTP_REFERER']);
site_validate_request_origin('https://www.edtech.hivecolab.com');
echo "PASS: session settings, valid CSRF, and same-origin requests\n";

$_SESSION['admin_id'] = 7;
$state = site_calendar_oauth_state(42);
check(strlen($state) === 64, 'OAuth state is random, not a mentor ID');
check(site_consume_calendar_oauth_state($state) === 42, 'OAuth state resolves session-bound mentor');
check(site_consume_calendar_oauth_state($state) === null, 'OAuth state cannot be replayed');
site_calendar_oauth_state(42);
check(site_consume_calendar_oauth_state('42') === null, 'Reject forged numeric OAuth state');
$state = site_calendar_oauth_state(42);
$_SESSION['calendar_oauth']['expires_at'] = time() - 1;
check(site_consume_calendar_oauth_state($state) === null, 'Reject expired OAuth state');
$state = site_calendar_oauth_state(42);
$_SESSION['admin_id'] = 8;
check(site_consume_calendar_oauth_state($state) === null, 'Reject OAuth state from a different login');
echo "PASS: OAuth forgery, replay, expiry, and account binding\n";

$html = '<html><head><title>Forms</title></head><body><form method="POST" action="/save"></form>'
    . '<form method=post action="https://external.example/save"></form>'
    . '<script>const s = "<form method=post>";</script><!-- <form method=post> -->'
    . '<textarea><form method=post></textarea><form method=GET></form></body></html>';
$secured = site_protect_html($html, 'https://www.edtech.hivecolab.com', $token);
check(substr_count($secured, 'name="site_csrf_token"') === 1, 'Only local POST forms receive server-rendered tokens');
check(str_contains($secured, 'name="csrf-token"'), 'AJAX token provided in head');
check(str_contains($secured, '<script>const s = "<form method=post>";</script>'), 'Script literals preserved');
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
unset($_POST['site_csrf_token']);
site_require_csrf();
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
check(site_private_can_read(['venture_id' => 1], ['venture_id' => 1]), 'Owner may read own file');
check(!site_private_can_read(['venture_id' => 1], ['venture_id' => 2]), 'Different venture denied');
check(!site_private_can_read(['venture_id' => 1], []), 'Anonymous denied');
check(site_private_can_read(['mentor_id' => 5], ['mentor_id' => 5]), 'Mentor may read own report');
check(!site_private_can_read(['mentor_id' => 5], ['mentor_id' => 6]), 'Different mentor denied');
check(!site_private_can_read(['staff_only' => true], ['venture_id' => 1]), 'Staff-only material denies ventures');
check(site_private_can_read(['staff_only' => true], ['staff_permission' => true]), 'Authorized staff may read');
foreach (['uploads/ventures/docs/../../config.php', 'uploads/ventures/docs/%2e%2e/file.pdf', 'uploads/ventures/docs/file.php', 'assets/images/favicon.png'] as $path) {
    check(site_private_resolve($path, dirname(__DIR__)) === null, 'Unsafe file path denied');
}
putenv('APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$cipher = site_encrypt_secret('synthetic-secret', 'test:1');
check(!str_contains($cipher, 'synthetic-secret'), 'Secret is not stored as plaintext');
check(site_decrypt_secret($cipher, 'test:1') === 'synthetic-secret', 'Encrypted secret round trip');
try {
    site_decrypt_secret($cipher, 'test:2');
    check(false, 'Reject ciphertext moved between records');
} catch (RuntimeException $expected) {}
putenv('APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
try {
    site_decrypt_secret($cipher, 'test:1');
    check(false, 'Reject incorrect encryption key');
} catch (RuntimeException $expected) {}
putenv('APP_ENCRYPTION_KEY=');
try {
    site_encrypt_secret('secret', 'test');
    check(false, 'Missing encryption key must fail closed');
} catch (RuntimeException $expected) {}
echo "PASS: shared form protection, file authorization, and authenticated encryption\n";

foreach (['cross-origin', 'null-origin', 'cross-site', 'cross-referer', 'missing-token', 'wrong-token', 'array-token'] as $case) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $status);
    check($status === 0, $case);
}
echo "All security checks passed.\n";
