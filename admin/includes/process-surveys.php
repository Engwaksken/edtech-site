<?php
declare(strict_types=1);

require_once '../../includes/config.php';
require_once 'auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');


function survey_flash_redirect(string $url, string $message = '', string $type = 'success'): void
{
    if ($message !== '' && function_exists('flash')) {
        flash('surveys', $message, $type);
    } elseif ($message !== '') {
        $_SESSION['surveys_flash'] = [
            'msg'  => $message,
            'type' => $type
        ];
    }

    header('Location: ' . $url);
    exit;
}

function survey_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function survey_json_response(bool $success, string $message = '', array $extra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'error'   => $success ? '' : $message,
    ], $extra));
    exit;
}

function survey_clean(?string $value): string
{
    return trim((string)$value);
}

function survey_nullable_datetime(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $time = strtotime(str_replace('T', ' ', $value));

    if ($time === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $time);
}

function survey_slug(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
    return $slug !== '' ? $slug : 'survey';
}

function survey_token(): string
{
    return bin2hex(random_bytes(24));
}

function survey_redirect_back_to_builder(int $survey_id, string $message, string $type = 'success'): void
{
    if (survey_is_ajax()) {
        survey_json_response($type !== 'error', $message, ['survey_id' => $survey_id]);
    }

    survey_flash_redirect('../survey-builder.php?id=' . $survey_id, $message, $type);
}

function survey_upload_base_dir(): string
{
    return rtrim(__DIR__ . '/../../uploads/surveys', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

function survey_upload_base_url(): string
{
    return '/uploads/surveys/';
}

function survey_ensure_upload_dir(): string
{
    $dir = survey_upload_base_dir();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }

    if (!is_writable($dir)) {
        throw new Exception('Upload directory is not writable.');
    }

    return $dir;
}

function survey_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
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
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function survey_ensure_column(mysqli $conn, string $table, string $column, string $definition): void
{
    if (survey_column_exists($conn, $table, $column)) {
        return;
    }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

    if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) {
        throw new Exception("Failed to add missing column {$table}.{$column}: " . $conn->error);
    }
}

function survey_ensure_conditional_columns(mysqli $conn): void
{
    survey_ensure_column($conn, 'survey_questions', 'conditional_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
    survey_ensure_column($conn, 'survey_questions', 'conditional_question_id', "INT NULL DEFAULT NULL AFTER `conditional_enabled`");
    survey_ensure_column($conn, 'survey_questions', 'conditional_operator', "VARCHAR(30) NULL DEFAULT NULL AFTER `conditional_question_id`");
    survey_ensure_column($conn, 'survey_questions', 'conditional_value', "VARCHAR(255) NULL DEFAULT NULL AFTER `conditional_operator`");
    survey_ensure_column($conn, 'survey_questions', 'conditional_action', "VARCHAR(20) NOT NULL DEFAULT 'show' AFTER `conditional_value`");
}

function survey_delete_old_header_image(mysqli $conn, int $survey_id): void
{
    $stmt = $conn->prepare("SELECT header_image FROM surveys WHERE id = ? LIMIT 1");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $survey_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['header_image'])) {
        return;
    }

    $old_file = survey_upload_base_dir() . basename((string)$row['header_image']);

    if (is_file($old_file)) {
        @unlink($old_file);
    }
}

function survey_upload_header_image(mysqli $conn, int $survey_id, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed. Please select a valid image.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Header image must be under 5 MB.');
    }

    $tmp = (string)$file['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        throw new Exception('Invalid uploaded file.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, WebP, or GIF images are allowed.');
    }

    $upload_dir = survey_ensure_upload_dir();
    survey_delete_old_header_image($conn, $survey_id);

    $filename = 'survey_header_' . $survey_id . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $dest = $upload_dir . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        throw new Exception('Could not save uploaded image.');
    }

    return survey_upload_base_url() . $filename;
}

