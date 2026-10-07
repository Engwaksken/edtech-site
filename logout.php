<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/logout-handler.php';
site_handle_logout('/login?logged_out=1', '/dashboard', getenv('SITE_URL') ?: 'https://www.edtech.hivecolab.com');
