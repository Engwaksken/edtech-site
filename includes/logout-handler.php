<?php
declare(strict_types=1);
require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/security.php';

function site_handle_logout(string $login, string $cancel, string $base): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $token = htmlspecialchars(site_csrf_token(), ENT_QUOTES, 'UTF-8');
        $back = htmlspecialchars($cancel, ENT_QUOTES, 'UTF-8');
        $asset = htmlspecialchars(rtrim($base, '/'), ENT_QUOTES, 'UTF-8');
        header('Cache-Control: private, no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign out</title><link rel="icon" href="'.$asset.'/assets/images/favicon.png"><link rel="stylesheet" href="'.$asset.'/assets/css/refinements.css"><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#172033;font:16px/1.6 system-ui,sans-serif}.box{width:min(420px,calc(100% - 64px));padding:28px;background:white;border:1px solid #e2e8f0;border-radius:16px}h1{margin-top:0;font-size:26px}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.btn{border:0;cursor:pointer;text-decoration:none}.btn-primary{background:#fc7f10;color:white}</style></head><body><main class="box"><h1>Ready to sign out?</h1><p>You can sign in again whenever you need your workspace.</p><form method="POST"><input type="hidden" name="site_csrf_token" value="'.$token.'"><div class="actions"><button type="submit" class="btn btn-primary">Sign out</button><a href="'.$back.'" class="btn">Back to workspace</a></div></form></main></body></html>';
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    site_require_csrf();
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires'=>time()-3600,'path'=>$params['path'],'domain'=>$params['domain'],
        'secure'=>$params['secure'],'httponly'=>true,'samesite'=>$params['samesite'] ?: 'Lax']);
    session_destroy();
    header('Location: ' . $login);
    exit;
}