function survey_unique_slug(mysqli $conn, string $title, int $ignore_id = 0): string
{
    $base = survey_slug($title);
    $slug = $base;
    $i = 1;

    while (true) {
        if ($ignore_id > 0) {
            $stmt = $conn->prepare("SELECT id FROM surveys WHERE slug = ? AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $slug, $ignore_id);
        } else {
            $stmt = $conn->prepare("SELECT id FROM surveys WHERE slug = ? LIMIT 1");
            $stmt->bind_param('s', $slug);
        }

        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $slug;
        }

        $slug = $base . '-' . $i++;
    }
}

function survey_allowed_field_types(): array
{
    return [
        'short_text',
        'long_text',
        'email',
        'phone',
        'number',
        'currency',
        'date',
        'single_choice',
        'multiple_choice',
        'dropdown',
        'yes_no',
        'rating',
        'linear_scale',
        'file_upload',
        'consent',
    ];
}

function survey_normalise_file_types($raw): string
{
    $valid = ['images', 'documents', 'spreadsheets', 'videos', 'audio', 'any'];

    if (is_array($raw)) {
        $cleaned = array_filter(array_map('trim', $raw), fn($v) => in_array($v, $valid, true));
        return implode(',', array_unique($cleaned));
    }

    if (is_string($raw) && trim($raw) !== '') {
        $parts = explode(',', $raw);
        $cleaned = array_filter(array_map('trim', $parts), fn($v) => in_array($v, $valid, true));
        return implode(',', array_unique($cleaned));
    }

    return '';
}

function survey_data_value(array $data, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }

    return $default;
}

function survey_normalise_condition_operator(string $operator): string
{
    $operator = trim($operator);

    $map = [
        '='  => 'equals',
        '==' => 'equals',
        '!=' => 'not_equals',
        '<>' => 'not_equals',
        '>=' => 'greater_equal',
        '<=' => 'less_equal',
        '>'  => 'greater_than',
        '<'  => 'less_than',
    ];

    if (isset($map[$operator])) {
        return $map[$operator];
    }

    $allowed = [
        'equals',
        'not_equals',
        'greater_than',
        'less_than',
        'greater_equal',
        'less_equal',
        'contains',
        'not_contains',
    ];

    return in_array($operator, $allowed, true) ? $operator : 'equals';
}

function survey_normalise_condition_value(string $value): string
{
    $value = trim($value);

    if (strcasecmp($value, 'yes') === 0) {
        return 'Yes';
    }

    if (strcasecmp($value, 'no') === 0) {
        return 'No';
    }

    return $value;
}

function survey_normalise_condition_action(string $action): string
{
    $action = strtolower(trim($action));
    return in_array($action, ['show', 'hide'], true) ? $action : 'show';
}

function survey_validate_parent_question(mysqli $conn, int $survey_id, int $parent_question_id, int $current_question_id = 0): void
{
    if ($parent_question_id <= 0) {
        throw new Exception('Conditional question is enabled, but no parent question was selected.');
    }

    if ($current_question_id > 0 && $parent_question_id === $current_question_id) {
        throw new Exception('A question cannot depend on itself.');
    }

    $stmt = $conn->prepare("
        SELECT id, field_type
        FROM survey_questions
        WHERE id = ?
          AND survey_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('DB error: ' . $conn->error);
    }

    $stmt->bind_param('ii', $parent_question_id, $survey_id);
    $stmt->execute();
    $parent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$parent) {
        throw new Exception('Selected conditional parent question was not found.');
    }

    $allowed_parent_types = ['yes_no', 'rating', 'linear_scale', 'single_choice', 'dropdown', 'multiple_choice', 'number'];

    if (!in_array((string)$parent['field_type'], $allowed_parent_types, true)) {
        throw new Exception('Conditional parent must be Yes/No, 1–5 scale/rating, choice, dropdown, or number.');
    }
}

