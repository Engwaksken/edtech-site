<?php

declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';



if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');


function redirect_blogs(): void
{
    header('Location: ../blogs.php');
    exit;
}

function blog_col_exists(mysqli $conn, string $column): bool
{
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

    if ($column === '') {
        return false;
    }

    $escapedColumn = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `blogs` LIKE '{$escapedColumn}'"
    );

    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function blog_h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function blog_flash_error(string $message): void
{
    flash('blogs', $message, 'error');
}

function blog_flash_success(string $message): void
{
    flash('blogs', $message, 'success');
}



function blog_style_attr(array $section): string
{
    $sizes = [
        'small'  => '14px',
        'normal' => '16px',
        'large'  => '18px',
        'xlarge' => '22px',
        'huge'   => '28px',
    ];

    $weights = [
        'light'    => '300',
        'normal'   => '400',
        'medium'   => '500',
        'semibold' => '600',
        'bold'     => '700',
    ];

    $sizeKey = (string) ($section['fontSize'] ?? 'normal');
    $weightKey = (string) ($section['fontWeight'] ?? 'normal');

    $fontStyle = (
        ($section['fontStyle'] ?? 'normal') === 'italic'
    ) ? 'italic' : 'normal';

    $fontSize = $sizes[$sizeKey] ?? $sizes['normal'];
    $fontWeight = $weights[$weightKey] ?? $weights['normal'];

    if (
        $fontSize === $sizes['normal']
        && $fontWeight === $weights['normal']
        && $fontStyle === 'normal'
    ) {
        return '';
    }

    return sprintf(
        ' style="font-size:%s;font-weight:%s;font-style:%s;"',
        $fontSize,
        $fontWeight,
        $fontStyle
    );
}



function blog_prepare_published_at(
    string $status,
    string $publishedAt
): ?string {
    if ($status === 'draft') {
        return null;
    }

    $publishedAt = trim($publishedAt);

    if ($publishedAt === '') {
        return $status === 'published'
            ? date('Y-m-d H:i:s')
            : null;
    }

    $publishedAt = str_replace('T', ' ', $publishedAt);

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $publishedAt);

        if ($date instanceof DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return $status === 'published'
        ? date('Y-m-d H:i:s')
        : null;
}



function blog_upload_image_file(
    string $field,
    string $prefix,
    string &$error = ''
): string {
    $error = '';

    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return '';
    }

    $file = $_FILES[$field];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return '';
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $error = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'Image upload failed because the file is too large.',
            UPLOAD_ERR_PARTIAL   => 'Image upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload error: temporary folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server upload error: unable to write the image to disk.',
            UPLOAD_ERR_EXTENSION  => 'Image upload was stopped by a server extension.',
            default               => 'Image upload failed. Please try again.',
        };
        return '';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'Invalid uploaded image.';
        return '';
    }

    $maxSize = 10 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) <= 0) {
        $error = 'The uploaded image is empty.';
        return '';
    }

    if ((int) ($file['size'] ?? 0) > $maxSize) {
        $error = 'Image must not exceed 10MB.';
        return '';
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        $error = 'Invalid image type. Use JPG, PNG, WEBP or GIF.';
        return '';
    }

    // Validate the actual file content, not only the extension supplied by the browser.
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        $error = 'The uploaded file is not a valid image.';
        return '';
    }

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mimeType = (string) ($imageInfo['mime'] ?? '');

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        $error = 'The uploaded image format is not supported.';
        return '';
    }

    // Normalize the extension according to the verified MIME type.
    $extension = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => $extension,
    };

    $directory = dirname(__DIR__, 2) . '/uploads/blogs/';

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            $error = 'Unable to create the blog upload directory.';
            return '';
        }
    }

    if (!is_writable($directory)) {
        $error = 'The blog upload directory is not writable.';
        return '';
    }

    try {
        $randomName = bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        $randomName = str_replace('.', '', uniqid('', true));
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', $prefix) ?: 'blog-image';
    $filename = sprintf(
        '%s-%s-%s.%s',
        $safePrefix,
        date('YmdHis'),
        $randomName,
        $extension
    );

    $targetPath = $directory . $filename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        $error = 'Failed to save the uploaded image. Check uploads/blogs permissions.';
        return '';
    }

    @chmod($targetPath, 0644);

    return 'uploads/blogs/' . $filename;
}

