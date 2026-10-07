<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    nlp_response(false, 'Database connection was not found.', 500);
}

$conn->set_charset('utf8mb4');

/* ============================================================
   RESPONSE / ROUTING
============================================================ */

function nlp_is_ajax(): bool
{
    return (
        strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || (string)($_POST['ajax'] ?? '') === '1'
        || (string)($_GET['ajax'] ?? '') === '1'
    );
}

function nlp_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function nlp_flash_message(string $message, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash('newsletters', $message, $type);
        return;
    }

    $_SESSION['flash_newsletters'] = [
        'message' => $message,
        'type' => $type,
    ];
}

/*
 * A newsletter's HTML is read by email clients (Gmail, Outlook, Apple
 * Mail...) which have no concept of "the current page" - every image
 * URL inside a sent email MUST be a full, absolute, publicly
 * resolvable URL (https://...). A relative URL like
 * "../uploads/x.jpg" or "uploads/x.jpg" will render fine inside the
 * admin builder/preview (because the browser resolves it against the
 * admin page it's already on) but will simply fail to load once the
 * same HTML is delivered as an email - this was the cause of images
 * "disappearing" only in the sent newsletter, not the preview.
 *
 * This always returns a full "scheme://host" base, falling back to
 * the current request's host when SITE_URL isn't configured, so
 * uploaded image URLs and the final email body are never accidentally
 * built as relative paths.
 */
function nlp_site_base_url(): string
{
    if (
        defined('SITE_URL')
        && trim((string)SITE_URL) !== ''
    ) {
        return rtrim((string)SITE_URL, '/');
    }

    $isHttps =
        (
            !empty($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        )
        || (
            strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        );

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME']
            ?? ''
        );

    if ($host === '') {
        return '';
    }

    return
        ($isHttps ? 'https' : 'http')
        . '://'
        . $host;
}

function nlp_admin_newsletters_url(
    string $tab = 'newsletters'
): string {
    $tab =
        in_array(
            $tab,
            [
                'newsletters',
                'hero',
            ],
            true
        )
            ? $tab
            : 'newsletters';

    $siteUrl =
        defined('SITE_URL')
            ? rtrim(
                (string)SITE_URL,
                '/'
            )
            : '';

    /*
     * Use an absolute admin URL whenever SITE_URL is configured.
     * This avoids ../newsletters being resolved by the browser as
     * /newsletters when the current request is /admin/newsletters.
     */
    if ($siteUrl !== '') {
        return
            $siteUrl
            . '/admin/newsletters?tab='
            . rawurlencode($tab);
    }

    /*
     * Safe fallback for installations without SITE_URL.
     * An absolute path from the web root avoids relative URL ambiguity.
     */
    return
        '/admin/newsletters?tab='
        . rawurlencode($tab);
}


function nlp_redirect(
    string $message = '',
    string $type = 'success',
    string $tab = 'newsletters',
    array $extra = []
): never {
    if (nlp_is_ajax()) {
        nlp_json(
            array_merge(
                [
                    'ok' => $type !== 'error',
                    'message' => $message,
                    'type' => $type,
                    'tab' => $tab,
                    'redirect' =>
                        nlp_admin_newsletters_url(
                            $tab
                        ),
                ],
                $extra
            ),
            $type === 'error'
                ? 422
                : 200
        );
    }

    if ($message !== '') {
        nlp_flash_message(
            $message,
            $type
        );
    }

    while (
        ob_get_level() > 0
    ) {
        @ob_end_clean();
    }

    header(
        'Location: '
        . nlp_admin_newsletters_url(
            $tab
        )
    );

    exit;
}


function nlp_response(
    bool $ok,
    string $message,
    int $status = 200,
    array $extra = []
): never {
    if (nlp_is_ajax()) {
        nlp_json(
            array_merge(
                [
                    'ok' => $ok,
                    'message' => $message,
                ],
                $extra
            ),
            $status
        );
    }

    nlp_redirect(
        $message,
        $ok ? 'success' : 'error',
        'newsletters',
        $extra
    );
}

set_exception_handler(
    function (Throwable $e): void {
        error_log(
            'Newsletter processor error: '
            . $e->getMessage()
        );

        if (nlp_is_ajax()) {
            nlp_json(
                [
                    'ok' => false,
                    'message' => 'Newsletter processing failed: '
                        . $e->getMessage(),
                ],
                500
            );
        }

        nlp_redirect(
            'Newsletter processing failed. Please try again.',
            'error'
        );
    }
);

/* ============================================================
   DATABASE HELPERS
============================================================ */

function nlp_safe_identifier(string $name): string
{
    return preg_replace(
        '/[^a-zA-Z0-9_]/',
        '',
        $name
    ) ?? '';
}

function nlp_table_exists(
    mysqli $conn,
    string $table
): bool {
    $table =
        nlp_safe_identifier(
            $table
        );

    if ($table === '') {
        return false;
    }

    try {
        /*
         * Avoid INFORMATION_SCHEMA and prepared SHOW TABLES.
         * This works on restricted cPanel MySQL users as long as the
         * application can SELECT from the requested table.
         */
        $result =
            $conn->query(
                "SELECT 1 FROM `{$table}` LIMIT 1"
            );

        return
            $result instanceof mysqli_result;

    } catch (Throwable $e) {
        error_log(
            'Newsletter table check failed for '
            . $table
            . ': '
            . $e->getMessage()
        );

        return false;
    }
}


function nlp_has_column(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $table =
        nlp_safe_identifier(
            $table
        );

    if ($table === '') {
        return false;
    }

    try {
        $result =
            $conn->query(
                "SHOW COLUMNS FROM `{$table}`"
            );

        if (
            !($result instanceof mysqli_result)
        ) {
            return false;
        }

        while (
            $row =
                $result->fetch_assoc()
        ) {
            if (
                (string)(
                    $row['Field']
                    ?? ''
                )
                === $column
            ) {
                return true;
            }
        }

        return false;

    } catch (Throwable $e) {
        error_log(
            'Newsletter column check failed for '
            . $table
            . '.'
            . $column
            . ': '
            . $e->getMessage()
        );

        return false;
    }
}


function nlp_add_column(
    mysqli $conn,
    string $column,
    string $definition
): void {
    if (!nlp_has_column($conn, 'newsletters', $column)) {
        if (!$conn->query(
            "ALTER TABLE newsletters ADD COLUMN `$column` $definition"
        )) {
            throw new RuntimeException(
                'Could not add newsletter column '
                . $column
                . ': '
                . $conn->error
            );
        }
    }
}