function survey_normalise_condition_data(mysqli $conn, array $data, int $survey_id, int $current_question_id = 0): array
{
    survey_ensure_conditional_columns($conn);

    $enabled_raw = survey_data_value($data, [
        'conditional_enabled',
        'has_condition',
        'enable_condition',
        'is_conditional',
        'condition_enabled',
    ], '');

    $parent_raw = survey_data_value($data, [
        'conditional_question_id',
        'condition_question_id',
        'parent_question_id',
        'show_if_question_id',
        'conditional_parent_id',
    ], 0);

    $operator_raw = survey_data_value($data, [
        'conditional_operator',
        'condition_operator',
        'show_if_operator',
    ], 'equals');

    $value_raw = survey_data_value($data, [
        'conditional_value',
        'condition_value',
        'show_if_value',
    ], '');

    $action_raw = survey_data_value($data, [
        'conditional_action',
        'condition_action',
        'show_if_action',
    ], 'show');

    $parent_question_id = (int)$parent_raw;
    $condition_value = survey_normalise_condition_value((string)$value_raw);

    $enabled = !empty($enabled_raw) || ($parent_question_id > 0 && $condition_value !== '');

    if (!$enabled) {
        return [
            'enabled'     => 0,
            'question_id' => null,
            'operator'    => null,
            'value'       => null,
            'action'      => 'show',
        ];
    }

    if ($condition_value === '') {
        throw new Exception('Conditional question is enabled, but no condition value was provided.');
    }

    survey_validate_parent_question($conn, $survey_id, $parent_question_id, $current_question_id);

    return [
        'enabled'     => 1,
        'question_id' => $parent_question_id,
        'operator'    => survey_normalise_condition_operator((string)$operator_raw),
        'value'       => $condition_value,
        'action'      => survey_normalise_condition_action((string)$action_raw),
    ];
}

function survey_save_question_options(mysqli $conn, int $question_id, string $field_type, string $options_text): void
{
    $delete = $conn->prepare("DELETE FROM survey_question_options WHERE question_id = ?");
    if (!$delete) {
        throw new Exception('DB error: ' . $conn->error);
    }

    $delete->bind_param('i', $question_id);
    $delete->execute();
    $delete->close();

    $auto_options = [];

    if ($field_type === 'yes_no') {
        $auto_options = ['Yes', 'No'];
    }

    if (in_array($field_type, ['single_choice', 'multiple_choice', 'dropdown'], true)) {
        $options_text = trim($options_text);

        if ($options_text !== '') {
            $auto_options = preg_split('/\r\n|\r|\n/', $options_text) ?: [];
        }
    }

    if (empty($auto_options)) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO survey_question_options
            (question_id, option_label, option_value, sort_order)
        VALUES
            (?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('DB error: ' . $conn->error);
    }

    $sort = 1;

    foreach ($auto_options as $line) {
        $label = trim((string)$line);

        if ($label === '') {
            continue;
        }

        $value = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));

        if ($value === '') {
            $value = 'option_' . $sort;
        }

        if ($field_type === 'yes_no') {
            $value = $label;
        }

        $stmt->bind_param('issi', $question_id, $label, $value, $sort);
        $stmt->execute();

        $sort++;
    }

    $stmt->close();
}