function upload_blog_builder_image(
    string $field,
    string &$error = ''
): string {
    return blog_upload_image_file($field, 'blog-section', $error);
}

function upload_blog_featured_image(
    string $field = 'featured_image',
    string &$error = ''
): string {
    return blog_upload_image_file($field, 'blog-featured', $error);
}


/*
|--------------------------------------------------------------------------
| Apply uploaded files to builder sections BEFORE rendering/saving JSON
|--------------------------------------------------------------------------
*/
function blog_apply_builder_uploads(
    array &$sections,
    array &$errors = []
): void {
    $errors = [];
    $files = $_FILES['builder_images'] ?? null;

    foreach ($sections as &$section) {
        if (!is_array($section) || (string)($section['type'] ?? '') !== 'image') {
            continue;
        }

        $sectionId = preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            (string)($section['id'] ?? '')
        );

        if ($sectionId === '') {
            $errors[] = 'An image block has an invalid section ID.';
            continue;
        }

        // No new upload for this image block: retain the existing src.
        if (!is_array($files)) {
            continue;
        }

        $name       = (string)($files['name'][$sectionId] ?? '');
        $tmpName    = (string)($files['tmp_name'][$sectionId] ?? '');
        $uploadCode = (int)($files['error'][$sectionId] ?? UPLOAD_ERR_NO_FILE);
        $size       = (int)($files['size'][$sectionId] ?? 0);
        $mimeType   = (string)($files['type'][$sectionId] ?? '');

        if ($uploadCode === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }

        // Convert this nested builder_images item into normal $_FILES format
        // so the validated uploader can save it exactly like featured images.
        $temporaryField = '__builder_image_' . $sectionId;
        $_FILES[$temporaryField] = [
            'name'     => $name,
            'type'     => $mimeType,
            'tmp_name' => $tmpName,
            'error'    => $uploadCode,
            'size'     => $size,
        ];

        $uploadError = '';
        $newImage = blog_upload_image_file(
            $temporaryField,
            'blog-section',
            $uploadError
        );
        unset($_FILES[$temporaryField]);

        if ($newImage !== '') {
            // Persist the physical image path in this exact builder block.
            $section['src'] = $newImage;
        }

        if ($uploadError !== '') {
            $errors[] = 'Image block: ' . $uploadError;
        }
    }

    unset($section);
}

/*
|--------------------------------------------------------------------------
| Safe table data checker
|--------------------------------------------------------------------------
*/

