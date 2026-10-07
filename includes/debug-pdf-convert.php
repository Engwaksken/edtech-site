<?php
// TEMPORARY DIAGNOSTIC — delete this file once the PDF preview/download
// issue is resolved. Visit it directly in the browser; it doesn't touch
// the database or serve real documents, it just reports on the server
// environment PHP is actually running under.

declare(strict_types=1);

header('Content-Type: text/plain');

echo "=== PHP execution environment ===\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "Running as user: " . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'posix_* not available') . "\n";
echo "PATH env var (as PHP sees it): " . (getenv('PATH') ?: '(empty/not set)') . "\n";
echo "\n";

echo "=== disable_functions check ===\n";
$disabled = ini_get('disable_functions');
echo "disable_functions: " . ($disabled === '' ? '(none disabled)' : $disabled) . "\n";
echo "exec() callable: " . (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', $disabled)), true) ? 'YES' : 'NO — this alone would explain the fallback') . "\n";
echo "shell_exec() callable: " . (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', $disabled)), true) ? 'YES' : 'NO — this alone would explain the fallback') . "\n";
echo "\n";

echo "=== Locating soffice (as PHP sees it, not your SSH shell) ===\n";
$whichOutput = @shell_exec('which soffice 2>&1');
echo "`which soffice` output: " . var_export($whichOutput, true) . "\n";

$commonPaths = [
    '/usr/bin/soffice',
    '/usr/bin/libreoffice',
    '/opt/libreoffice*/program/soffice',
    '/snap/bin/libreoffice',
];
foreach ($commonPaths as $p) {
    foreach (glob($p) ?: [] as $match) {
        echo "Found via glob: $match (executable: " . (is_executable($match) ? 'yes' : 'NO') . ")\n";
    }
}
echo "\n";

echo "=== Temp directory ===\n";
$tmp = sys_get_temp_dir();
echo "sys_get_temp_dir(): $tmp\n";
echo "writable: " . (is_writable($tmp) ? 'YES' : 'NO — this would also break conversion') . "\n";
echo "\n";

echo "=== Attempting an actual test conversion ===\n";
$sofficeBin = trim((string)@shell_exec('which soffice 2>/dev/null'));

if ($sofficeBin === '') {
    echo "soffice not found via PHP's shell_exec — this is why it's falling back to docx.\n";
    echo "Even if `which soffice` works in your SSH terminal, PHP-FPM/Apache may run with a different (more restrictive) PATH.\n";
} else {
    echo "Found soffice at: $sofficeBin\n";

    // Build a tiny throwaway docx-like test isn't practical here without
    // a real file, so instead just test that soffice itself runs at all.
    $versionCmd = escapeshellarg($sofficeBin) . ' --version 2>&1';
    exec($versionCmd, $versionOutput, $versionExit);
    echo "`soffice --version` exit code: $versionExit\n";
    echo "Output: " . implode("\n", $versionOutput) . "\n";

    if ($versionExit !== 0) {
        echo "\nsoffice was found but failed to even report its version — likely a permissions or profile-directory issue for whatever user PHP runs as.\n";
    } else {
        echo "\nsoffice runs fine standalone. If real conversions still fail, the issue is likely specific to the generated .docx file or a profile-lock conflict under load.\n";
    }
}