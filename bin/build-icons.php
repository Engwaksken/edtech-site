<?php
declare(strict_types=1);
// Build the local icon assets from @fortawesome/fontawesome-free@6.7.2.
// Usage: php bin/build-icons.php /path/to/node_modules/@fortawesome/fontawesome-free
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = $argv[1] ?? '';
$package = is_file($source . '/package.json') ? json_decode(file_get_contents($source . '/package.json'), true) : null;
if (($package['name'] ?? '') !== '@fortawesome/fontawesome-free' || ($package['version'] ?? '') !== '6.7.2') {
    fwrite(STDERR, "Supply the installed Font Awesome Free 6.7.2 package directory.\n");
    exit(1);
}
$destination = dirname(__DIR__) . '/assets/vendor/fontawesome';
foreach (['css', 'webfonts'] as $directory) {
    if (!is_dir($destination . '/' . $directory)) mkdir($destination . '/' . $directory, 0755, true);
}
$normalization = <<<'CSS'

/* Keep shared button/heading typography from replacing icon-font glyphs. */
body :is(i,span)[class*="fa"]:is(.fa,.fas,.fa-solid,.far,.fa-regular,.fab,.fa-brands){font-family:"Font Awesome 6 Free";font-style:normal;font-variant:normal;text-rendering:auto}
body :is(i,span)[class*="fa"]:is(.fa,.fas,.fa-solid){font-weight:900}
body :is(i,span)[class*="fa"]:is(.far,.fa-regular){font-weight:400}
body :is(i,span)[class*="fa"]:is(.fab,.fa-brands){font-family:"Font Awesome 6 Brands";font-weight:400}
CSS;
file_put_contents($destination . '/css/all.min.css', file_get_contents($source . '/css/all.min.css') . $normalization . "\n");
foreach (['fa-solid-900.woff2', 'fa-regular-400.woff2', 'fa-brands-400.woff2', 'fa-v4compatibility.woff2'] as $font) {
    if (!copy($source . '/webfonts/' . $font, $destination . '/webfonts/' . $font)) exit(1);
}
copy($source . '/LICENSE.txt', $destination . '/LICENSE.txt');
echo "Built local Font Awesome CSS, webfonts, and license.\n";
