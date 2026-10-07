<?php
declare(strict_types=1);

// Load before starting a session. Do not trust client-supplied proxy headers.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function site_csrf_token(): string
{
    if (empty($_SESSION['site_csrf_token'])) {
        $_SESSION['site_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['site_csrf_token'];
}

function site_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['site_csrf_token'] ?? '';
    $expected = $_SESSION['site_csrf_token'] ?? '';
    if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Your session could not be verified. Reload the form and try again.');
    }
}

function site_is_unsafe_request(): bool
{
    return !in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD', 'OPTIONS'], true);
}

function site_protect_html(string $html, string $baseUrl, string $token): string
{
    $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $field = '<input type="hidden" name="site_csrf_token" value="' . $escaped . '">';
    $jsVersion = filemtime(__DIR__ . '/../assets/js/security.js');
    $head = '<meta name="csrf-token" content="' . $escaped . '">'
        . '<script src="' . htmlspecialchars(rtrim($baseUrl, '/') . '/assets/js/security.js?v=' . $jsVersion, ENT_QUOTES, 'UTF-8') . '"></script>';
    // Tokenize whole tags (including quoted attributes) and raw-text elements.
    // Never interpret markup embedded in JavaScript, comments, or textarea values.
    $pattern = '~<!--.*?-->|<(script|style|textarea|title)\b[^>]*>.*?</\1\s*>|<(?:[^>"\']|"[^"]*"|\'[^\']*\')+>~is';
    $headAdded = false;
    return preg_replace_callback($pattern, static function (array $match) use ($baseUrl, $head, $field, &$headAdded): string {
        $tag = $match[0];
        if (!$headAdded && preg_match('~^<head(?:\s|>)~i', $tag)) {
            $headAdded = true;
            return $tag . $head;
        }
        if (!preg_match('~^<form\b~i', $tag)
            || !preg_match('~\bmethod\s*=\s*(?:"post"|\'post\'|post(?=\s|>))~i', $tag)) {
            return $tag;
        }
        if (preg_match('~\baction\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $action)) {
            $url = html_entity_decode($action[1] ?: ($action[2] ?? '') ?: ($action[3] ?? ''), ENT_QUOTES, 'UTF-8');
            if (str_starts_with($url, '//')) {
                $url = (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
            }
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)
                && site_origin($url) !== site_origin($baseUrl)) {
                return $tag;
            }
        }
        return $tag . $field;
    }, $html) ?? $html;
}

function site_enable_form_protection(string $baseUrl): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $token = site_csrf_token();
    ob_start(static function (string $output) use ($baseUrl, $token): string {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
                return $output;
            }
            if (stripos($header, 'Content-Disposition: attachment') === 0 || stripos($header, 'Content-Length:') === 0) {
                return $output;
            }
        }
        return site_protect_html($output, $baseUrl, $token);
    });
}

function site_origin(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
}

function site_calendar_oauth_state(int $mentorId): string
{
    $state = bin2hex(random_bytes(32));
    $_SESSION['calendar_oauth'] = [
        'state' => $state,
        'mentor_id' => $mentorId,
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'expires_at' => time() + 600,
    ];
    return $state;
}

function site_consume_calendar_oauth_state(string $state): ?int
{
    $pending = $_SESSION['calendar_oauth'] ?? null;
    unset($_SESSION['calendar_oauth']);
    if (!is_array($pending) || $state === ''
        || !hash_equals((string)$pending['state'], $state)
        || (int)$pending['expires_at'] <= time()
        || (int)$pending['admin_id'] <= 0
        || (int)$pending['admin_id'] !== (int)($_SESSION['admin_id'] ?? 0)
        || (int)$pending['mentor_id'] <= 0) {
        return null;
    }
    return (int)$pending['mentor_id'];
}

// Defense in depth for legacy forms/AJAX that do not yet send a CSRF token.
// Missing Origin headers are allowed for compatibility; this is not a token substitute.
function site_validate_request_origin(string $siteUrl): void
{
    if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $source = $origin !== '' ? $origin : $referer;
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
        || ($source !== '' && site_origin($source) !== site_origin($siteUrl))) {
        http_response_code(403);
        exit('This request could not be verified. Open the form on this website and try again.');
    }
}
