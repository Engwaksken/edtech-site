<?php
declare(strict_types=1);
// Build optimized copies of shared assets. Original images are retained.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('imagewebp')) { fwrite(STDERR, "GD WebP support is required.\n"); exit(1); }
$directory = dirname(__DIR__) . '/assets/images';
$totalBefore = 0; $totalAfter = 0;
foreach (['logo.png', 'logo_white.png', 'masteercard_foundation.png', 'foundation_white.png', 'edtech-hero.jpg'] as $name) {
    $source = $directory . '/' . $name;
    $image = imagecreatefromstring(file_get_contents($source));
    if (!$image) { fwrite(STDERR, "Could not decode {$name}.\n"); exit(1); }
    $width = imagesx($image); $height = imagesy($image);
    $limit = str_contains($name, 'hero') ? 1600 : 640;
    $scale = min(1, $limit / $width);
    $resized = imagecreatetruecolor((int)round($width * $scale), (int)round($height * $scale));
    imagealphablending($resized, false); imagesavealpha($resized, true);
    imagefilledrectangle($resized, 0, 0, imagesx($resized), imagesy($resized), imagecolorallocatealpha($resized, 0, 0, 0, 127));
    imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
    $destination = $directory . '/' . pathinfo($name, PATHINFO_FILENAME) . '.webp';
    if (!imagewebp($resized, $destination, str_contains($name, 'hero') ? 82 : 92)) {
        fwrite(STDERR, "Could not build {$name}.\n"); exit(1);
    }
    $before = filesize($source); $after = filesize($destination);
    $totalBefore += $before; $totalAfter += $after;
    echo $name . ': ' . round($before / 1024) . 'KB -> ' . round($after / 1024) . "KB\n";
    imagedestroy($resized); imagedestroy($image);
}
echo 'Shared image payload reduction: ' . round((1 - $totalAfter / $totalBefore) * 100) . "%\n";