function blog_table_has_data(
    array $headers,
    array $rows
): bool {
    foreach ($headers as $header) {
        if (trim((string) $header) !== '') {
            return true;
        }
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Build blog HTML
|--------------------------------------------------------------------------
*/

function build_blog_html(
    array &$sections,
    array &$builderErrors = []
): string {
    $builderErrors = [];

    $html = '<div class="elementor-blog-content">';

    foreach ($sections as &$section) {
        if (!is_array($section)) {
            continue;
        }

        $type = (string) ($section['type'] ?? 'paragraph');

        $sectionId = preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            (string) ($section['id'] ?? '')
        );

        /*
        |--------------------------------------------------------------------------
        | Heading
        |--------------------------------------------------------------------------
        */

        if ($type === 'heading') {
            $allowedLevels = [
                'h1',
                'h2',
                'h3',
                'h4',
            ];

            $requestedLevel = (string) (
                $section['level'] ?? 'h2'
            );

            $level = in_array(
                $requestedLevel,
                $allowedLevels,
                true
            ) ? $requestedLevel : 'h2';

            $text = trim(
                (string) ($section['text'] ?? '')
            );

            $style = blog_style_attr($section);

            if ($text !== '') {
                $safeText = blog_h($text);

                $html .= sprintf(
                    '<section class="blog-block blog-heading">' .
                    '<%1$s class="article-heading"%2$s>%3$s</%1$s>' .
                    '</section>',
                    $level,
                    $style,
                    $safeText
                );
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Paragraph
        |--------------------------------------------------------------------------
        */

        if ($type === 'paragraph') {
            $text = trim(
                (string) ($section['text'] ?? '')
            );

            $style = blog_style_attr($section);

            if ($text !== '') {
                $paragraphs = preg_split(
                    "/\r\n|\n|\r/",
                    $text
                );

                $html .= '<section class="blog-block blog-paragraph">';

                foreach ($paragraphs ?: [] as $paragraph) {
                    $paragraph = trim((string) $paragraph);

                    if ($paragraph === '') {
                        continue;
                    }

                    $html .= sprintf(
                        '<p%s>%s</p>',
                        $style,
                        nl2br(blog_h($paragraph))
                    );
                }

                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Image
        |--------------------------------------------------------------------------
        */

        if ($type === 'image') {
            $src = trim(
                (string) ($section['src'] ?? '')
            );

            $url = trim(
                (string) ($section['url'] ?? '')
            );

            $caption = trim(
                (string) ($section['caption'] ?? '')
            );

            $allowedAlignments = [
                'left',
                'right',
                'center',
                'full',
            ];

            $requestedAlignment = (string) (
                $section['align'] ?? 'center'
            );

            $alignment = in_array(
                $requestedAlignment,
                $allowedAlignments,
                true
            ) ? $requestedAlignment : 'center';

            $imageSource = $src !== '' ? $src : $url;

            if ($imageSource !== '') {
                $safeImage = blog_h($imageSource);
                $safeCaption = blog_h($caption);

                $html .= sprintf(
                    '<section class="blog-block blog-image img-align-%s">',
                    blog_h($alignment)
                );

                $html .= '<figure class="article-figure">';

                $html .= sprintf(
                    '<img src="%s" alt="%s" loading="lazy" class="article-img">',
                    $safeImage,
                    $safeCaption
                );

                if ($safeCaption !== '') {
                    $html .= sprintf(
                        '<figcaption>%s</figcaption>',
                        $safeCaption
                    );
                }

                $html .= '</figure>';
                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Quote
        |--------------------------------------------------------------------------
        */

        if ($type === 'quote') {
            $text = trim(
                (string) ($section['text'] ?? '')
            );

            $author = trim(
                (string) ($section['author'] ?? '')
            );

            $source = trim(
                (string) ($section['source'] ?? '')
            );

            $style = blog_style_attr($section);

            if ($text !== '') {
                $html .= '<section class="blog-block blog-quote">';
                $html .= '<blockquote class="article-blockquote">';

                $html .= sprintf(
                    '<p%s>%s</p>',
                    $style,
                    nl2br(blog_h($text))
                );

                if ($author !== '' || $source !== '') {
                    $html .= '<footer class="blockquote-footer">';

                    if ($author !== '') {
                        $html .= sprintf(
                            '<cite class="quote-author">%s</cite>',
                            blog_h($author)
                        );
                    }

                    if ($source !== '') {
                        $separator = $author !== '' ? ', ' : '';

                        $html .= sprintf(
                            '<span class="quote-source">%s%s</span>',
                            $separator,
                            blog_h($source)
                        );
                    }

                    $html .= '</footer>';
                }

                $html .= '</blockquote>';
                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Table
        |--------------------------------------------------------------------------
        */

        if ($type === 'table') {
            $caption = trim(
                (string) ($section['caption'] ?? '')
            );

            $headers = is_array(
                $section['headers'] ?? null
            ) ? $section['headers'] : [];

            $rows = is_array(
                $section['rows'] ?? null
            ) ? $section['rows'] : [];

            if (blog_table_has_data($headers, $rows)) {
                $html .= '<section class="blog-block blog-table">';

                if ($caption !== '') {
                    $html .= sprintf(
                        '<p class="table-caption">%s</p>',
                        blog_h($caption)
                    );
                }

                $html .= '<div class="table-responsive">';
                $html .= '<table class="article-table">';

                if (!empty($headers)) {
                    $html .= '<thead><tr>';

                    foreach ($headers as $header) {
                        $html .= sprintf(
                            '<th>%s</th>',
                            blog_h($header)
                        );
                    }

                    $html .= '</tr></thead>';
                }

                if (!empty($rows)) {
                    $html .= '<tbody>';

                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $html .= '<tr>';

                        foreach ($row as $cell) {
                            $html .= sprintf(
                                '<td>%s</td>',
                                nl2br(blog_h($cell))
                            );
                        }

                        $html .= '</tr>';
                    }

                    $html .= '</tbody>';
                }

                $html .= '</table>';
                $html .= '</div>';
                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | List
        |--------------------------------------------------------------------------
        */

        if ($type === 'list') {
            $listTag = (
                ($section['style'] ?? 'unordered') === 'ordered'
            ) ? 'ol' : 'ul';

            $fontStyle = blog_style_attr($section);

            $items = is_array(
                $section['items'] ?? null
            ) ? $section['items'] : [];

            $cleanItems = [];

            foreach ($items as $item) {
                $item = trim((string) $item);

                if ($item !== '') {
                    $cleanItems[] = $item;
                }
            }

            if (!empty($cleanItems)) {
                $html .= '<section class="blog-block blog-list">';

                $html .= sprintf(
                    '<%s class="article-list"%s>',
                    $listTag,
                    $fontStyle
                );

                foreach ($cleanItems as $item) {
                    $html .= sprintf(
                        '<li>%s</li>',
                        blog_h($item)
                    );
                }

                $html .= sprintf(
                    '</%s>',
                    $listTag
                );

                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Callout
        |--------------------------------------------------------------------------
        */

        if ($type === 'callout') {
            $allowedVariants = [
                'info',
                'tip',
                'warning',
                'danger',
            ];

            $requestedVariant = (string) (
                $section['variant'] ?? 'info'
            );

            $variant = in_array(
                $requestedVariant,
                $allowedVariants,
                true
            ) ? $requestedVariant : 'info';

            $title = trim(
                (string) ($section['title'] ?? '')
            );

            $text = trim(
                (string) ($section['text'] ?? '')
            );

            $style = blog_style_attr($section);

            $icons = [
                'info'    => 'i',
                'tip'     => '*',
                'warning' => '!',
                'danger'  => '!!',
            ];

            if ($text !== '') {
                $html .= sprintf(
                    '<section class="blog-block blog-callout callout-%s">',
                    blog_h($variant)
                );

                if ($title !== '') {
                    $html .= sprintf(
                        '<div class="callout-title">%s %s</div>',
                        blog_h($icons[$variant]),
                        blog_h($title)
                    );
                }

                $html .= sprintf(
                    '<div class="callout-body"%s>%s</div>',
                    $style,
                    nl2br(blog_h($text))
                );

                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Divider
        |--------------------------------------------------------------------------
        */

        if ($type === 'divider') {
            $allowedStyles = [
                'solid',
                'dashed',
                'decorative',
            ];

            $requestedStyle = (string) (
                $section['style'] ?? 'solid'
            );

            $dividerStyle = in_array(
                $requestedStyle,
                $allowedStyles,
                true
            ) ? $requestedStyle : 'solid';

            $html .= sprintf(
                '<section class="blog-block blog-divider">' .
                '<hr class="divider-%s">' .
                '</section>',
                blog_h($dividerStyle)
            );

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Two columns
        |--------------------------------------------------------------------------
        */

        if ($type === 'columns') {
            $left = trim(
                (string) ($section['left'] ?? '')
            );

            $right = trim(
                (string) ($section['right'] ?? '')
            );

            $style = blog_style_attr($section);

            if ($left !== '' || $right !== '') {
                $html .= '<section class="blog-block blog-columns">';
                $html .= '<div class="columns-wrap">';

                $html .= sprintf(
                    '<div class="column-item"%s>%s</div>',
                    $style,
                    nl2br(blog_h($left))
                );

                $html .= sprintf(
                    '<div class="column-item"%s>%s</div>',
                    $style,
                    nl2br(blog_h($right))
                );

                $html .= '</div>';
                $html .= '</section>';
            }

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Button
        |--------------------------------------------------------------------------
        */

        if ($type === 'button') {
            $text = trim(
                (string) ($section['text'] ?? 'Read More')
            );

            if ($text === '') {
                $text = 'Read More';
            }

            $url = trim(
                (string) ($section['url'] ?? '')
            );

            $allowedStyles = [
                'primary',
                'secondary',
                'outline',
            ];

            $requestedStyle = (string) (
                $section['style'] ?? 'primary'
            );

            $buttonStyle = in_array(
                $requestedStyle,
                $allowedStyles,
                true
            ) ? $requestedStyle : 'primary';

            $fontStyle = blog_style_attr($section);

            if ($url !== '') {
                $html .= '<section class="blog-block blog-button">';

                $html .= sprintf(
                    '<a class="blog-btn blog-btn-%s" href="%s" ' .
                    'target="_blank" rel="noopener noreferrer"%s>%s</a>',
                    blog_h($buttonStyle),
                    blog_h($url),
                    $fontStyle,
                    blog_h($text)
                );

                $html .= '</section>';
            }

            continue;
        }
    }

    unset($section);

    $html .= '</div>';

    return $html;
}

/*
|--------------------------------------------------------------------------
| Delete uploaded image created during failed save
|--------------------------------------------------------------------------
*/

function blog_delete_uploaded_file(string $imagePath): void
{
    $imagePath = trim($imagePath);

    if ($imagePath === '') {
        return;
    }

    if (function_exists('delete_image')) {
        delete_image($imagePath);
        return;
    }

    $normalizedPath = ltrim(
        str_replace('\\', '/', $imagePath),
        '/'
    );

    $fullPath = '../../' . $normalizedPath;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| Only accept POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_blogs();
}

$action = trim(
    (string) ($_POST['action'] ?? '')
);


/*
|--------------------------------------------------------------------------
| AJAX: upload one Article Builder image immediately
|--------------------------------------------------------------------------
*/
if ($action === 'upload_builder_image') {
    header('Content-Type: application/json; charset=utf-8');

    $sectionId = preg_replace(
        '/[^a-zA-Z0-9_]/',
        '',
        (string) ($_POST['section_id'] ?? '')
    );

    if ($sectionId === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid image block ID.',
        ]);
        exit;
    }

    if (
        !isset($_FILES['builder_image'])
        || !is_array($_FILES['builder_image'])
        || (int) ($_FILES['builder_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please select an image to upload.',
        ]);
        exit;
    }

    $uploadError = '';
    $savedPath = blog_upload_image_file(
        'builder_image',
        'blog-section',
        $uploadError
    );

    if ($savedPath === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $uploadError !== ''
                ? $uploadError
                : 'Unable to save the builder image.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'path' => $savedPath,
        'section_id' => $sectionId,
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

/*
|--------------------------------------------------------------------------
| Save article
|--------------------------------------------------------------------------
*/

if ($action === 'save') {
    // Useful server-side diagnostic: confirms which multipart file fields arrived.
    // This goes to the PHP error log, not to the browser.
    if (!empty($_FILES)) {
        error_log('[BLOG IMAGE UPLOAD] fields=' . implode(',', array_keys($_FILES)));
        if (isset($_FILES['builder_images']['name']) && is_array($_FILES['builder_images']['name'])) {
            error_log('[BLOG BUILDER IMAGES] ids=' . implode(',', array_keys($_FILES['builder_images']['name'])));
        }
    }

    $id = (int) ($_POST['id'] ?? 0);

    $title = trim(
        (string) ($_POST['title'] ?? '')
    );

    $excerpt = trim(
        (string) ($_POST['excerpt'] ?? '')
    );

    $author = trim(
        (string) ($_POST['author'] ?? '')
    );

    $category = trim(
        (string) ($_POST['category'] ?? '')
    );

    $status = trim(
        (string) ($_POST['status'] ?? 'draft')
    );

    $publishedAtInput = trim(
        (string) ($_POST['published_at'] ?? '')
    );

    $sortOrder = (int) (
        $_POST['sort_order'] ?? 0
    );

    $featuredImage = trim(
        (string) ($_POST['existing_image'] ?? '')
    );

    $builderRaw = (string) (
        $_POST['content_builder'] ?? ''
    );

    /*
    |--------------------------------------------------------------------------
    | Validate input
    |--------------------------------------------------------------------------
    */

    if ($title === '') {
        blog_flash_error('Title is required.');
        redirect_blogs();
    }

    $allowedStatuses = [
        'draft',
        'published',
        'archived',
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'draft';
    }

    $publishedAt = blog_prepare_published_at(
        $status,
        $publishedAtInput
    );

    /*
    |--------------------------------------------------------------------------
    | Decode content builder
    |--------------------------------------------------------------------------
    */

    $sections = [];

    if ($builderRaw !== '') {
        try {
            $decodedSections = json_decode(
                $builderRaw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (is_array($decodedSections)) {
                $sections = $decodedSections;
            }
        } catch (JsonException $exception) {
            blog_flash_error(
                'The article builder content is invalid. Please refresh and try again.'
            );

            redirect_blogs();
        }
    }

    // First upload all builder images and write their saved paths
    // into the same section array that will be persisted as JSON.
    $uploadErrors = [];
    blog_apply_builder_uploads(
        $sections,
        $uploadErrors
    );

    if (!empty($uploadErrors)) {
        blog_flash_error(
            implode(' ', array_unique($uploadErrors))
        );
        redirect_blogs();
    }

    $builderErrors = [];

    $content = build_blog_html(
        $sections,
        $builderErrors
    );

    if (!empty($builderErrors)) {
        blog_flash_error(
            implode(' ', array_unique($builderErrors))
        );

        redirect_blogs();
    }

    $plainContent = trim(
        html_entity_decode(
            strip_tags($content),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );

    /*
     * Image-only and divider-only articles may not contain normal text.
     * Check the builder itself when strip_tags() gives an empty result.
     */
    $hasBuilderSection = false;

    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }

        $type = (string) ($section['type'] ?? '');

        if ($type === 'image') {
            $imageSource = trim(
                (string) (
                    $section['src']
                    ?? $section['url']
                    ?? ''
                )
            );

            if ($imageSource !== '') {
                $hasBuilderSection = true;
                break;
            }
        }

        if ($type === 'divider') {
            $hasBuilderSection = true;
            break;
        }

        $contentFields = [
            'text',
            'title',
            'left',
            'right',
            'caption',
        ];

        foreach ($contentFields as $field) {
            if (
                trim(
                    (string) ($section[$field] ?? '')
                ) !== ''
            ) {
                $hasBuilderSection = true;
                break 2;
            }
        }

        if (
            !empty($section['items'])
            && is_array($section['items'])
        ) {
            foreach ($section['items'] as $item) {
                if (trim((string) $item) !== '') {
                    $hasBuilderSection = true;
                    break 2;
                }
            }
        }

        if (
            !empty($section['headers'])
            || !empty($section['rows'])
        ) {
            $hasBuilderSection = true;
            break;
        }
    }

    if ($plainContent === '' && !$hasBuilderSection) {
        blog_flash_error(
            'Please add at least one article section with content.'
        );

        redirect_blogs();
    }

    $contentBuilder = json_encode(
        $sections,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    if ($contentBuilder === false) {
        blog_flash_error(
            'Failed to prepare the article builder content.'
        );

        redirect_blogs();
    }

    /*
    |--------------------------------------------------------------------------
    | Featured image upload
    |--------------------------------------------------------------------------
    */

    $oldFeaturedImage = $featuredImage;
    $newFeaturedImage = '';

    $uploadError = '';
    $uploadedImage = upload_blog_featured_image(
        'featured_image',
        $uploadError
    );

    if ($uploadError !== '') {
        blog_flash_error($uploadError);
        redirect_blogs();
    }

    if ($uploadedImage !== '') {
        $newFeaturedImage = $uploadedImage;
        $featuredImage = $newFeaturedImage;
    }

    /*
    |--------------------------------------------------------------------------
    | Generate unique slug
    |--------------------------------------------------------------------------
    */

    $slugBase = function_exists('slugify')
        ? slugify($title)
        : strtolower(
            trim(
                preg_replace(
                    '/[^a-z0-9]+/i',
                    '-',
                    $title
                ),
                '-'
            )
        );

    if ($slugBase === '') {
        $slugBase = 'blog';
    }

    /*
     * unique_slug() expects:
     *
     * int $exclude_id = 0
     *
     * $id is always an integer because it was cast above.
     * New article: $id = 0
     * Existing article: $id = the existing record ID
     */
    $slug = function_exists('unique_slug')
        ? unique_slug(
            $conn,
            'blogs',
            'slug',
            $slugBase,
            $id
        )
        : $slugBase;

    $hasBuilderColumn = blog_col_exists(
        $conn,
        'content_builder'
    );

    /*
    |--------------------------------------------------------------------------
    | Begin database transaction
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    try {
        /*
        |--------------------------------------------------------------------------
        | Update article
        |--------------------------------------------------------------------------
        */

        if ($id > 0) {
            $existingStatement = $conn->prepare(
                'SELECT id FROM blogs WHERE id = ? LIMIT 1'
            );

            if (!$existingStatement) {
                throw new RuntimeException(
                    'Database error: ' . $conn->error
                );
            }

            $existingStatement->bind_param(
                'i',
                $id
            );

            $existingStatement->execute();

            $existingResult = $existingStatement->get_result();
            $articleExists = (
                $existingResult instanceof mysqli_result
                && $existingResult->num_rows > 0
            );

            $existingStatement->close();

            if (!$articleExists) {
                throw new RuntimeException(
                    'The selected article was not found.'
                );
            }

            if ($hasBuilderColumn) {
                $statement = $conn->prepare(
                    'UPDATE blogs SET
                        title = ?,
                        slug = ?,
                        excerpt = ?,
                        content = ?,
                        content_builder = ?,
                        featured_image = ?,
                        author = ?,
                        category = ?,
                        status = ?,
                        published_at = ?,
                        sort_order = ?
                     WHERE id = ?
                     LIMIT 1'
                );

                if (!$statement) {
                    throw new RuntimeException(
                        'Database error: ' . $conn->error
                    );
                }

                $statement->bind_param(
                    'ssssssssssii',
                    $title,
                    $slug,
                    $excerpt,
                    $content,
                    $contentBuilder,
                    $featuredImage,
                    $author,
                    $category,
                    $status,
                    $publishedAt,
                    $sortOrder,
                    $id
                );
            } else {
                $statement = $conn->prepare(
                    'UPDATE blogs SET
                        title = ?,
                        slug = ?,
                        excerpt = ?,
                        content = ?,
                        featured_image = ?,
                        author = ?,
                        category = ?,
                        status = ?,
                        published_at = ?,
                        sort_order = ?
                     WHERE id = ?
                     LIMIT 1'
                );

                if (!$statement) {
                    throw new RuntimeException(
                        'Database error: ' . $conn->error
                    );
                }

                $statement->bind_param(
                    'sssssssssii',
                    $title,
                    $slug,
                    $excerpt,
                    $content,
                    $featuredImage,
                    $author,
                    $category,
                    $status,
                    $publishedAt,
                    $sortOrder,
                    $id
                );
            }

            if (!$statement->execute()) {
                $statementError = $statement->error;
                $statement->close();

                throw new RuntimeException(
                    'Failed to update article: ' . $statementError
                );
            }

            $statement->close();

            $conn->commit();

            /*
             * Delete the old featured image only after the database
             * update has completed successfully.
             */
            if (
                $newFeaturedImage !== ''
                && $oldFeaturedImage !== ''
                && $oldFeaturedImage !== $newFeaturedImage
            ) {
                blog_delete_uploaded_file(
                    $oldFeaturedImage
                );
            }

            $message = match ($status) {
                'draft' => 'Article saved as draft.',
                'published' => 'Article published successfully.',
                'archived' => 'Article archived successfully.',
                default => 'Article updated successfully.',
            };

            blog_flash_success($message);
            redirect_blogs();
        }

        /*
        |--------------------------------------------------------------------------
        | Insert article
        |--------------------------------------------------------------------------
        */

        if ($hasBuilderColumn) {
            $statement = $conn->prepare(
                'INSERT INTO blogs (
                    title,
                    slug,
                    excerpt,
                    content,
                    content_builder,
                    featured_image,
                    author,
                    category,
                    status,
                    published_at,
                    sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$statement) {
                throw new RuntimeException(
                    'Database error: ' . $conn->error
                );
            }

            $statement->bind_param(
                'ssssssssssi',
                $title,
                $slug,
                $excerpt,
                $content,
                $contentBuilder,
                $featuredImage,
                $author,
                $category,
                $status,
                $publishedAt,
                $sortOrder
            );
        } else {
            $statement = $conn->prepare(
                'INSERT INTO blogs (
                    title,
                    slug,
                    excerpt,
                    content,
                    featured_image,
                    author,
                    category,
                    status,
                    published_at,
                    sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$statement) {
                throw new RuntimeException(
                    'Database error: ' . $conn->error
                );
            }

            $statement->bind_param(
                'sssssssssi',
                $title,
                $slug,
                $excerpt,
                $content,
                $featuredImage,
                $author,
                $category,
                $status,
                $publishedAt,
                $sortOrder
            );
        }

        if (!$statement->execute()) {
            $statementError = $statement->error;
            $statement->close();

            throw new RuntimeException(
                'Failed to create article: ' . $statementError
            );
        }

        $statement->close();

        $conn->commit();

        $message = match ($status) {
            'draft' => 'Article saved as draft.',
            'published' => 'Article published successfully.',
            'archived' => 'Article created and archived successfully.',
            default => 'Article created successfully.',
        };

        blog_flash_success($message);
        redirect_blogs();
    } catch (Throwable $exception) {
        $conn->rollback();

        /*
         * Remove only the newly uploaded featured image.
         * Never remove the existing image after a failed update.
         */
        if ($newFeaturedImage !== '') {
            blog_delete_uploaded_file(
                $newFeaturedImage
            );
        }

        blog_flash_error(
            $exception->getMessage()
        );

        redirect_blogs();
    }
}

/*
|--------------------------------------------------------------------------
| Delete article
|--------------------------------------------------------------------------
*/

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id <= 0) {
        blog_flash_error('Invalid article.');
        redirect_blogs();
    }

    $conn->begin_transaction();

    try {
        $statement = $conn->prepare(
            'SELECT featured_image
             FROM blogs
             WHERE id = ?
             LIMIT 1'
        );

        if (!$statement) {
            throw new RuntimeException(
                'Database error: ' . $conn->error
            );
        }

        $statement->bind_param(
            'i',
            $id
        );

        if (!$statement->execute()) {
            $statementError = $statement->error;
            $statement->close();

            throw new RuntimeException(
                'Failed to load article: ' . $statementError
            );
        }

        $result = $statement->get_result();

        $article = (
            $result instanceof mysqli_result
        ) ? $result->fetch_assoc() : null;

        $statement->close();

        if (!$article) {
            throw new RuntimeException(
                'The selected article was not found.'
            );
        }

        $featuredImage = trim(
            (string) ($article['featured_image'] ?? '')
        );

        $statement = $conn->prepare(
            'DELETE FROM blogs
             WHERE id = ?
             LIMIT 1'
        );

        if (!$statement) {
            throw new RuntimeException(
                'Database error: ' . $conn->error
            );
        }

        $statement->bind_param(
            'i',
            $id
        );

        if (!$statement->execute()) {
            $statementError = $statement->error;
            $statement->close();

            throw new RuntimeException(
                'Failed to delete article: ' . $statementError
            );
        }

        $statement->close();

        $conn->commit();

        if ($featuredImage !== '') {
            blog_delete_uploaded_file(
                $featuredImage
            );
        }

        blog_flash_success(
            'Article deleted successfully.'
        );

        redirect_blogs();
    } catch (Throwable $exception) {
        $conn->rollback();

        blog_flash_error(
            $exception->getMessage()
        );

        redirect_blogs();
    }
}

/*
|--------------------------------------------------------------------------
| Invalid request
|--------------------------------------------------------------------------
*/

blog_flash_error('Invalid request.');
redirect_blogs();

