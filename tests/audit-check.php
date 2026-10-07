<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
$failed = 0;
foreach ($iterator as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('#^(vendor|uploads)/#', $relative) || $file->getExtension() !== 'php') {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
    $count++;
    if ($status !== 0) {
        echo "FAIL: {$relative}\n" . implode("\n", $output) . "\n";
        $failed++;
    }
    $output = [];
}
echo "PHP lint: {$count} application files checked; {$failed} failures.\n";

require_once $root . '/vendor/autoload.php';
try {
    $parser = new Sabberworm\CSS\Parser(
        file_get_contents($root . '/assets/css/refinements.css'),
        Sabberworm\CSS\Settings::create()->withLenientParsing(false)
    );
    $parser->parse();
    echo "Shared refinements CSS: strict parsing passed.\n";

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $pdf = new Dompdf\Dompdf($options);
    $pdf->loadHtml('<!doctype html><html><body><h1>Fellowship report</h1><p>PDF upgrade smoke check.</p></body></html>');
    $pdf->render();
    if (!str_starts_with($pdf->output(), '%PDF-')) {
        throw new RuntimeException('PDF output header is missing.');
    }
    echo "Patched PDF library: rendering smoke check passed.\n";
} catch (Throwable $exception) {
    echo 'FAIL: asset/dependency smoke check: ' . $exception->getMessage() . "\n";
    $failed++;
}

foreach (['assets/images/favicon.png', 'assets/images/masteercard_foundation.png', 'assets/images/edtech-hero.jpg', 'assets/css/refinements.css', 'includes/security.php', 'uploads/.htaccess'] as $asset) {
    if (!is_file($root . '/' . $asset)) {
        echo "FAIL: missing shared asset {$asset}\n";
        $failed++;
    }
}

foreach (['login.php', 'admin/login.php', 'change-password.php', 'index.php'] as $form) {
    if (!str_contains(file_get_contents($root . '/' . $form), 'name="site_csrf_token"')) {
        echo "FAIL: missing CSRF field in {$form}\n";
        $failed++;
    }
}

// Report standalone HTML pages that still depend on the default /favicon.ico.
$fallbacks = [];
foreach (array_merge(glob($root . '/*.php'), glob($root . '/admin/*.php')) as $page) {
    $source = file_get_contents($page);
    if (preg_match('/<head[\s>]/i', $source)
        && !str_contains($source, 'favicon')
        && !str_contains($source, 'includes/header.php')) {
        $fallbacks[] = str_replace('\\', '/', substr($page, strlen($root) + 1));
    }
}
if ($fallbacks) {
    echo 'Default favicon fallback pages: ' . implode(', ', $fallbacks) . "\n";
}
echo $failed === 0 ? "Audit checks passed.\n" : "Audit checks failed.\n";
exit($failed === 0 ? 0 : 1);