function nlp_ensure_schema(mysqli $conn): void
{
    if (!nlp_table_exists($conn, 'newsletters')) {
        $sql = "
            CREATE TABLE newsletters (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subject VARCHAR(255) NOT NULL DEFAULT '',
                preheader VARCHAR(500) NULL,
                from_name VARCHAR(190) NULL,
                from_email VARCHAR(190) NULL,
                body_json LONGTEXT NULL,
                body_html LONGTEXT NULL,
                body_message TEXT NULL,
                pdf_path VARCHAR(255) NULL,
                pdf_file VARCHAR(255) NULL,
                pdf_name VARCHAR(255) NULL,
                recipient_type VARCHAR(50) NOT NULL DEFAULT 'manual',
                recipient_emails LONGTEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'draft',
                sent_at DATETIME NULL,
                total_sent INT UNSIGNED NOT NULL DEFAULT 0,
                duplicated_from INT UNSIGNED NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'Could not create newsletters table: '
                . $conn->error
            );
        }
    }

    $columns = [
        'body_message' => 'TEXT NULL',
        'pdf_path' => 'VARCHAR(255) NULL',
        'pdf_file' => 'VARCHAR(255) NULL',
        'pdf_name' => 'VARCHAR(255) NULL',
        'duplicated_from' => 'INT UNSIGNED NULL',
        'total_sent' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'sent_at' => 'DATETIME NULL',
        'created_at' => 'DATETIME NULL',
        'updated_at' => 'DATETIME NULL',
    ];

    foreach ($columns as $name => $definition) {
        nlp_add_column($conn, $name, $definition);
    }
}

