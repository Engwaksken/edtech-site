<?php
declare(strict_types=1);

function site_load_environment(?string $file = null): void
{
    $configuredFile = getenv('APP_ENV_FILE') ?: '';
    $required = $file !== null || $configuredFile !== '';
    $file = $file ?? ($configuredFile !== '' ? $configuredFile : dirname(__DIR__) . '/.env');

    if (!is_file($file)) {
        if ($required) {
            throw new RuntimeException('The configured environment file does not exist.');
        }
        return; // Server-provided environment variables remain supported.
    }
    if (!is_readable($file)) {
        throw new RuntimeException('The environment file is not readable by PHP.');
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Run composer install before loading the environment file.');
    }
    require_once $autoload;
    if (!class_exists(\Dotenv\Dotenv::class)) {
        throw new RuntimeException('The environment loader dependency is missing. Run composer install.');
    }

    try {
        // The putenv adapter supports the application's existing getenv() calls.
        // Immutable loading preserves values already supplied by the server/shell.
        \Dotenv\Dotenv::createUnsafeImmutable(dirname($file), basename($file))->safeLoad();
    } catch (Throwable $exception) {
        // Parser exceptions can contain source lines, including passwords.
        throw new RuntimeException('Could not load the environment file. Check its syntax and file permissions.');
    }
}

site_load_environment();
