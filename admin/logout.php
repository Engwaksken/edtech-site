<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/logout-handler.php';
site_handle_logout('/admin/login?logged_out=1', '/admin/', getenv('SITE_URL') ?: 'https://www.edtech.hivecolab.com');