function nlp_get_newsletter(
    mysqli $conn,
    int $id
): ?array {
    $stmt = $conn->prepare("
        SELECT *
        FROM newsletters
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function nlp_setting(
    mysqli $conn,
    string $key,
    string $default = ''
): string {
    if (function_exists('get_setting')) {
        return (string)get_setting(
            $conn,
            $key,
            $default
        );
    }

    if (!nlp_table_exists($conn, 'site_settings')) {
        return $default;
    }

    $stmt = $conn->prepare("
        SELECT setting_value
        FROM site_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string)($row['setting_value'] ?? $default);
}

function nlp_save_setting(
    mysqli $conn,
    string $key,
    string $value
): bool {
    if (!nlp_table_exists($conn, 'site_settings')) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM site_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $stmt = $conn->prepare("
            UPDATE site_settings
            SET setting_value = ?
            WHERE setting_key = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $value,
            $key
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO site_settings (
                setting_key,
                setting_value
            )
            VALUES (?, ?)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $key,
            $value
        );
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* ============================================================
   UPLOAD HELPERS
============================================================ */

function nlp_image_extension(
    string $mime,
    string $fallback
): string {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => $fallback,
    };
}

/*
 * Newsletter images are resized AND recompressed against a target byte
 * budget so a single newsletter (which can contain many images) stays
 * fast to load and cheap to store.
 *
 * Behaviour:
 *  - Never upscale. Images are only ever shrunk down to fit within
 *    $maxWidth x $maxHeight.
 *  - For photographic formats (JPEG/WEBP) the encoder quality is
 *    stepped down automatically until the saved file is at or under
 *    $maxBytes, or the minimum quality floor is reached.
 *  - If quality reduction alone isn't enough, the image is shrunk
 *    further (one extra downscale pass) and re-encoded.
 *  - PNG/GIF are saved with maximum lossless compression (GD does not
 *    support a byte-budget for these formats without changing the
 *    file type, so they are left as lossless but still resized).
 */
function nlp_compress_image(
    string $source,
    string $target,
    string $extension,
    int $maxWidth = 1600,
    int $maxHeight = 1600,
    int $jpegQuality = 78,
    int $webpQuality = 74,
    int $maxBytes = 400 * 1024
): bool {
    if (
        !extension_loaded('gd')
        || !function_exists('getimagesize')
    ) {
        return false;
    }

    $info = @getimagesize($source);

    if (!$info) {
        return false;
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    $mime = (string)($info['mime'] ?? '');

    if (
        $width <= 0
        || $height <= 0
    ) {
        return false;
    }

    $sourceImage = match ($mime) {
        'image/jpeg' =>
            function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg($source)
                : false,

        'image/png' =>
            function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($source)
                : false,

        'image/gif' =>
            function_exists('imagecreatefromgif')
                ? @imagecreatefromgif($source)
                : false,

        'image/webp' =>
            function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($source)
                : false,

        default => false,
    };

    if (!$sourceImage) {
        return false;
    }

    $renderPass = static function (
        int $targetMaxWidth,
        int $targetMaxHeight
    ) use (
        $sourceImage,
        $width,
        $height,
        $mime
    ): ?array {
        $scale = min(
            1,
            $targetMaxWidth / $width,
            $targetMaxHeight / $height
        );

        $newWidth =
            max(
                1,
                (int)round(
                    $width * $scale
                )
            );

        $newHeight =
            max(
                1,
                (int)round(
                    $height * $scale
                )
            );

        $canvas =
            imagecreatetruecolor(
                $newWidth,
                $newHeight
            );

        if (!$canvas) {
            return null;
        }

        if (
            $mime === 'image/png'
            || $mime === 'image/gif'
            || $mime === 'image/webp'
        ) {
            imagealphablending(
                $canvas,
                false
            );

            imagesavealpha(
                $canvas,
                true
            );

            $transparent =
                imagecolorallocatealpha(
                    $canvas,
                    0,
                    0,
                    0,
                    127
                );

            imagefilledrectangle(
                $canvas,
                0,
                0,
                $newWidth,
                $newHeight,
                $transparent
            );
        }

        imagecopyresampled(
            $canvas,
            $sourceImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        return [$canvas, $newWidth, $newHeight];
    };

    $pass = $renderPass($maxWidth, $maxHeight);

    if (!$pass) {
        imagedestroy($sourceImage);
        return false;
    }

    [$canvas] = $pass;

    $saveWithQuality = static function (
        $canvas,
        string $target,
        string $mime,
        int $jpegQuality,
        int $webpQuality
    ) {
        return match ($mime) {
            'image/jpeg' =>
                @imagejpeg(
                    $canvas,
                    $target,
                    $jpegQuality
                ),

            'image/png' =>
                @imagepng(
                    $canvas,
                    $target,
                    9
                ),

            'image/gif' =>
                @imagegif(
                    $canvas,
                    $target
                ),

            'image/webp' =>
                function_exists('imagewebp')
                    ? @imagewebp(
                        $canvas,
                        $target,
                        $webpQuality
                    )
                    : false,

            default => false,
        };
    };

    $saved = $saveWithQuality(
        $canvas,
        $target,
        $mime,
        $jpegQuality,
        $webpQuality
    );

    $isLossy =
        $mime === 'image/jpeg'
        || $mime === 'image/webp';

    /*
     * Step 1: for photographic formats, keep lowering encoder quality
     * until the file fits the byte budget or we hit a sensible floor.
     */
    if (
        $saved
        && $isLossy
        && is_file($target)
    ) {
        $attempts = 0;

        while (
            (int)@filesize($target) > $maxBytes
            && $jpegQuality > 35
            && $webpQuality > 35
            && $attempts < 5
        ) {
            $attempts++;
            $jpegQuality = max(35, $jpegQuality - 12);
            $webpQuality = max(35, $webpQuality - 12);

            $saved = $saveWithQuality(
                $canvas,
                $target,
                $mime,
                $jpegQuality,
                $webpQuality
            );
        }
    }

    /*
     * Step 2: quality reduction alone wasn't enough (e.g. a very large,
     * detailed photo). Shrink the canvas once more and re-encode at a
     * moderate quality before giving up on hitting the byte budget.
     */
    if (
        $saved
        && $isLossy
        && is_file($target)
        && (int)@filesize($target) > $maxBytes
    ) {
        imagedestroy($canvas);

        $secondPass = $renderPass(
            (int)round($maxWidth * 0.7),
            (int)round($maxHeight * 0.7)
        );

        if ($secondPass) {
            [$canvas] = $secondPass;

            $saved = $saveWithQuality(
                $canvas,
                $target,
                $mime,
                60,
                58
            );
        }
    }

    imagedestroy($canvas);
    imagedestroy($sourceImage);

    return (bool)$saved;
}

function nlp_upload(
    array $file,
    string $folder,
    array $extensions,
    int $maxBytes,
    bool $compressImages = true,
    int $imageMaxWidth = 1600,
    int $imageMaxHeight = 1600,
    int $imageJpegQuality = 78,
    int $imageWebpQuality = 74,
    int $imageTargetBytes = 400 * 1024
): array {
    if (
        empty($file['name'])
        || ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_OK
    ) {
        return [
            'ok' => false,
            'message' =>
                'No file was uploaded.',
        ];
    }

    $size =
        (int)(
            $file['size']
            ?? 0
        );

    if ($size > $maxBytes) {
        return [
            'ok' => false,
            'message' =>
                'The uploaded file is too large. Maximum allowed size is '
                . round($maxBytes / (1024 * 1024), 1)
                . ' MB.',
        ];
    }

    $extension =
        strtolower(
            pathinfo(
                (string)$file['name'],
                PATHINFO_EXTENSION
            )
        );

    if (
        !in_array(
            $extension,
            $extensions,
            true
        )
    ) {
        return [
            'ok' => false,
            'message' =>
                'Invalid file type.',
        ];
    }

    $uploadRoot =
        defined('UPLOAD_PATH')
            ? rtrim(
                (string)UPLOAD_PATH,
                '/\\'
            )
            : dirname(
                __DIR__,
                2
            )
            . '/uploads';

    $directory =
        $uploadRoot
        . '/'
        . trim(
            $folder,
            '/'
        );

    if (
        !is_dir($directory)
        && !@mkdir(
            $directory,
            0755,
            true
        )
        && !is_dir($directory)
    ) {
        return [
            'ok' => false,
            'message' =>
                'Upload directory could not be created.',
        ];
    }

    if (!is_writable($directory)) {
        return [
            'ok' => false,
            'message' =>
                'Upload directory is not writable.',
        ];
    }

    $tmp =
        (string)(
            $file['tmp_name']
            ?? ''
        );

    if (
        $tmp === ''
        || !is_uploaded_file($tmp)
    ) {
        return [
            'ok' => false,
            'message' =>
                'The uploaded file could not be verified.',
        ];
    }

    $imageExtensions = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    $isImage =
        in_array(
            $extension,
            $imageExtensions,
            true
        );

    /*
     * Reject images whose declared pixel dimensions are absurdly large
     * before we ever attempt to load them into GD. This protects the
     * server from memory exhaustion on decompression-bomb style
     * uploads (e.g. a tiny file that decodes to a 20000x20000 image).
     */
    if (
        $isImage
        && function_exists('getimagesize')
    ) {
        $dimensionCheck = @getimagesize($tmp);

        if ($dimensionCheck) {
            $rawWidth = (int)($dimensionCheck[0] ?? 0);
            $rawHeight = (int)($dimensionCheck[1] ?? 0);

            if (
                $rawWidth > 8000
                || $rawHeight > 8000
            ) {
                return [
                    'ok' => false,
                    'message' =>
                        'Image dimensions are too large. Please use an image no larger than 8000x8000 pixels.',
                ];
            }
        }
    }

    $finalExtension =
        $extension === 'jpeg'
            ? 'jpg'
            : $extension;

    if (
        $isImage
        && function_exists('getimagesize')
    ) {
        $imageInfo =
            @getimagesize(
                $tmp
            );

        if ($imageInfo) {
            $finalExtension =
                nlp_image_extension(
                    (string)(
                        $imageInfo[
                            'mime'
                        ]
                        ?? ''
                    ),
                    $finalExtension
                );
        }
    }

    $filename =
        'newsletter_'
        . date('YmdHis')
        . '_'
        . bin2hex(
            random_bytes(5)
        )
        . '.'
        . $finalExtension;

    $target =
        $directory
        . '/'
        . $filename;

    $saved = false;
    $compressed = false;

    /*
     * Images are resized to fit within $imageMaxWidth x $imageMaxHeight
     * and recompressed toward $imageTargetBytes so newsletters stay
     * lightweight and fast to load/send. If GD is unavailable or
     * compression fails, the original upload is still saved safely
     * (subject to the overall $maxBytes cap enforced above).
     */
    if (
        $isImage
        && $compressImages
    ) {
        $compressed =
            nlp_compress_image(
                $tmp,
                $target,
                $finalExtension,
                $imageMaxWidth,
                $imageMaxHeight,
                $imageJpegQuality,
                $imageWebpQuality,
                $imageTargetBytes
            );

        $saved =
            $compressed;
    }

    if (!$saved) {
        $saved =
            move_uploaded_file(
                $tmp,
                $target
            );
    }

    if (!$saved) {
        return [
            'ok' => false,
            'message' =>
                'The uploaded file could not be saved.',
        ];
    }

    @chmod(
        $target,
        0644
    );

    return [
        'ok' => true,
        'path' =>
            'uploads/'
            . trim(
                $folder,
                '/'
            )
            . '/'
            . $filename,
        'name' =>
            basename(
                (string)$file['name']
            ),
        'compressed' =>
            $compressed,
        'size' =>
            is_file($target)
                ? (int)filesize($target)
                : 0,
    ];
}


/* ============================================================
   SEND
============================================================ */

function nlp_absolute_upload_path(
    string $storedPath
): string {
    $storedPath =
        trim(
            str_replace(
                '\\',
                '/',
                $storedPath
            )
        );

    if ($storedPath === '') {
        return '';
    }

    /*
     * Absolute filesystem path already supplied.
     */
    if (
        str_starts_with(
            $storedPath,
            '/'
        )
        && is_file(
            $storedPath
        )
    ) {
        return $storedPath;
    }

    $root =
        defined('UPLOAD_PATH')
            ? dirname(
                rtrim(
                    (string)UPLOAD_PATH,
                    '/\\'
                )
            )
            : dirname(
                __DIR__,
                2
            );

    $candidate =
        rtrim(
            $root,
            '/\\'
        )
        . '/'
        . ltrim(
            $storedPath,
            '/'
        );

    return
        is_file($candidate)
            ? $candidate
            : '';
}


function nlp_email_body(
    mysqli $conn,
    array $newsletter,
    string $recipientEmail
): string {
    $html =
        trim(
            (string)(
                $newsletter['body_html']
                ?? ''
            )
        );

    $message =
        trim(
            (string)(
                $newsletter['body_message']
                ?? ''
            )
        );

    $pdfPath =
        trim(
            (string)(
                $newsletter['pdf_file']
                ?? $newsletter['pdf_path']
                ?? ''
            )
        );

    /*
     * Designed newsletter.
     */
    if ($html !== '') {
        $body =
            $html;
    } else {
        /*
         * PDF newsletter / simple message.
         */
        $content = '';

        if ($message !== '') {
            $content .=
                nl2br(
                    htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );
        } else {
            $content .=
                '<p>Please find the newsletter attached.</p>';
        }

        if (
            function_exists(
                'email_wrapper'
            )
        ) {
            $body =
                email_wrapper(
                    $content
                );
        } else {
            $body =
                '<!doctype html>'
                . '<html><body>'
                . $content
                . '</body></html>';
        }
    }

    $siteUrl = nlp_site_base_url();

    $unsubscribeUrl =
        $siteUrl
        . '/newsletter?unsubscribe='
        . rawurlencode(
            $recipientEmail
        );

    /*
     * Replace newsletter variables.
     */
    $body =
        str_replace(
            [
                '{email}',
                '{unsubscribe_url}',
            ],
            [
                htmlspecialchars(
                    $recipientEmail,
                    ENT_QUOTES,
                    'UTF-8'
                ),
                htmlspecialchars(
                    $unsubscribeUrl,
                    ENT_QUOTES,
                    'UTF-8'
                ),
            ],
            $body
        );

    /*
     * Convert relative src/href URLs to absolute URLs for email
     * clients. This covers <img src="...">, <a href="...">, etc.
     */
    if ($siteUrl !== '') {
        $body =
            preg_replace_callback(
                '/\b(src|href)=([\'"])(?!https?:\/\/|mailto:|tel:|cid:|data:|#)([^\'"]+)\2/i',
                static function (
                    array $matches
                ) use (
                    $siteUrl
                ): string {
                    $url =
                        ltrim(
                            $matches[3],
                            '/'
                        );

                    return
                        $matches[1]
                        . '='
                        . $matches[2]
                        . $siteUrl
                        . '/'
                        . $url
                        . $matches[2];
                },
                $body
            ) ?? $body;

        /*
         * The builder also sets images as inline CSS background
         * images (block/section/top-bar backgrounds), e.g.:
         *   style="background-image:url('uploads/newsletters/...')"
         *
         * These are NOT <img src="..."> or href="..." attributes, so
         * the fixup above never touched them - meaning a section
         * background image could look correct in the admin preview
         * (relative to the admin page) yet silently fail to load in
         * the actual sent email. This second pass rewrites any
         * relative url(...) reference the same way.
         */
        $body =
            preg_replace_callback(
                '/url\(\s*([\'"]?)(?!https?:\/\/|data:|cid:)([^\'")]+)\1\s*\)/i',
                static function (
                    array $matches
                ) use (
                    $siteUrl
                ): string {
                    $quote = $matches[1];

                    $url =
                        ltrim(
                            $matches[2],
                            '/'
                        );

                    return
                        'url('
                        . $quote
                        . $siteUrl
                        . '/'
                        . $url
                        . $quote
                        . ')';
                },
                $body
            ) ?? $body;
    }

    return $body;
}


function nlp_newsletter_recipients(
    mysqli $conn,
    array $newsletter
): array {
    $type =
        trim(
            (string)(
                $newsletter[
                    'recipient_type'
                ]
                ?? 'manual'
            )
        );

    $emails = [];

    if (
        $type === 'subscribers'
        && nlp_table_exists(
            $conn,
            'newsletter_subscribers'
        )
    ) {
        $result =
            $conn->query("
                SELECT email
                FROM newsletter_subscribers
                WHERE status = 'active'
                  AND email IS NOT NULL
                  AND email <> ''
            ");

        if ($result) {
            while (
                $row =
                    $result->fetch_assoc()
            ) {
                $emails[] =
                    (string)(
                        $row['email']
                        ?? ''
                    );
            }
        }
    } else {
        $decoded =
            json_decode(
                (string)(
                    $newsletter[
                        'recipient_emails'
                    ]
                    ?? '[]'
                ),
                true
            );

        if (
            is_array(
                $decoded
            )
        ) {
            /*
             * Support both:
             * ["a@example.com","b@example.com"]
             *
             * and:
             * [{"email":"a@example.com"}]
             */
            foreach (
                $decoded
                as $item
            ) {
                if (
                    is_array(
                        $item
                    )
                ) {
                    $emails[] =
                        (string)(
                            $item['email']
                            ?? ''
                        );
                } else {
                    $emails[] =
                        (string)$item;
                }
            }
        } else {
            /*
             * Graceful fallback for old comma/newline separated values.
             */
            $raw =
                (string)(
                    $newsletter[
                        'recipient_emails'
                    ]
                    ?? ''
                );

            $emails =
                preg_split(
                    '/[\s,;]+/',
                    $raw,
                    -1,
                    PREG_SPLIT_NO_EMPTY
                )
                ?: [];
        }
    }

    $valid = [];

    foreach (
        $emails
        as $email
    ) {
        $email =
            strtolower(
                trim(
                    (string)$email
                )
            );

        if (
            $email !== ''
            && filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $valid[$email] =
                $email;
        }
    }

    return
        array_values(
            $valid
        );
}


function nlp_send_newsletter(
    mysqli $conn,
    int $id
): array {
    $newsletter =
        nlp_get_newsletter(
            $conn,
            $id
        );

    if (!$newsletter) {
        throw new RuntimeException(
            'Newsletter was not found.'
        );
    }

    if (
        !function_exists(
            'sendEmail'
        )
    ) {
        throw new RuntimeException(
            'SMTP email helper sendEmail() is not available.'
        );
    }

    $emails =
        nlp_newsletter_recipients(
            $conn,
            $newsletter
        );

    if (!$emails) {
        throw new RuntimeException(
            'No valid recipients were selected.'
        );
    }

    $subject =
        trim(
            (string)(
                $newsletter[
                    'subject'
                ]
                ?? ''
            )
        );

    if ($subject === '') {
        $subject =
            'Newsletter';
    }

    $pdfStoredPath =
        trim(
            (string)(
                $newsletter[
                    'pdf_file'
                ]
                ?? $newsletter[
                    'pdf_path'
                ]
                ?? ''
            )
        );

    $attachments = [];

    if ($pdfStoredPath !== '') {
        $absolutePdf =
            nlp_absolute_upload_path(
                $pdfStoredPath
            );

        if ($absolutePdf !== '') {
            $attachments[] =
                $absolutePdf;
        }
    }

    /*
     * Sending to many recipients can take a while even with a reused
     * SMTP connection (see below). Make sure the request itself is
     * not cut short by PHP's default execution time limit while a
     * batch is still in progress.
     */
    @set_time_limit(0);
    @ignore_user_abort(true);

    /*
     * IMPORTANT - SMTP connection reuse:
     *
     * sendEmail() (in mail-function.php) opens a brand new PHPMailer
     * instance - and therefore a brand new SMTP connection (TCP +
     * TLS handshake + AUTH) - on every single call. Calling it once
     * per recipient means paying that full reconnect cost for every
     * single person on the list, which is the main reason sending a
     * newsletter to even a few dozen recipients could take far
     * longer than the actual mail sending itself.
     *
     * createBulkMailer()/sendBulkEmail()/closeBulkMailer() (also in
     * mail-function.php) open ONE SMTP connection via PHPMailer's
     * SMTPKeepAlive and reuse it for every recipient in this loop,
     * closing it only once the whole batch is done.
     *
     * If mail-function.php hasn't been updated yet, this falls back
     * to the original per-recipient sendEmail() behaviour so nothing
     * breaks - it will just be slower until that file is deployed.
     */
    $useBulkMailer =
        function_exists('createBulkMailer')
        && function_exists('sendBulkEmail');

    $bulkMailer = null;

    if ($useBulkMailer) {
        $bulkMailer = createBulkMailer();

        if (!$bulkMailer) {
            throw new RuntimeException(
                'Could not establish an SMTP connection for sending. '
                . 'Please check the active email settings.'
            );
        }
    }

    $sent = 0;
    $failed = 0;
    $failures = [];

    foreach (
        $emails
        as $email
    ) {
        $body =
            nlp_email_body(
                $conn,
                $newsletter,
                $email
            );

        $altBody =
            trim(
                html_entity_decode(
                    strip_tags(
                        str_replace(
                            [
                                '<br>',
                                '<br/>',
                                '<br />',
                                '</p>',
                            ],
                            [
                                "\n",
                                "\n",
                                "\n",
                                "\n\n",
                            ],
                            $body
                        )
                    ),
                    ENT_QUOTES
                    | ENT_HTML5,
                    'UTF-8'
                )
            );

        $result =
            $bulkMailer
                ? sendBulkEmail(
                    $bulkMailer,
                    $email,
                    $subject,
                    $body,
                    $altBody,
                    [],
                    [],
                    $attachments
                )
                : sendEmail(
                    $email,
                    $subject,
                    $body,
                    $altBody,
                    [],
                    [],
                    $attachments
                );

        if ($result === true) {
            $sent++;
        } else {
            $failed++;

            $error =
                is_string(
                    $result
                )
                    ? $result
                    : 'Unknown SMTP error.';

            $failures[] = [
                'email' =>
                    $email,
                'error' =>
                    $error,
            ];

            error_log(
                'Newsletter delivery failed to '
                . $email
                . ': '
                . $error
            );
        }
    }

    if ($bulkMailer && function_exists('closeBulkMailer')) {
        closeBulkMailer($bulkMailer);
    }

    $now =
        date(
            'Y-m-d H:i:s'
        );

    /*
     * Never mark a newsletter as sent if SMTP delivered zero messages.
     */
    if ($sent > 0) {
        $status =
            $failed > 0
                ? 'sent'
                : 'sent';

        $stmt =
            $conn->prepare("
                UPDATE newsletters
                SET status = ?,
                    sent_at = ?,
                    total_sent = ?,
                    updated_at = ?
                WHERE id = ?
                LIMIT 1
            ");

        if ($stmt) {
            $stmt->bind_param(
                'ssisi',
                $status,
                $now,
                $sent,
                $now,
                $id
            );

            $stmt->execute();
            $stmt->close();
        }
    }

    return [
        'total' =>
            count(
                $emails
            ),
        'sent' =>
            $sent,
        'failed' =>
            $failed,
        'failures' =>
            $failures,
    ];
}


/* ============================================================
   START
============================================================ */

if (!nlp_table_exists($conn, 'newsletters')) {
    nlp_response(
        false,
        'The newsletters table could not be accessed. Please check database permissions.',
        500
    );
}

if (!nlp_has_column($conn, 'newsletters', 'duplicated_from')) {
    /*
     * Duplication metadata is optional. Saving a normal draft does not
     * require this column.
     */
}

$method = strtoupper(
    (string)(
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
);

/* ============================================================
   LAZY NEWSLETTER DATA
============================================================ */

if (
    $method === 'GET'
    && (string)(
        $_GET['newsletter_action']
        ?? $_GET['action']
        ?? ''
    ) === 'get_newsletter'
) {
    $id =
        max(
            0,
            (int)(
                $_GET['id']
                ?? 0
            )
        );

    if ($id <= 0) {
        nlp_json(
            [
                'ok' => false,
                'message' =>
                    'Invalid newsletter selected.',
            ],
            422
        );
    }

    $newsletter =
        nlp_get_newsletter(
            $conn,
            $id
        );

    if (!$newsletter) {
        nlp_json(
            [
                'ok' => false,
                'message' =>
                    'Newsletter was not found.',
            ],
            404
        );
    }

    /*
     * Full LONGTEXT content is returned only for the one newsletter
     * explicitly requested by the user.
     */
    nlp_json(
        [
            'ok' => true,
            'newsletter' =>
                $newsletter,
        ]
    );
}


/* ============================================================
   PREVIEW
============================================================ */

if (
    $method === 'GET'
    && (string)($_GET['newsletter_action'] ?? $_GET['action'] ?? '') === 'preview'
) {
    $id = (int)($_GET['id'] ?? 0);

    $newsletter = nlp_get_newsletter(
        $conn,
        $id
    );

    if (!$newsletter) {
        http_response_code(404);
        header(
            'Content-Type: text/html; charset=UTF-8'
        );

        echo '<p>Newsletter not found.</p>';
        exit;
    }

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    echo trim(
        (string)(
            $newsletter['body_html']
            ?? ''
        )
    ) !== ''
        ? (string)$newsletter['body_html']
        : '<p>No newsletter content is available.</p>';

    exit;
}

if ($method !== 'POST') {
    nlp_redirect(
        'Invalid newsletter request.',
        'error'
    );
}

$action = trim(
    (string)(
        $_POST['newsletter_action']
        ?? $_POST['action']
        ?? ''
    )
);

/* ============================================================
   SMTP TEST EMAIL
============================================================ */

if ($action === 'test_email') {
    $email =
        trim(
            (string)(
                $_POST['email']
                ?? ''
            )
        );

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        nlp_response(
            false,
            'Enter a valid test email address.',
            422
        );
    }

    if (
        !function_exists(
            'sendEmail'
        )
    ) {
        nlp_response(
            false,
            'SMTP mail helper is not available.',
            500
        );
    }

    $result =
        sendEmail(
            $email,
            'Newsletter SMTP Test',
            email_wrapper(
                '<h3>SMTP test successful</h3>'
                . '<p>This test email was sent using the active '
                . 'Hive Colab PHPMailer SMTP configuration.</p>'
            )
        );

    if ($result !== true) {
        nlp_response(
            false,
            is_string($result)
                ? $result
                : 'SMTP test failed.',
            500
        );
    }

    nlp_response(
        true,
        'SMTP test email sent successfully to '
        . $email
        . '.'
    );
}


/* ============================================================
   NEWSLETTER IMAGE UPLOAD
============================================================ */

if ($action === 'upload_image') {
    $file =
        $_FILES['image']
        ?? $_FILES['newsletter_image']
        ?? null;

    if (
        !$file
        || !is_array($file)
    ) {
        nlp_response(
            false,
            'Select an image to upload.',
            422
        );
    }

    /*
     * Newsletter body/section images: these can appear many times in
     * a single newsletter, so they are capped hard both in original
     * upload size and in final (post-compression) byte size, and are
     * resized to a web-appropriate maximum of 1400x1400.
     *
     * - Original upload accepted up to 6 MB (covers most camera/phone
     *   photos and screenshots).
     * - Final stored file is compressed down toward ~300 KB so a
     *   newsletter with several images still loads and sends quickly.
     */
    $upload =
        nlp_upload(
            $file,
            'newsletters/images',
            [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'webp',
            ],
            6 * 1024 * 1024,
            true,
            1400,
            1400,
            76,
            72,
            300 * 1024
        );

    if (!$upload['ok']) {
        nlp_response(
            false,
            (string)$upload['message'],
            422
        );
    }

    /*
     * Newsletter images are embedded into emails, which have no
     * "current page" to resolve a relative URL against. Always hand
     * back a full, absolute URL (see nlp_site_base_url()) so the
     * image keeps working once the newsletter is actually sent, not
     * just while previewed inside the admin builder.
     */
    $base = nlp_site_base_url();

    $url =
        $base !== ''
            ? $base
                . '/'
                . ltrim(
                    (string)$upload['path'],
                    '/'
                )
            : ltrim(
                (string)$upload['path'],
                '/'
            );

    nlp_json(
        [
            'ok' => true,
            'path' => $upload['path'],
            'url' => $url,
            'name' => $upload['name'],
            'compressed' =>
                (bool)(
                    $upload[
                        'compressed'
                    ]
                    ?? false
                ),
            'size' =>
                (int)(
                    $upload[
                        'size'
                    ]
                    ?? 0
                ),
        ]
    );
}

/* ============================================================
   SAVE DESIGNED NEWSLETTER
============================================================ */

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);

    $subject = trim((string)($_POST['subject'] ?? ''));
    $preheader = trim((string)($_POST['preheader'] ?? ''));
    $fromName = trim((string)($_POST['from_name'] ?? ''));
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $bodyJson = (string)($_POST['body_json'] ?? '[]');
    $bodyHtml = (string)($_POST['body_html'] ?? '');

    /*
     * Fast, clear failure instead of a silent multi-minute hang or a
     * confusing MySQL "packet too large" error deep in execute().
     *
     * Client-side, the builder now uploads every image (including
     * any legacy base64 images left over from before that fix) and
     * stores only a short URL, so a normal newsletter - even a large
     * one with many images - should be well under this cap. If this
     * trips, something is still embedding raw image/file data
     * directly into the design instead of uploading it.
     */
    $maxNewsletterContentBytes = 8 * 1024 * 1024;

    if (
        (strlen($bodyJson) + strlen($bodyHtml))
        > $maxNewsletterContentBytes
    ) {
        nlp_response(
            false,
            'This newsletter is too large to save (over '
            . round($maxNewsletterContentBytes / (1024 * 1024), 1)
            . ' MB of content). It likely still contains an old, '
            . 'un-optimised image - try removing and re-uploading '
            . 'any large images, then save again.',
            413
        );
    }

    $recipientType = trim(
        (string)($_POST['recipient_type'] ?? 'manual')
    );

    $recipientEmails = (string)(
        $_POST['recipient_emails']
        ?? '[]'
    );

    $saveAction = trim(
        (string)($_POST['save_action'] ?? 'draft')
    );

    $duplicatedFrom = (int)(
        $_POST['duplicated_from']
        ?? 0
    );

    if ($saveAction === 'draft') {
        if ($subject === '') {
            $subject = 'Untitled Newsletter Draft';
        }

        /*
         * Drafts never require recipients.
         */
        $recipientEmails = '[]';
    } elseif ($subject === '') {
        nlp_response(
            false,
            'Subject is required before sending.',
            422
        );
    }

    if (
        $fromEmail !== ''
        && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
    ) {
        nlp_response(
            false,
            'Please enter a valid sender email.',
            422
        );
    }

    $allowedRecipientTypes = [
        'startups',
        'upload',
        'subscribers',
        'manual',
        'saved',
    ];

    if (
        !in_array(
            $recipientType,
            $allowedRecipientTypes,
            true
        )
    ) {
        $recipientType = 'manual';
    }

    $now = date('Y-m-d H:i:s');
    $status = 'draft';

    $hasDuplicatedFrom = nlp_has_column(
        $conn,
        'newsletters',
        'duplicated_from'
    );

    if ($id > 0) {
        if ($hasDuplicatedFrom) {
            $stmt = $conn->prepare("
                UPDATE newsletters
                SET subject = ?,
                    preheader = ?,
                    from_name = ?,
                    from_email = ?,
                    body_json = ?,
                    body_html = ?,
                    recipient_type = ?,
                    recipient_emails = ?,
                    status = ?,
                    duplicated_from = ?,
                    updated_at = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Newsletter update could not be prepared: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssssssssisi',
                $subject,
                $preheader,
                $fromName,
                $fromEmail,
                $bodyJson,
                $bodyHtml,
                $recipientType,
                $recipientEmails,
                $status,
                $duplicatedFrom,
                $now,
                $id
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE newsletters
                SET subject = ?,
                    preheader = ?,
                    from_name = ?,
                    from_email = ?,
                    body_json = ?,
                    body_html = ?,
                    recipient_type = ?,
                    recipient_emails = ?,
                    status = ?,
                    updated_at = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Newsletter update could not be prepared: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'ssssssssssi',
                $subject,
                $preheader,
                $fromName,
                $fromEmail,
                $bodyJson,
                $bodyHtml,
                $recipientType,
                $recipientEmails,
                $status,
                $now,
                $id
            );
        }
    } else {
        if ($hasDuplicatedFrom) {
            $stmt = $conn->prepare("
                INSERT INTO newsletters (
                    subject,
                    preheader,
                    from_name,
                    from_email,
                    body_json,
                    body_html,
                    recipient_type,
                    recipient_emails,
                    status,
                    duplicated_from,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Newsletter insert could not be prepared: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssssssssiss',
                $subject,
                $preheader,
                $fromName,
                $fromEmail,
                $bodyJson,
                $bodyHtml,
                $recipientType,
                $recipientEmails,
                $status,
                $duplicatedFrom,
                $now,
                $now
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO newsletters (
                    subject,
                    preheader,
                    from_name,
                    from_email,
                    body_json,
                    body_html,
                    recipient_type,
                    recipient_emails,
                    status,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Newsletter insert could not be prepared: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssssssssss',
                $subject,
                $preheader,
                $fromName,
                $fromEmail,
                $bodyJson,
                $bodyHtml,
                $recipientType,
                $recipientEmails,
                $status,
                $now,
                $now
            );
        }
    }

    if (!$stmt->execute()) {
        $dbError = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Newsletter could not be saved: '
            . $dbError
        );
    }

    $newsletterId = $id > 0
        ? $id
        : (int)$conn->insert_id;

    $stmt->close();

    if ($saveAction === 'send') {
        $delivery =
            nlp_send_newsletter(
                $conn,
                $newsletterId
            );

        if (
            (int)$delivery['sent']
            <= 0
        ) {
            $firstError =
                (string)(
                    $delivery[
                        'failures'
                    ][0]['error']
                    ?? 'SMTP did not deliver any messages.'
                );

            nlp_response(
                false,
                'Newsletter was saved, but no recipient received the email. '
                . $firstError,
                500,
                [
                    'id' =>
                        $newsletterId,
                    'sent' =>
                        0,
                    'failed' =>
                        (int)$delivery[
                            'failed'
                        ],
                ]
            );
        }

        $message =
            (int)$delivery['failed']
            > 0
                ? 'Newsletter sent to '
                    . (int)$delivery['sent']
                    . ' recipient(s); '
                    . (int)$delivery['failed']
                    . ' delivery attempt(s) failed.'
                : 'Newsletter sent successfully to '
                    . (int)$delivery['sent']
                    . ' recipient(s).';

        nlp_response(
            true,
            $message,
            200,
            [
                'id' =>
                    $newsletterId,
                'sent' =>
                    (int)$delivery[
                        'sent'
                    ],
                'failed' =>
                    (int)$delivery[
                        'failed'
                    ],
            ]
        );
    }

    nlp_response(
        true,
        'Draft saved successfully.',
        200,
        [
            'id' => $newsletterId,
            'status' => 'draft',
        ]
    );
}

/* ============================================================
   DUPLICATE
============================================================ */

if ($action === 'duplicate_newsletter') {
    $id = (int)($_POST['id'] ?? 0);

    $source = nlp_get_newsletter(
        $conn,
        $id
    );

    if (!$source) {
        nlp_response(
            false,
            'The newsletter to duplicate was not found.',
            404
        );
    }

    $subject = trim(
        (string)($source['subject'] ?? '')
    );

    if (!preg_match('/^Copy of\s+/i', $subject)) {
        $subject = 'Copy of '
            . ($subject !== '' ? $subject : 'Newsletter');
    }

    $preheader = (string)($source['preheader'] ?? '');
    $fromName = (string)($source['from_name'] ?? '');
    $fromEmail = (string)($source['from_email'] ?? '');
    /*
     * Duplicate the saved builder data exactly.
     *
     * Do NOT decode/re-encode body_json here. A byte-for-byte copy keeps
     * every section background setting intact, including:
     * - backgroundType
     * - backgroundColor
     * - backgroundImage
     * - backgroundPosition
     * - backgroundSize
     * - backgroundRepeat
     * - Top Bar colours
     * - CTA/footer/block backgrounds
     * - nested column styling
     */
    $postedBodyJson = (string)($_POST['source_body_json'] ?? '');
    $postedBodyHtml = (string)($_POST['source_body_html'] ?? '');

    $bodyJson = trim($postedBodyJson) !== ''
        ? $postedBodyJson
        : (string)($source['body_json'] ?? '[]');

    $bodyHtml = trim($postedBodyHtml) !== ''
        ? $postedBodyHtml
        : (string)($source['body_html'] ?? '');

    /*
     * Validate only. Do not transform the JSON.
     */
    $decodedBodyJson = json_decode($bodyJson, true);

    if (!is_array($decodedBodyJson)) {
        $bodyJson = (string)($source['body_json'] ?? '[]');

        $decodedBodyJson = json_decode($bodyJson, true);

        if (!is_array($decodedBodyJson)) {
            $bodyJson = '[]';
        }
    }

    /*
     * The duplicate is always a new draft and deliberately starts with
     * no recipients.
     */
    $recipientType = 'manual';
    $recipientEmails = '[]';
    $now = date('Y-m-d H:i:s');

    $hasDuplicatedFrom = nlp_has_column(
        $conn,
        'newsletters',
        'duplicated_from'
    );

    if ($hasDuplicatedFrom) {
        $stmt = $conn->prepare("
            INSERT INTO newsletters (
                subject,
                preheader,
                from_name,
                from_email,
                body_json,
                body_html,
                recipient_type,
                recipient_emails,
                status,
                duplicated_from,
                created_at,
                updated_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, 'draft', ?, ?, ?
            )
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Duplicate could not be prepared: '
                . $conn->error
            );
        }

        $stmt->bind_param(
            'ssssssssiss',
            $subject,
            $preheader,
            $fromName,
            $fromEmail,
            $bodyJson,
            $bodyHtml,
            $recipientType,
            $recipientEmails,
            $id,
            $now,
            $now
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO newsletters (
                subject,
                preheader,
                from_name,
                from_email,
                body_json,
                body_html,
                recipient_type,
                recipient_emails,
                status,
                created_at,
                updated_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, 'draft', ?, ?
            )
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Duplicate could not be prepared: '
                . $conn->error
            );
        }

        $stmt->bind_param(
            'ssssssssss',
            $subject,
            $preheader,
            $fromName,
            $fromEmail,
            $bodyJson,
            $bodyHtml,
            $recipientType,
            $recipientEmails,
            $now,
            $now
        );
    }

    if (!$stmt->execute()) {
        $dbError = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Newsletter could not be duplicated: '
            . $dbError
        );
    }

    $newId = (int)$conn->insert_id;
    $stmt->close();

    /*
     * Confirm the copied draft has the same saved design JSON.
     */
    $copied = nlp_get_newsletter($conn, $newId);

    if (
        !$copied
        || (string)($copied['body_json'] ?? '') !== $bodyJson
    ) {
        throw new RuntimeException(
            'The newsletter was duplicated, but its saved design could not be verified.'
        );
    }

    nlp_response(
        true,
        'Newsletter duplicated as a new draft with all backgrounds preserved.',
        200,
        [
            'id' => $newId,
            'duplicated_from' => $id,
        ]
    );
}

/* ============================================================
   SEND NOW
============================================================ */

if ($action === 'send_now') {
    $id = (int)($_POST['id'] ?? 0);

    $delivery =
        nlp_send_newsletter(
            $conn,
            $id
        );

    if (
        (int)$delivery['sent']
        <= 0
    ) {
        $firstError =
            (string)(
                $delivery[
                    'failures'
                ][0]['error']
                ?? 'SMTP did not deliver any messages.'
            );

        nlp_response(
            false,
            'No recipient received the newsletter. '
            . $firstError,
            500,
            [
                'id' =>
                    $id,
                'sent' =>
                    0,
                'failed' =>
                    (int)$delivery[
                        'failed'
                    ],
            ]
        );
    }

    $message =
        (int)$delivery['failed']
        > 0
            ? 'Newsletter sent to '
                . (int)$delivery['sent']
                . ' recipient(s); '
                . (int)$delivery['failed']
                . ' delivery attempt(s) failed.'
            : 'Newsletter sent successfully to '
                . (int)$delivery['sent']
                . ' recipient(s).';

    nlp_response(
        true,
        $message,
        200,
        [
            'id' =>
                $id,
            'sent' =>
                (int)$delivery[
                    'sent'
                ],
            'failed' =>
                (int)$delivery[
                    'failed'
                ],
        ]
    );
}

/* ============================================================
   DELETE
============================================================ */

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    $stmt = $conn->prepare("
        DELETE FROM newsletters
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Newsletter delete could not be prepared.'
        );
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    nlp_response(
        true,
        'Newsletter deleted successfully.'
    );
}

/* ============================================================
   REMOVE SUBSCRIBER
============================================================ */

if ($action === 'remove_subscriber') {
    if (
        !nlp_table_exists(
            $conn,
            'newsletter_subscribers'
        )
    ) {
        nlp_response(
            false,
            'Newsletter subscribers table does not exist.',
            422
        );
    }

    $id = (int)($_POST['id'] ?? 0);

    $stmt = $conn->prepare("
        DELETE FROM newsletter_subscribers
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    nlp_response(
        true,
        'Subscriber removed successfully.'
    );
}

/* ============================================================
   HERO
============================================================ */

if ($action === 'save_hero_banner') {
    $bgType = trim(
        (string)(
            $_POST['newsletter_hero_bg_type']
            ?? 'color'
        )
    );

    if (
        !in_array(
            $bgType,
            ['color', 'image', 'video'],
            true
        )
    ) {
        $bgType = 'color';
    }

    $bgColor = trim(
        (string)(
            $_POST['newsletter_hero_bg_color']
            ?? '#0f172a'
        )
    );

    if (
        !preg_match(
            '/^#[0-9A-Fa-f]{6}$/',
            $bgColor
        )
    ) {
        $bgColor = '#0f172a';
    }

    $overlayColor = trim(
        (string)(
            $_POST['newsletter_hero_bg_overlay_color']
            ?? '#000000'
        )
    );

    if (
        !preg_match(
            '/^#[0-9A-Fa-f]{6}$/',
            $overlayColor
        )
    ) {
        $overlayColor = '#000000';
    }

    $opacity = max(
        0,
        min(
            100,
            (int)(
                $_POST['newsletter_hero_bg_overlay_opacity']
                ?? 40
            )
        )
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_bg_type',
        $bgType
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_bg_color',
        $bgColor
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_bg_overlay',
        isset($_POST['newsletter_hero_bg_overlay'])
            ? '1'
            : '0'
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_bg_overlay_color',
        $overlayColor
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_bg_overlay_opacity',
        (string)$opacity
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_label',
        trim(
            (string)(
                $_POST['newsletter_hero_label']
                ?? 'Newsletter'
            )
        )
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_title',
        trim(
            (string)(
                $_POST['newsletter_hero_title']
                ?? ''
            )
        )
    );

    nlp_save_setting(
        $conn,
        'newsletter_hero_subtitle',
        trim(
            (string)(
                $_POST['newsletter_hero_subtitle']
                ?? ''
            )
        )
    );

    if (
        !empty(
            $_FILES[
                'newsletter_hero_bg_image_file'
            ]['name']
        )
    ) {
        /*
         * Hero banner image: shown large/full-bleed on the public page,
         * so it keeps a bigger footprint than in-body images, but is
         * still capped in dimensions, upload size, and final byte
         * budget so it doesn't slow down the newsletter page itself.
         */
        $upload = nlp_upload(
            $_FILES[
                'newsletter_hero_bg_image_file'
            ],
            'settings/backgrounds',
            ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            8 * 1024 * 1024,
            true,
            1920,
            1080,
            78,
            74,
            600 * 1024
        );

        if (!$upload['ok']) {
            nlp_response(
                false,
                (string)$upload['message'],
                422
            );
        }

        nlp_save_setting(
            $conn,
            'newsletter_hero_bg_image',
            (string)$upload['path']
        );

        nlp_save_setting(
            $conn,
            'newsletter_hero_bg_type',
            'image'
        );
    }

    if (
        !empty(
            $_FILES[
                'newsletter_hero_bg_video_file'
            ]['name']
        )
    ) {
        $upload = nlp_upload(
            $_FILES[
                'newsletter_hero_bg_video_file'
            ],
            'settings/backgrounds',
            ['mp4', 'webm', 'ogg'],
            30 * 1024 * 1024
        );

        if (!$upload['ok']) {
            nlp_response(
                false,
                (string)$upload['message'],
                422
            );
        }

        nlp_save_setting(
            $conn,
            'newsletter_hero_bg_video',
            (string)$upload['path']
        );

        nlp_save_setting(
            $conn,
            'newsletter_hero_bg_type',
            'video'
        );
    }

    nlp_redirect(
        'Newsletter hero banner updated successfully.',
        'success',
        'hero'
    );
}

/* ============================================================
   PDF NEWSLETTER
============================================================ */

if ($action === 'save_pdf_newsletter') {
    $saveAction = trim(
        (string)(
            $_POST['save_action']
            ?? 'draft'
        )
    );

    $subject = trim(
        (string)(
            $_POST['subject']
            ?? ''
        )
    );

    if (
        $subject === ''
        && $saveAction === 'draft'
    ) {
        $subject = 'Untitled PDF Newsletter Draft';
    }

    if ($subject === '') {
        nlp_response(
            false,
            'Subject is required before sending.',
            422
        );
    }

    $pdfPath = '';
    $pdfName = '';

    if (
        !empty(
            $_FILES['newsletter_pdf']['name']
        )
    ) {
        $upload = nlp_upload(
            $_FILES['newsletter_pdf'],
            'newsletters/pdf',
            ['pdf'],
            20 * 1024 * 1024
        );

        if (!$upload['ok']) {
            nlp_response(
                false,
                (string)$upload['message'],
                422
            );
        }

        $pdfPath = (string)$upload['path'];
        $pdfName = (string)$upload['name'];
    } elseif ($saveAction !== 'draft') {
        nlp_response(
            false,
            'Please upload the PDF newsletter.',
            422
        );
    }

    $preheader = trim(
        (string)(
            $_POST['preheader']
            ?? ''
        )
    );

    $fromName = trim(
        (string)(
            $_POST['from_name']
            ?? ''
        )
    );

    $fromEmail = trim(
        (string)(
            $_POST['from_email']
            ?? ''
        )
    );

    $bodyMessage = trim(
        (string)(
            $_POST['body_message']
            ?? ''
        )
    );

    $recipientType = trim(
        (string)(
            $_POST['recipient_type']
            ?? 'manual'
        )
    );

    $recipientEmails = (string)(
        $_POST['recipient_emails']
        ?? '[]'
    );

    if ($saveAction === 'draft') {
        $recipientEmails = '[]';
    }

    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT INTO newsletters (
            subject,
            preheader,
            from_name,
            from_email,
            body_message,
            pdf_path,
            pdf_file,
            pdf_name,
            recipient_type,
            recipient_emails,
            status,
            created_at,
            updated_at
        )
        VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            'draft', ?, ?
        )
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'PDF newsletter insert could not be prepared: '
            . $conn->error
        );
    }

    $stmt->bind_param(
        'ssssssssssss',
        $subject,
        $preheader,
        $fromName,
        $fromEmail,
        $bodyMessage,
        $pdfPath,
        $pdfPath,
        $pdfName,
        $recipientType,
        $recipientEmails,
        $now,
        $now
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'PDF newsletter could not be saved: '
            . $error
        );
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    nlp_response(
        true,
        'PDF newsletter saved as draft.',
        200,
        [
            'id' => $id,
        ]
    );
}

nlp_response(
    false,
    'Unknown newsletter action.',
    422
);
