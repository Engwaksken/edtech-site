<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['venture_login']) ||
    empty($_SESSION['venture_id'])
) {
    header('Location: login.php');
    exit;
}

$ventureId = (int) $_SESSION['venture_id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Composer
|--------------------------------------------------------------------------
|
| No LibreOffice is required.
|
| Required packages:
|   composer require phpoffice/phpword
|   composer require phpoffice/phpspreadsheet
|   composer require dompdf/dompdf
|
*/
$autoload = __DIR__ . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function rf_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}


function rf_safe_filename(string $filename): string
{
    $filename = basename($filename);

    $filename = str_replace(
        ["\r", "\n", '"'],
        ['', '', "'"],
        $filename
    );

    return $filename !== ''
        ? $filename
        : 'resource';
}


function rf_download(
    string $file,
    string $filename,
    string $mime
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header(
        'Content-Disposition: attachment; filename="' .
        rf_safe_filename($filename) .
        '"'
    );
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: public');

    readfile($file);
    exit;
}


function rf_inline_file(
    string $file,
    string $filename,
    string $mime
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header(
        'Content-Disposition: inline; filename="' .
        rf_safe_filename($filename) .
        '"'
    );
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=0');

    readfile($file);
    exit;
}


function rf_html_header(string $title): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');

    $safeTitle = htmlspecialchars(
        $title,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $favicon = htmlspecialchars(rtrim(SITE_URL, '/') . '/assets/images/favicon.png', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="{$favicon}">
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>{$safeTitle}</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            color: #1f2937;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .rf-toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            padding: 12px 18px;

            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;

            box-shadow:
                0 2px 8px
                rgba(0, 0, 0, 0.06);
        }

        .rf-title {
            min-width: 0;

            font-size: 15px;
            font-weight: 700;

            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .rf-actions {
            flex-shrink: 0;
        }

        .rf-btn {
            display: inline-block;

            padding: 9px 16px;

            background: #05645b;
            color: #ffffff;

            border-radius: 7px;

            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .rf-btn:hover {
            opacity: 0.9;
        }

        .rf-document {
            width: calc(100% - 30px);
            max-width: 1200px;

            min-height: 400px;

            margin: 20px auto;
            padding: 35px;

            background: #ffffff;

            border-radius: 8px;

            box-shadow:
                0 2px 15px
                rgba(0, 0, 0, 0.07);

            overflow-x: auto;
        }

        .rf-document img {
            max-width: 100%;
            height: auto;
        }

        table {
            border-collapse: collapse;
            max-width: 100%;
        }

        td,
        th {
            padding: 7px 10px;
            border: 1px solid #d1d5db;
        }

        @media (max-width: 700px) {
            .rf-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .rf-document {
                width: calc(100% - 16px);
                margin: 8px auto;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
HTML;
}


function rf_html_footer(): void
{
    echo '</body></html>';
}


/*
|--------------------------------------------------------------------------
| Request
|--------------------------------------------------------------------------
*/

$id = (int) ($_GET['id'] ?? 0);

$mode = strtolower(
    trim((string) ($_GET['mode'] ?? 'download'))
);

if (!in_array($mode, ['view', 'download'], true)) {
    $mode = 'download';
}

if ($id <= 0) {
    http_response_code(404);
    exit('Invalid resource.');
}

/*
|--------------------------------------------------------------------------
| Resource
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM resources
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load resource.');
}

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();

$resource = $result
    ? $result->fetch_assoc()
    : null;

$stmt->close();

if (!$resource) {
    http_response_code(404);
    exit('Resource not found.');
}

/*
|--------------------------------------------------------------------------
| Permission
|--------------------------------------------------------------------------
*/

$allowed = false;

if (
    strtolower(
        (string) ($resource['access_level'] ?? '')
    ) === 'public'
) {
    $allowed = true;
} else {

    $stmt = $conn->prepare("
        SELECT email
        FROM ventures
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        exit('Unable to verify venture.');
    }

    $stmt->bind_param('i', $ventureId);
    $stmt->execute();

    $ventureResult = $stmt->get_result();

    $ventureRow = $ventureResult
        ? $ventureResult->fetch_assoc()
        : null;

    $stmt->close();

    $ventureEmail = trim(
        (string) ($ventureRow['email'] ?? '')
    );

    $hasRequestVentureId = rf_column_exists(
        $conn,
        'resource_requests',
        'venture_id'
    );

    if ($hasRequestVentureId) {

        $stmt = $conn->prepare("
            SELECT id
            FROM resource_requests
            WHERE resource_id = ?
              AND (
                    venture_id = ?
                    OR requester_email = ?
                  )
              AND status = 'approved'
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iis',
                $id,
                $ventureId,
                $ventureEmail
            );
        }

    } else {

        $stmt = $conn->prepare("
            SELECT id
            FROM resource_requests
            WHERE resource_id = ?
              AND requester_email = ?
              AND status = 'approved'
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'is',
                $id,
                $ventureEmail
            );
        }
    }

    if ($stmt) {
        $stmt->execute();

        $permissionResult = $stmt->get_result();

        $allowed =
            $permissionResult &&
            $permissionResult->num_rows > 0;

        $stmt->close();
    }
}

if (!$allowed) {
    http_response_code(403);

    exit(
        'You do not have permission to access this resource.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve uploaded file
|--------------------------------------------------------------------------
*/

$relative = trim(
    (string) ($resource['file_path'] ?? '')
);

if ($relative === '') {
    http_response_code(404);
    exit('No file attached.');
}

$root = realpath(__DIR__);

if ($root === false) {
    http_response_code(500);
    exit('Unable to resolve application directory.');
}

$file = realpath(
    $root .
    DIRECTORY_SEPARATOR .
    ltrim(
        str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $relative
        ),
        DIRECTORY_SEPARATOR
    )
);

$rootPrefix =
    rtrim($root, DIRECTORY_SEPARATOR) .
    DIRECTORY_SEPARATOR;

if (
    $file === false ||
    !str_starts_with($file, $rootPrefix) ||
    !is_file($file)
) {
    http_response_code(404);

    exit(
        'The uploaded file could not be found on the server.'
    );
}

/*
|--------------------------------------------------------------------------
| File information
|--------------------------------------------------------------------------
*/

$ext = strtolower(
    pathinfo(
        $file,
        PATHINFO_EXTENSION
    )
);

$mimeTypes = [

    'pdf' => 'application/pdf',

    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',

    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'avi'  => 'video/x-msvideo',

    'doc' =>
        'application/msword',

    'docx' =>
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

    'odt' =>
        'application/vnd.oasis.opendocument.text',

    'rtf' =>
        'application/rtf',

    'ppt' =>
        'application/vnd.ms-powerpoint',

    'pptx' =>
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',

    'xls' =>
        'application/vnd.ms-excel',

    'xlsx' =>
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

    'ods' =>
        'application/vnd.oasis.opendocument.spreadsheet',

    'csv' =>
        'text/csv',

    'zip' =>
        'application/zip',

    'txt' =>
        'text/plain; charset=UTF-8',

];

$mime =
    $mimeTypes[$ext] ??
    'application/octet-stream';

$filename = trim(
    (string) ($resource['file_name'] ?? '')
);

if ($filename === '') {
    $filename = basename($file);
}

$filename = rf_safe_filename($filename);

/*
|--------------------------------------------------------------------------
| Download count
|--------------------------------------------------------------------------
|
| Increment only when the user actually downloads the file.
|
*/

if ($mode === 'download') {

    $stmt = $conn->prepare("
        UPDATE resources
        SET download_count =
            COALESCE(download_count, 0) + 1
        WHERE id = ?
    ");

    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    rf_download(
        $file,
        $filename,
        $mime
    );
}

/*
|--------------------------------------------------------------------------
| Browser-native preview
|--------------------------------------------------------------------------
*/

$browserInline = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'mp4',
    'webm',
    'txt',
];

if (in_array($ext, $browserInline, true)) {
    rf_inline_file(
        $file,
        $filename,
        $mime
    );
}

/*
|--------------------------------------------------------------------------
| Word preview
|--------------------------------------------------------------------------
|
| PHPWord replaces LibreOffice for supported document preview.
|
| Best support:
|   DOCX
|   ODT
|   RTF
|
| Legacy DOC files are downloaded because PHPWord does not reliably parse
| binary Microsoft Word .doc files.
|
*/

$wordPreviewExtensions = [
    'docx',
    'odt',
    'rtf',
];

if (
    in_array(
        $ext,
        $wordPreviewExtensions,
        true
    )
) {

    if (
        !class_exists(
            \PhpOffice\PhpWord\IOFactory::class
        )
    ) {
        http_response_code(500);

        exit(
            'PHPWord is not installed on this server. ' .
            'Run composer require phpoffice/phpword.'
        );
    }

    try {

        $phpWord =
            \PhpOffice\PhpWord\IOFactory::load(
                $file
            );

        $writer =
            \PhpOffice\PhpWord\IOFactory::createWriter(
                $phpWord,
                'HTML'
            );

        $downloadUrl =
            'resource-file.php?id=' .
            urlencode((string) $id) .
            '&mode=download';

        rf_html_header($filename);

        echo '<div class="rf-toolbar">';

        echo '<div class="rf-title">' .
            htmlspecialchars(
                $filename,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</div>';

        echo '<div class="rf-actions">';

        echo '<a class="rf-btn" href="' .
            htmlspecialchars(
                $downloadUrl,
                ENT_QUOTES,
                'UTF-8'
            ) .
            '">Download</a>';

        echo '</div>';

        echo '</div>';

        echo '<div class="rf-document">';

        /*
         * PHPWord HTML writer sends the document HTML
         * directly to this output stream.
         */
        $writer->save('php://output');

        echo '</div>';

        rf_html_footer();

        exit;

    } catch (Throwable $e) {

        error_log(
            'Resource Word preview error: ' .
            $e->getMessage()
        );

        /*
         * Do not expose technical information to users.
         */
        http_response_code(200);

        rf_html_header('Document preview unavailable');

        echo '<div class="rf-document">';

        echo '<h3>Preview unavailable</h3>';

        echo '<p>';
        echo 'This document cannot be previewed directly. ';
        echo 'You can download it instead.';
        echo '</p>';

        echo '<p>';

        echo '<a class="rf-btn" href="resource-file.php?id=' .
            urlencode((string) $id) .
            '&amp;mode=download">';

        echo 'Download document';

        echo '</a>';

        echo '</p>';

        echo '</div>';

        rf_html_footer();

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Spreadsheet preview
|--------------------------------------------------------------------------
|
| PhpSpreadsheet replaces LibreOffice for Excel preview.
|
*/

$spreadsheetExtensions = [
    'xls',
    'xlsx',
    'ods',
    'csv',
];

if (
    in_array(
        $ext,
        $spreadsheetExtensions,
        true
    )
) {

    if (
        !class_exists(
            \PhpOffice\PhpSpreadsheet\IOFactory::class
        )
    ) {
        http_response_code(500);

        exit(
            'PhpSpreadsheet is not installed on this server. ' .
            'Run composer require phpoffice/phpspreadsheet.'
        );
    }

    try {

        /*
         * Avoid calculating unnecessary formatting/data
         * while loading large spreadsheets where possible.
         */
        $spreadsheet =
            \PhpOffice\PhpSpreadsheet\IOFactory::load(
                $file
            );

        $writer =
            new \PhpOffice\PhpSpreadsheet\Writer\Html(
                $spreadsheet
            );

        /*
         * Show all sheets in the generated HTML.
         */
        $writer->writeAllSheets();

        /*
         * Prevent huge embedded charts from causing unnecessary
         * memory use unless explicitly needed.
         */
        if (
            method_exists(
                $writer,
                'setIncludeCharts'
            )
        ) {
            $writer->setIncludeCharts(false);
        }

        $downloadUrl =
            'resource-file.php?id=' .
            urlencode((string) $id) .
            '&mode=download';

        rf_html_header($filename);

        echo '<div class="rf-toolbar">';

        echo '<div class="rf-title">' .
            htmlspecialchars(
                $filename,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) .
            '</div>';

        echo '<div class="rf-actions">';

        echo '<a class="rf-btn" href="' .
            htmlspecialchars(
                $downloadUrl,
                ENT_QUOTES,
                'UTF-8'
            ) .
            '">Download</a>';

        echo '</div>';

        echo '</div>';

        echo '<div class="rf-document">';

        $writer->save('php://output');

        echo '</div>';

        rf_html_footer();

        /*
         * Free memory for large spreadsheets.
         */
        $spreadsheet->disconnectWorksheets();

        unset(
            $writer,
            $spreadsheet
        );

        exit;

    } catch (Throwable $e) {

        error_log(
            'Resource spreadsheet preview error: ' .
            $e->getMessage()
        );

        http_response_code(200);

        rf_html_header(
            'Spreadsheet preview unavailable'
        );

        echo '<div class="rf-document">';

        echo '<h3>Preview unavailable</h3>';

        echo '<p>';
        echo 'This spreadsheet cannot be previewed directly. ';
        echo 'You can download it instead.';
        echo '</p>';

        echo '<p>';

        echo '<a class="rf-btn" href="resource-file.php?id=' .
            urlencode((string) $id) .
            '&amp;mode=download">';

        echo 'Download spreadsheet';

        echo '</a>';

        echo '</p>';

        echo '</div>';

        rf_html_footer();

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Unsupported previews
|--------------------------------------------------------------------------
|
| PowerPoint is intentionally not passed to LibreOffice.
|
| PHPWord and PhpSpreadsheet do not provide PowerPoint rendering.
| Users can download PPT/PPTX files instead.
|
*/

rf_html_header('Preview unavailable');

$downloadUrl =
    'resource-file.php?id=' .
    urlencode((string) $id) .
    '&mode=download';

$safeFilename = htmlspecialchars(
    $filename,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

echo <<<HTML

<div class="rf-toolbar">

    <div class="rf-title">
        {$safeFilename}
    </div>

</div>

<div class="rf-document">

    <h3>Preview not available</h3>

    <p>
        This file type cannot be previewed directly
        on the server.
    </p>

    <p>
        You can safely download the original file.
    </p>

    <p>
        <a
            class="rf-btn"
            href="{$downloadUrl}"
        >
            Download File
        </a>
    </p>

</div>

HTML;

rf_html_footer();

exit;