function survey_save_single_question(mysqli $conn, array $data): int
{
    survey_ensure_conditional_columns($conn);

    $id = (int)($data['id'] ?? 0);
    $survey_id = (int)($data['survey_id'] ?? 0);

    $section_title = survey_clean((string)($data['section_title'] ?? ''));
    $question_text = survey_clean((string)($data['question_text'] ?? ''));
    $help_text = survey_clean((string)($data['help_text'] ?? ''));
    $field_type = survey_clean((string)($data['field_type'] ?? 'short_text'));

    $is_required = !empty($data['is_required']) ? 1 : 0;
    $sort_order = (int)($data['sort_order'] ?? 0);
    $status = isset($data['status']) ? (int)$data['status'] : 1;

    $scale_min = isset($data['scale_min']) && $data['scale_min'] !== '' ? (int)$data['scale_min'] : null;
    $scale_max = isset($data['scale_max']) && $data['scale_max'] !== '' ? (int)$data['scale_max'] : null;

    $scale_min_label = survey_clean((string)($data['scale_min_label'] ?? ''));
    $scale_max_label = survey_clean((string)($data['scale_max_label'] ?? ''));
    $options_text = (string)($data['options_text'] ?? '');

    $file_allowed_types = '';
    $file_max_size_mb = null;
    $file_max_count = null;

    if ($survey_id <= 0) {
        throw new Exception('Invalid survey selected.');
    }

    if ($question_text === '') {
        throw new Exception('Question text is required.');
    }

    if (!in_array($field_type, survey_allowed_field_types(), true)) {
        $field_type = 'short_text';
    }

    if ($field_type === 'linear_scale' || $field_type === 'rating') {
        $scale_min = $scale_min ?? 1;
        $scale_max = $scale_max ?? 5;

        if ($scale_min < 0) {
            $scale_min = 1;
        }

        if ($scale_max <= $scale_min) {
            $scale_max = 5;
        }

        if ($scale_max > 10) {
            $scale_max = 10;
        }
    } else {
        $scale_min = null;
        $scale_max = null;
        $scale_min_label = '';
        $scale_max_label = '';
    }

    if ($field_type === 'yes_no') {
        $options_text = "Yes\nNo";
    }

    if ($field_type === 'file_upload') {
        $file_allowed_types = survey_normalise_file_types($data['file_allowed_types'] ?? '');

        if ($file_allowed_types === '') {
            $file_allowed_types = 'any';
        }

        $file_max_size_mb = isset($data['file_max_size_mb']) && $data['file_max_size_mb'] !== ''
            ? max(1, (int)$data['file_max_size_mb'])
            : 10;

        $file_max_count = isset($data['file_max_count']) && $data['file_max_count'] !== ''
            ? max(1, (int)$data['file_max_count'])
            : 1;
    }

    $condition = survey_normalise_condition_data($conn, $data, $survey_id, $id);

    $conditional_enabled = (int)$condition['enabled'];
    $conditional_question_id = $condition['question_id'];
    $conditional_operator = $condition['operator'];
    $conditional_value = $condition['value'];
    $conditional_action = $condition['action'];

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE survey_questions SET
                section_title = ?,
                question_text = ?,
                help_text = ?,
                field_type = ?,
                is_required = ?,
                sort_order = ?,
                scale_min = ?,
                scale_max = ?,
                scale_min_label = ?,
                scale_max_label = ?,
                status = ?,
                file_allowed_types = ?,
                file_max_size_mb = ?,
                file_max_count = ?,
                conditional_enabled = ?,
                conditional_question_id = ?,
                conditional_operator = ?,
                conditional_value = ?,
                conditional_action = ?
            WHERE id = ? AND survey_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param(
            'ssssiiiissisiiiisssii',
            $section_title,
            $question_text,
            $help_text,
            $field_type,
            $is_required,
            $sort_order,
            $scale_min,
            $scale_max,
            $scale_min_label,
            $scale_max_label,
            $status,
            $file_allowed_types,
            $file_max_size_mb,
            $file_max_count,
            $conditional_enabled,
            $conditional_question_id,
            $conditional_operator,
            $conditional_value,
            $conditional_action,
            $id,
            $survey_id
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to update question: ' . $stmt->error);
        }

        $stmt->close();

        $question_id = $id;
    } else {
        $stmt = $conn->prepare("
            INSERT INTO survey_questions
            (
                survey_id,
                section_title,
                question_text,
                help_text,
                field_type,
                is_required,
                sort_order,
                scale_min,
                scale_max,
                scale_min_label,
                scale_max_label,
                status,
                file_allowed_types,
                file_max_size_mb,
                file_max_count,
                conditional_enabled,
                conditional_question_id,
                conditional_operator,
                conditional_value,
                conditional_action
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param(
            'issssiiiissisiiiisss',
            $survey_id,
            $section_title,
            $question_text,
            $help_text,
            $field_type,
            $is_required,
            $sort_order,
            $scale_min,
            $scale_max,
            $scale_min_label,
            $scale_max_label,
            $status,
            $file_allowed_types,
            $file_max_size_mb,
            $file_max_count,
            $conditional_enabled,
            $conditional_question_id,
            $conditional_operator,
            $conditional_value,
            $conditional_action
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to add question: ' . $stmt->error);
        }

        $question_id = (int)$stmt->insert_id;
        $stmt->close();
    }

    survey_save_question_options($conn, $question_id, $field_type, $options_text);

    return $question_id;
}

function survey_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        survey_flash_redirect('../surveys.php', 'Invalid request.', 'error');
    }
}

