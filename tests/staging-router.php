<?php
declare(strict_types=1);
if (getenv('EDTECH_STAGING') !== '1' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/__test/')) {
    require $root . '/includes/config.php';
    if ($path === '/__test/form') {
        echo '<!doctype html><html><head><title>Staging form</title></head><body><form method="POST" action="/__test/post"><button>Save</button></form></body></html>';
    } elseif ($path === '/__test/portal') {
        $_SESSION['venture_id'] = 1;
        $_SESSION['venture_login'] = true;
        $page_title = 'Your workspace';
        $current_nav = 'dashboard';
        require $root . '/layout.php';
        echo '<h1>Welcome back</h1><p>Your fellowship workspace brings your team, documents, and programme updates together.</p><div class="card" style="padding:24px;margin-top:24px"><h2>Keep your venture profile up to date</h2><p>Share your latest progress with the programme team.</p><form method="POST" action="/__test/post"><button class="btn btn-primary" type="submit">Save changes</button></form></div></main></div></body></html>';
    } elseif ($path === '/__test/post') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    } elseif ($path === '/__test/session') {
        unset($_SESSION['venture_id'], $_SESSION['venture_login'], $_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['mentor_id']);
        if (!empty($_GET['venture'])) {
            $_SESSION['venture_id'] = (int)$_GET['venture'];
            $_SESSION['venture_login'] = true;
        } elseif (!empty($_GET['admin'])) {
            $_SESSION['admin_id'] = (int)$_GET['admin'];
        }
        header('Content-Type: application/json');
        echo '{"ok":true}';
    }
    return true;
}
require_once $root . '/includes/private-files.php';
if (site_private_group(ltrim($path, '/')) !== null) {
    $_GET = ['path' => ltrim(rawurldecode($path), '/')];
    require $root . '/private-file.php';
    return true;
}
if (preg_match('~^/(includes|admin/includes|tests|bin)(/|$)~', $path)) {
    http_response_code(403);
    return true;
}
if ($path === '/favicon.ico') {
    header('Content-Type: image/png');
    readfile($root . '/assets/images/favicon.png');
    return true;
}
$pages = ['/' => 'index.php', '/login' => 'login.php', '/admin/login' => 'admin/login.php', '/faqs' => 'faqs.php', '/logout' => 'logout.php', '/admin/logout' => 'admin/logout.php'];
if (isset($pages[$path])) {
    require $root . '/' . $pages[$path];
    return true;
}
return false;
