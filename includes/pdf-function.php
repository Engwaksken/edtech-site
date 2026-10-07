<?php


declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

function pdf_render_html(string $html, array $options = []): string
{
    if (!class_exists(Dompdf::class)) {
        throw new RuntimeException(
            'Dompdf is not available. Run "composer require dompdf/dompdf" and ' .
            'ensure vendor/autoload.php is being loaded (see pp_require_pdf()).'
        );
    }

    $dompdfOptions = new Options();
    $dompdfOptions->set('isRemoteEnabled', true);   
    $dompdfOptions->set('isHtml5ParserEnabled', true);
    $dompdfOptions->set('defaultFont', 'DejaVu Sans');

    if (!empty($options['base_path'])) {
        $dompdfOptions->set('chroot', $options['base_path']);
    }

    $dompdf = new Dompdf($dompdfOptions);
    $dompdf->loadHtml($html, 'UTF-8');

    $paper       = $options['paper'] ?? 'a4';
    $orientation = $options['orientation'] ?? 'portrait';
    $dompdf->setPaper($paper, $orientation);

    $dompdf->render();

    return $dompdf->output();
}


function pdf_stream_to_browser(string $html, string $filename, array $options = []): void
{
    $pdfBytes = pdf_render_html($html, $options);

    $filename = preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $filename);
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        $filename .= '.pdf';
    }

    $disposition = !empty($options['inline']) ? 'inline' : 'attachment';

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: public');

    echo $pdfBytes;
    exit;
}


function pdf_save_to_disk(string $html, string $absolutePath, array $options = []): bool
{
    $pdfBytes = pdf_render_html($html, $options);

    $dir = dirname($absolutePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return file_put_contents($absolutePath, $pdfBytes) !== false;
}


function pdf_wrap_html(string $title, string $bodyHtml): string
{
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
    @page { margin: 28px 36px; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 12px;
        color: #1f2430;
        line-height: 1.5;
    }
    h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
    h2 { font-size: 15px; margin: 18px 0 8px; color: #111827; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
    .meta { color: #6b7280; font-size: 11px; margin-bottom: 18px; }
    .label { font-weight: 700; color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table th, table td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; font-size: 11px; }
    table th { background: #f9fafb; font-weight: 700; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 700; }
    .badge-success { background: #d1fae5; color: #065f46; }
    .badge-info    { background: #dbeafe; color: #1e40af; }
    .footer { margin-top: 30px; font-size: 10px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 8px; }
</style>
</head>
<body>
{$bodyHtml}
</body>
</html>
HTML;
}