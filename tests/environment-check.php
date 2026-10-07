<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/environment.php';

function environment_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$temporaryRoot = getenv('EDTECH_TEST_TMP') ?: sys_get_temp_dir();
$fixture = $temporaryRoot . '/edtech-env-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
try {
    $file = $fixture . '/fixture.env';
    file_put_contents($file, "# Synthetic configuration only\nEDTECH_ENV_TEST_PASSWORD='synthetic # dollar\$ equals= semicolon;'\nEDTECH_ENV_TEST_EMPTY=\nEDTECH_ENV_TEST_URL=\"https://example.test/path\"\nEDTECH_ENV_TEST_OVERRIDE=file-value\n");
    putenv('EDTECH_ENV_TEST_OVERRIDE=server-value');
    site_load_environment($file);
    environment_assert(getenv('EDTECH_ENV_TEST_PASSWORD') === 'synthetic # dollar$ equals= semicolon;', 'Quoted passwords must preserve literal special characters');
    environment_assert(getenv('EDTECH_ENV_TEST_EMPTY') === '', 'Empty optional values must load');
    environment_assert(getenv('EDTECH_ENV_TEST_URL') === 'https://example.test/path', 'Double-quoted URL must load');
    environment_assert(getenv('EDTECH_ENV_TEST_OVERRIDE') === 'server-value', 'Existing process values must take precedence');
    echo "PASS: quoted values, empty values, and server precedence\n";

    $invalid = $fixture . '/invalid.env';
    file_put_contents($invalid, "EDTECH_ENV_TEST_INVALID=DO_NOT_PRINT_THIS_FAKE_SECRET unexpected unquoted whitespace\n");
    $rejected = false;
    try {
        site_load_environment($invalid);
    } catch (RuntimeException $exception) {
        $rejected = true;
        environment_assert(!str_contains($exception->getMessage(), 'DO_NOT_PRINT_THIS_FAKE_SECRET'), 'Parser errors must not disclose secret values');
    }
    environment_assert($rejected, 'Malformed environment file must fail');
    try {
        site_load_environment($fixture . '/missing.env');
        environment_assert(false, 'Explicit missing file must fail');
    } catch (RuntimeException $exception) {
        environment_assert(str_contains($exception->getMessage(), 'does not exist'), 'Missing file must report configuration failure');
    }
    echo "PASS: malformed/missing configuration fails without disclosing values\n";

    // Isolate the real CLI entry point from any existing project/server credentials.
    foreach (['includes', 'bin', 'vendor'] as $directory) mkdir($fixture . '/' . $directory, 0700);
    foreach (['environment.php', 'encryption.php', 'backup-encryption.php', 'config.php', 'security.php'] as $name) {
        copy($root . '/includes/' . $name, $fixture . '/includes/' . $name);
    }
    copy($root . '/bin/secure-storage.php', $fixture . '/bin/secure-storage.php');
    file_put_contents($fixture . '/vendor/autoload.php', '<?php return require ' . var_export($root . '/vendor/autoload.php', true) . ';');
    $key = base64_encode(str_repeat('K', 32));
    file_put_contents($fixture . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=synthetic_fixture_user\nDB_PASS='synthetic fixture password'\nDB_NAME=synthetic_fixture_database\nAPP_ENCRYPTION_KEY='{$key}'\n");
    $environment = getenv();
    foreach (['DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','DB_SSL_CA','APP_ENCRYPTION_KEY','APP_ENV_FILE'] as $name) unset($environment[$name]);
    $probe = $fixture . '/probe.php';
    file_put_contents($probe, '<?php require __DIR__ . "/includes/encryption.php"; if (site_encryption_key() !== str_repeat("K", 32)) exit(1); echo "key-loaded";');
    foreach ([$probe => [0, 'key-loaded'], $fixture . '/bin/secure-storage.php' => [1, 'Database initialization failed:']] as $script => [$expectedStatus, $expectedOutput]) {
        $args = [PHP_BINARY, $script];
        if (str_ends_with($script, 'secure-storage.php')) $args[] = 'migrate-secrets';
        $process = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $temporaryRoot, $environment);
        environment_assert(is_resource($process), 'Could not start isolated CLI check');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        environment_assert($status === $expectedStatus && str_contains($stdout . $stderr, $expectedOutput), 'CLI startup must load .env before encryption checks and report DB errors with a nonzero exit');
        environment_assert(!str_contains($stdout . $stderr, 'synthetic fixture password') && !str_contains($stdout . $stderr, $key), 'CLI failure must not print secrets');
    }
    echo "PASS: CLI loads the key independently of working directory and reports DB failure clearly\n";
} finally {
    foreach (['EDTECH_ENV_TEST_PASSWORD','EDTECH_ENV_TEST_EMPTY','EDTECH_ENV_TEST_URL','EDTECH_ENV_TEST_OVERRIDE'] as $name) {
        putenv($name); unset($_ENV[$name], $_SERVER[$name]);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($fixture);
}
echo "All environment checks passed.\n";