survey_require_post();

$action = (string)($_POST['action'] ?? '');

try {
    survey_ensure_conditional_columns($conn);

    if ($action === 'save_survey') {
        $id = (int)($_POST['id'] ?? 0);

        $title = survey_clean((string)($_POST['title'] ?? ''));
        $description = survey_clean((string)($_POST['description'] ?? ''));
        $welcome_text = survey_clean((string)($_POST['welcome_text'] ?? ''));
        $thank_you_text = survey_clean((string)($_POST['thank_you_text'] ?? ''));
        $status = survey_clean((string)($_POST['status'] ?? 'draft'));

        $allow_multiple = isset($_POST['allow_multiple_responses']) ? 1 : 0;
        $require_login = isset($_POST['require_venture_login']) ? 1 : 0;

        $starts_at = survey_nullable_datetime($_POST['starts_at'] ?? null);
        $ends_at = survey_nullable_datetime($_POST['ends_at'] ?? null);

        $theme_color = survey_clean((string)($_POST['theme_color'] ?? '#6c47ff'));
        $header_style = survey_clean((string)($_POST['header_style'] ?? 'color'));

        if ($title === '') {
            survey_flash_redirect('../surveys.php', 'Survey title is required.', 'error');
        }

        if (!in_array($status, ['draft', 'published', 'closed'], true)) {
            $status = 'draft';
        }

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $theme_color)) {
            $theme_color = '#6c47ff';
        }

        if (!in_array($header_style, ['color', 'image'], true)) {
            $header_style = 'color';
        }

        if ($id > 0) {
            $slug = survey_unique_slug($conn, $title, $id);

            $image_path = isset($_FILES['header_image'])
                ? survey_upload_header_image($conn, $id, $_FILES['header_image'])
                : '';

            if ($image_path !== '') {
                $header_style = 'image';

                $stmt = $conn->prepare("
                    UPDATE surveys SET
                        title = ?,
                        slug = ?,
                        description = ?,
                        welcome_text = ?,
                        thank_you_text = ?,
                        status = ?,
                        allow_multiple_responses = ?,
                        require_venture_login = ?,
                        starts_at = ?,
                        ends_at = ?,
                        theme_color = ?,
                        header_style = ?,
                        header_image = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception('DB error: ' . $conn->error);
                }

                $stmt->bind_param(
                    'ssssssiisssssi',
                    $title,
                    $slug,
                    $description,
                    $welcome_text,
                    $thank_you_text,
                    $status,
                    $allow_multiple,
                    $require_login,
                    $starts_at,
                    $ends_at,
                    $theme_color,
                    $header_style,
                    $image_path,
                    $id
                );
            } else {
                $stmt = $conn->prepare("
                    UPDATE surveys SET
                        title = ?,
                        slug = ?,
                        description = ?,
                        welcome_text = ?,
                        thank_you_text = ?,
                        status = ?,
                        allow_multiple_responses = ?,
                        require_venture_login = ?,
                        starts_at = ?,
                        ends_at = ?,
                        theme_color = ?,
                        header_style = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception('DB error: ' . $conn->error);
                }

                $stmt->bind_param(
                    'ssssssiissssi',
                    $title,
                    $slug,
                    $description,
                    $welcome_text,
                    $thank_you_text,
                    $status,
                    $allow_multiple,
                    $require_login,
                    $starts_at,
                    $ends_at,
                    $theme_color,
                    $header_style,
                    $id
                );
            }

            if (!$stmt->execute()) {
                throw new Exception('Failed to update survey: ' . $stmt->error);
            }

            $stmt->close();

            survey_flash_redirect('../surveys.php', 'Survey updated successfully.');
        }

        $slug = survey_unique_slug($conn, $title);
        $token = survey_token();
        $created_by = (int)($_SESSION['admin_id'] ?? ($ADMIN['id'] ?? 0));

        $stmt = $conn->prepare("
            INSERT INTO surveys
            (
                title,
                slug,
                description,
                welcome_text,
                thank_you_text,
                external_token,
                status,
                allow_multiple_responses,
                require_venture_login,
                starts_at,
                ends_at,
                created_by,
                theme_color,
                header_style,
                created_at,
                updated_at
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param(
            'sssssssiississ',
            $title,
            $slug,
            $description,
            $welcome_text,
            $thank_you_text,
            $token,
            $status,
            $allow_multiple,
            $require_login,
            $starts_at,
            $ends_at,
            $created_by,
            $theme_color,
            $header_style
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to create survey: ' . $stmt->error);
        }

        $new_id = (int)$stmt->insert_id;
        $stmt->close();

        if (isset($_FILES['header_image'])) {
            $image_path = survey_upload_header_image($conn, $new_id, $_FILES['header_image']);

            if ($image_path !== '') {
                $stmt = $conn->prepare("
                    UPDATE surveys
                    SET header_image = ?, header_style = 'image', updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception('DB error: ' . $conn->error);
                }

                $stmt->bind_param('si', $image_path, $new_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        survey_flash_redirect('../survey-builder.php?id=' . $new_id, 'Survey created successfully. Add questions now.');
    }

    if ($action === 'save_survey_appearance') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);
        $theme_color = survey_clean((string)($_POST['theme_color'] ?? '#6c47ff'));
        $header_style = survey_clean((string)($_POST['header_style'] ?? 'color'));

        if ($survey_id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid survey.', 'error');
        }

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $theme_color)) {
            $theme_color = '#6c47ff';
        }

        if (!in_array($header_style, ['color', 'image'], true)) {
            $header_style = 'color';
        }

        $image_path = isset($_FILES['header_image'])
            ? survey_upload_header_image($conn, $survey_id, $_FILES['header_image'])
            : '';

        if ($image_path !== '') {
            $header_style = 'image';

            $stmt = $conn->prepare("
                UPDATE surveys
                SET theme_color = ?, header_style = ?, header_image = ?, updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('DB error: ' . $conn->error);
            }

            $stmt->bind_param('sssi', $theme_color, $header_style, $image_path, $survey_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE surveys
                SET theme_color = ?, header_style = ?, updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('DB error: ' . $conn->error);
            }

            $stmt->bind_param('ssi', $theme_color, $header_style, $survey_id);
        }

        if (!$stmt->execute()) {
            throw new Exception('Failed to save survey appearance: ' . $stmt->error);
        }

        $stmt->close();

        survey_redirect_back_to_builder($survey_id, 'Survey appearance saved successfully.');
    }

    if ($action === 'save_survey_header_image') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        if ($survey_id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid survey.', 'error');
        }

        if (!isset($_FILES['header_image'])) {
            survey_redirect_back_to_builder($survey_id, 'No image selected.', 'error');
        }

        $image_path = survey_upload_header_image($conn, $survey_id, $_FILES['header_image']);

        if ($image_path === '') {
            survey_redirect_back_to_builder($survey_id, 'No image selected.', 'error');
        }

        $stmt = $conn->prepare("
            UPDATE surveys
            SET header_image = ?, header_style = 'image', updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param('si', $image_path, $survey_id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to save image: ' . $stmt->error);
        }

        $stmt->close();

        survey_redirect_back_to_builder($survey_id, 'Header image uploaded successfully.');
    }

    if ($action === 'remove_survey_header_image') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        if ($survey_id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid survey.', 'error');
        }

        survey_delete_old_header_image($conn, $survey_id);

        $stmt = $conn->prepare("
            UPDATE surveys
            SET header_image = NULL, header_style = 'color', updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param('i', $survey_id);
        $stmt->execute();
        $stmt->close();

        survey_redirect_back_to_builder($survey_id, 'Header image removed successfully.');
    }

    if ($action === 'delete_survey') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid survey selected.', 'error');
        }

        survey_delete_old_header_image($conn, $id);

        $stmt = $conn->prepare("DELETE FROM surveys WHERE id = ? LIMIT 1");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            throw new Exception('Delete failed: ' . $stmt->error);
        }

        $stmt->close();

        survey_flash_redirect('../surveys.php', 'Survey deleted successfully.');
    }

    if ($action === 'save_question') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        $question_id = survey_save_single_question($conn, $_POST);

        survey_redirect_back_to_builder($survey_id, 'Question saved successfully.', 'success');
    }

    if ($action === 'save_multiple_questions') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        if ($survey_id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid survey selected.', 'error');
        }

        $questions = $_POST['questions'] ?? [];

        if (!is_array($questions) || empty($questions)) {
            survey_redirect_back_to_builder($survey_id, 'No questions submitted.', 'error');
        }

        $conn->begin_transaction();

        $saved = 0;

        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $question['survey_id'] = $survey_id;

            if (!isset($question['sort_order']) || $question['sort_order'] === '') {
                $question['sort_order'] = $index + 1;
            }

            if (trim((string)($question['question_text'] ?? '')) === '') {
                continue;
            }

            survey_save_single_question($conn, $question);
            $saved++;
        }

        if ($saved <= 0) {
            $conn->rollback();
            survey_redirect_back_to_builder($survey_id, 'Please add at least one valid question.', 'error');
        }

        $conn->commit();

        survey_redirect_back_to_builder($survey_id, $saved . ' question(s) saved successfully.');
    }

    if ($action === 'reorder_questions') {
        $survey_id = (int)($_POST['survey_id'] ?? 0);
        $order_json = (string)($_POST['order_json'] ?? '');
        $order = json_decode($order_json, true);

        if ($survey_id <= 0) {
            survey_json_response(false, 'Invalid survey selected.');
        }

        if (!is_array($order) || empty($order)) {
            survey_json_response(false, 'No question order was submitted.');
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            UPDATE survey_questions
            SET sort_order = ?
            WHERE id = ? AND survey_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $position = 1;
        foreach ($order as $item) {
            $question_id = (int)($item['id'] ?? 0);
            if ($question_id <= 0) {
                continue;
            }

            $sort_order = $position++;
            $stmt->bind_param('iii', $sort_order, $question_id, $survey_id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to save question order: ' . $stmt->error);
            }
        }

        $stmt->close();
        $conn->commit();

        survey_json_response(true, 'Question order saved successfully.');
    }

    if ($action === 'delete_question') {
        $id = (int)($_POST['id'] ?? 0);
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        if ($id <= 0 || $survey_id <= 0) {
            survey_flash_redirect('../surveys.php', 'Invalid question selected.', 'error');
        }

        $stmt = $conn->prepare("DELETE FROM survey_questions WHERE id = ? AND survey_id = ? LIMIT 1");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param('ii', $id, $survey_id);
        $stmt->execute();
        $stmt->close();

        survey_redirect_back_to_builder($survey_id, 'Question deleted successfully.');
    }

    if ($action === 'delete_response') {
        $id = (int)($_POST['id'] ?? 0);
        $survey_id = (int)($_POST['survey_id'] ?? 0);

        if ($id <= 0 || $survey_id <= 0) {
            survey_flash_redirect('../survey-responses.php?id=' . $survey_id, 'Invalid response.', 'error');
        }

        $del = $conn->prepare("DELETE FROM survey_response_answers WHERE response_id = ?");

        if (!$del) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $del->bind_param('i', $id);
        $del->execute();
        $del->close();

        $stmt = $conn->prepare("DELETE FROM survey_responses WHERE id = ? AND survey_id = ? LIMIT 1");

        if (!$stmt) {
            throw new Exception('DB error: ' . $conn->error);
        }

        $stmt->bind_param('ii', $id, $survey_id);
        $stmt->execute();
        $stmt->close();

        survey_flash_redirect('../survey-responses.php?id=' . $survey_id, 'Response deleted successfully.');
    }

    survey_flash_redirect('../surveys.php', 'Unknown action.', 'error');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    if (survey_is_ajax()) {
        survey_json_response(false, $e->getMessage());
    }

    $survey_id = (int)($_POST['survey_id'] ?? $_POST['id'] ?? 0);
    $url = $survey_id > 0 ? '../survey-builder.php?id=' . $survey_id : '../surveys.php';

    survey_flash_redirect($url, $e->getMessage(), 'error');
}
