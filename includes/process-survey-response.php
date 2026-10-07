<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Nairobi');

if (!isset($conn) || !($conn instanceof mysqli)) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'Database connection failed.',
        'errors' => ['Database connection failed.']
    ]);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function psr_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi'));
}

function psr_now_sql(): string
{
    return psr_now()->format('Y-m-d H:i:s');
}

function psr_now_readable(): string
{
    return psr_now()->format('F j, Y \a\t g:i A');
}

function psr_now_file(): string
{
    return psr_now()->format('YmdHis');
}

function psr_emit_json(bool $ok, array $data, int $status): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[process-survey-response] Uncaught: ' . $e->getMessage());

    psr_emit_json(false, [
        'message' => 'Submission failed due to a server error. Please try again.',
        'errors'  => [$e->getMessage()],
    ], 500);
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[process-survey-response] Fatal: ' . $error['message']);

        psr_emit_json(false, [
            'message' => 'Submission failed due to a server error. Please try again.',
            'errors'  => [$error['message']],
        ], 500);
    }
});

function psr_is_ajax(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains($accept, 'application/json')
        || isset($_SERVER['HTTP_SEC_FETCH_MODE']);
}

function psr_json(bool $ok, array $data = [], int $status = 200): void
{
    psr_emit_json($ok, $data, $status);
}

function psr_redirect(string $url): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Location: ' . $url);
    exit;
}

function psr_back_url(string $token): string
{
    return '../survey.php?t=' . urlencode($token);
}

function psr_fail(string $message, string $token = '', int $status = 422): void
{
    error_log('[process-survey-response] ' . $message);

    if (psr_is_ajax()) {
        psr_json(false, [
            'message' => $message,
            'errors'  => [$message],
        ], $status);
    }

    $_SESSION['survey_error'] = $message;
    psr_redirect($token !== '' ? psr_back_url($token) : '../');
}

function psr_post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function psr_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $value = trim((string)$_SERVER[$key]);

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            $value = trim((string)($parts[0] ?? ''));
        }

        if ($value !== '') {
            return substr($value, 0, 45);
        }
    }

    return '';
}

function psr_random_token(): string
{
    return bin2hex(random_bytes(24));
}

function psr_value_for_question(int $question_id): mixed
{
    foreach (['q_' . $question_id, 'question_' . $question_id, 'answer_' . $question_id] as $key) {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
    }

    foreach (['answers', 'responses', 'q'] as $group) {
        if (
            isset($_POST[$group])
            && is_array($_POST[$group])
            && array_key_exists($question_id, $_POST[$group])
        ) {
            return $_POST[$group][$question_id];
        }
    }

    return null;
}

function psr_string_answers(mixed $raw): array
{
    if ($raw === null) {
        return [];
    }

    if (is_array($raw)) {
        $answers = [];

        foreach ($raw as $item) {
            if (is_array($item)) {
                $item = json_encode($item, JSON_UNESCAPED_SLASHES);
            }

            $item = trim((string)$item);

            if ($item !== '') {
                $answers[] = $item;
            }
        }

        return $answers;
    }

    $raw = trim((string)$raw);

    return $raw === '' ? [] : [$raw];
}

function psr_nested_file(array $files, string $key): ?array
{
    if (
        !isset($files['name'])
        || !is_array($files['name'])
        || !array_key_exists($key, $files['name'])
    ) {
        return null;
    }

    return [
        'name'     => $files['name'][$key],
        'type'     => $files['type'][$key] ?? '',
        'tmp_name' => $files['tmp_name'][$key] ?? '',
        'error'    => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $files['size'][$key] ?? 0,
    ];
}

function psr_file_for_question(int $question_id): ?array
{
    foreach (['q_' . $question_id, 'question_' . $question_id, 'answer_' . $question_id] as $key) {
        if (isset($_FILES[$key])) {
            return $_FILES[$key];
        }
    }

    foreach (['answers', 'responses', 'q'] as $group) {
        if (isset($_FILES[$group])) {
            $file = psr_nested_file($_FILES[$group], (string)$question_id);

            if ($file !== null) {
                return $file;
            }
        }
    }

    return null;
}

function psr_normalise_file_entry(array $file_entry): array
{
    $files = [];

    if (is_array($file_entry['name'] ?? null)) {
        foreach ($file_entry['name'] as $i => $name) {
            $files[] = [
                'name'     => $name,
                'type'     => $file_entry['type'][$i] ?? '',
                'tmp_name' => $file_entry['tmp_name'][$i] ?? '',
                'error'    => $file_entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $file_entry['size'][$i] ?? 0,
            ];
        }
    } else {
        $files[] = [
            'name'     => $file_entry['name'] ?? '',
            'type'     => $file_entry['type'] ?? '',
            'tmp_name' => $file_entry['tmp_name'] ?? '',
            'error'    => $file_entry['error'] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $file_entry['size'] ?? 0,
        ];
    }

    return array_values(array_filter($files, static function (array $file): bool {
        return (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && trim((string)($file['name'] ?? '')) !== '';
    }));
}

function psr_upload_base_dir(int $survey_id): string
{
    return dirname(__DIR__) . '/uploads/responses/' . $survey_id . '/';
}

function psr_upload_base_url(int $survey_id): string
{
    return 'uploads/responses/' . $survey_id . '/';
}

function psr_ensure_upload_dir(int $survey_id): string
{
    $dir = psr_upload_base_dir($survey_id);

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    return $dir;
}

function psr_allowed_mimes(): array
{
    return [
        'images' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ],
        'documents' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/vnd.oasis.opendocument.text',
            'application/rtf',
            'text/rtf',
        ],
        'spreadsheets' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/vnd.oasis.opendocument.spreadsheet',
        ],
        'videos' => [
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/webm',
            'video/ogg',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/webm',
            'audio/aac',
            'audio/flac',
        ],
    ];
}

function psr_mime_whitelist(string $types_csv): ?array
{
    $groups = array_filter(array_map('trim', explode(',', strtolower($types_csv ?: 'any'))));

    if (!$groups || in_array('any', $groups, true)) {
        return null;
    }

    $all = psr_allowed_mimes();
    $allowed = [];

    foreach ($groups as $group) {
        if (isset($all[$group])) {
            $allowed = array_merge($allowed, $all[$group]);
        }
    }

    return array_values(array_unique($allowed));
}

function psr_process_file_question(
    array $file_entry,
    int $survey_id,
    int $question_id,
    int $max_mb,
    int $max_count,
    string $allowed_types
): array {
    $files = psr_normalise_file_entry($file_entry);

    if (!$files) {
        return [];
    }

    if (count($files) > $max_count) {
        throw new RuntimeException("Question #{$question_id} allows a maximum of {$max_count} file(s).");
    }

    $upload_dir = psr_ensure_upload_dir($survey_id);
    $max_bytes = $max_mb * 1024 * 1024;
    $whitelist = psr_mime_whitelist($allowed_types);
    $saved_urls = [];

    foreach ($files as $file) {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for "' . $file['name'] . '".');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file: ' . $file['name']);
        }

        if ((int)$file['size'] > $max_bytes) {
            throw new RuntimeException('"' . $file['name'] . "\" exceeds the {$max_mb} MB upload limit.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';

        if ($finfo) {
            finfo_close($finfo);
        }

        if ($whitelist !== null && !in_array($mime, $whitelist, true)) {
            throw new RuntimeException('"' . $file['name'] . '" is not an allowed file type.');
        }

        $safe_original = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)$file['name']));
        $safe_original = substr((string)$safe_original, 0, 90);

        $filename = sprintf(
            'q%d_%s_%s_%s',
            $question_id,
            psr_now_file(),
            bin2hex(random_bytes(6)),
            $safe_original
        );

        $destination = $upload_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save uploaded file: ' . $file['name']);
        }

        $saved_urls[] = psr_upload_base_url($survey_id) . $filename;
    }

    return $saved_urls;
}

function psr_notification_roles(): array
{
    return [
        'super_admin',
        'admin',
        'meal_officer',
        'programs_lead',
        'program_director',
        'consultant',
    ];
}

function psr_get_notification_recipients(mysqli $conn): array
{
    $roles = psr_notification_roles();
    $recipients = [];

    try {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));

        $sql = "
            SELECT full_name, email, role
            FROM admin_users
            WHERE email IS NOT NULL
              AND TRIM(email) <> ''
              AND (status IS NULL OR status = 'active')
              AND LOWER(REPLACE(REPLACE(TRIM(role), '-', '_'), ' ', '_')) IN ($placeholders)
        ";

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($roles));
        $stmt->bind_param($types, ...$roles);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $email = trim((string)($row['email'] ?? ''));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[$email] = [
                    'full_name'  => (string)($row['full_name'] ?? ''),
                    'email' => $email,
                    'role'  => (string)($row['role'] ?? ''),
                ];
            }
        }

        $stmt->close();
    } catch (Throwable $e) {
        error_log('[process-survey-response] Notification recipient lookup failed: ' . $e->getMessage());
        return [];
    }

    return array_values($recipients);
}

function psr_notify_admins_of_response(
    mysqli $conn,
    array $survey,
    int $response_id,
    string $respondent_name,
    string $respondent_email,
    string $venture_name,
    string $submitted_at
): void {
    if (!function_exists('sendEmail')) {
        error_log('[process-survey-response] sendEmail() not available; skipping notification.');
        return;
    }

    $recipients = psr_get_notification_recipients($conn);

    if (!$recipients) {
        error_log('[process-survey-response] No notification recipients found for roles: ' . implode(', ', psr_notification_roles()));
        return;
    }

    $survey_title = (string)($survey['title'] ?? 'Survey');

    $safe_survey_title = htmlspecialchars($survey_title, ENT_QUOTES, 'UTF-8');
    $display_name       = $respondent_name !== '' ? $respondent_name : 'Anonymous';
    $display_email      = $respondent_email !== '' ? $respondent_email : 'Not provided';
    $display_venture    = $venture_name !== '' ? $venture_name : 'Not provided';

    $safe_name          = htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8');
    $safe_email         = htmlspecialchars($display_email, ENT_QUOTES, 'UTF-8');
    $safe_venture       = htmlspecialchars($display_venture, ENT_QUOTES, 'UTF-8');
    $safe_submitted_at  = htmlspecialchars($submitted_at, ENT_QUOTES, 'UTF-8');

    $content = "
        <h3>New Survey Response Received</h3>
        <p>A new response has just been submitted for the survey below.</p>

        <div class='info-box'>
            <p><strong>Survey:</strong> {$safe_survey_title}</p>
            <p><strong>Respondent:</strong> {$safe_name}</p>
            <p><strong>Email:</strong> {$safe_email}</p>
            <p><strong>Venture / Organisation:</strong> {$safe_venture}</p>
            <p><strong>Submitted:</strong> {$safe_submitted_at}</p>
            <p><strong>Timezone:</strong> Africa/Nairobi</p>
            <p><strong>Response ID:</strong> #{$response_id}</p>
        </div>

        <p>Please log in to the admin dashboard to review the full response.</p>
    ";

    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $subject = 'New Survey Response: ' . $survey_title;

    foreach ($recipients as $recipient) {
        try {
            $result = sendEmail($recipient['email'], $subject, $body);

            if ($result !== true) {
                error_log(
                    '[process-survey-response] Notification email failed for '
                    . $recipient['email']
                    . ': '
                    . (is_string($result) ? $result : 'unknown error')
                );
            }
        } catch (Throwable $e) {
            error_log('[process-survey-response] Notification email exception for ' . $recipient['email'] . ': ' . $e->getMessage());
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        psr_redirect('../');
    }

    if (
        empty($_POST)
        && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    ) {
        psr_fail('The upload was too large for the server to accept. Please reduce file sizes and try again.', '', 413);
    }

    $token = psr_post_string('survey_token');

    if ($token === '') {
        psr_fail('Invalid request. Missing survey token.', '', 400);
    }

    $stmt = $conn->prepare('SELECT * FROM surveys WHERE external_token = ? LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $survey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$survey) {
        psr_fail('Survey not found or invalid survey link.', $token, 404);
    }

    $survey_id = (int)$survey['id'];

    if (($survey['status'] ?? '') !== 'published') {
        psr_fail('This survey is not currently accepting responses.', $token, 403);
    }

    $nowTimestamp = psr_now()->getTimestamp();

    if (!empty($survey['starts_at']) && strtotime((string)$survey['starts_at']) > $nowTimestamp) {
        psr_fail('This survey is not open yet.', $token, 403);
    }

    if (!empty($survey['ends_at']) && strtotime((string)$survey['ends_at']) < $nowTimestamp) {
        psr_fail('This survey is already closed.', $token, 403);
    }

    $session_venture_id = !empty($_SESSION['venture_id']) ? (int)$_SESSION['venture_id'] : null;
    $requires_venture_login = (int)($survey['require_venture_login'] ?? 0) === 1;

    if ($requires_venture_login && !$session_venture_id) {
        psr_fail('You must login before submitting this survey.', $token, 403);
    }

    /*
     * Anonymous by default:
     * - Name, email and organisation are optional.
     * - Public surveys do not attach an existing venture session to a response.
     * - If venture login is explicitly required, the venture link is retained
     *   because authentication is part of the survey configuration.
     */
    $venture_id = $requires_venture_login ? $session_venture_id : null;

    $respondent_name  = psr_post_string('respondent_name');
    $respondent_email = strtolower(psr_post_string('respondent_email'));
    $venture_name     = psr_post_string('venture_name');

    if ($respondent_email !== '' && !filter_var($respondent_email, FILTER_VALIDATE_EMAIL)) {
        psr_fail('Please provide a valid email address or leave the email field blank.', $token, 422);
    }

    /*
     * Duplicate protection is only identity-based when identity is available.
     * Anonymous responses with no email cannot be deduplicated by email.
     */
    if ((int)($survey['allow_multiple_responses'] ?? 0) === 0) {
        if ($respondent_email !== '') {
            $dup = $conn->prepare('
                SELECT id
                FROM survey_responses
                WHERE survey_id = ?
                  AND respondent_email IS NOT NULL
                  AND TRIM(respondent_email) <> \'\'
                  AND LOWER(TRIM(respondent_email)) = LOWER(TRIM(?))
                LIMIT 1
            ');
            $dup->bind_param('is', $survey_id, $respondent_email);
            $dup->execute();
            $alreadyEmail = $dup->get_result()->fetch_assoc();
            $dup->close();

            if ($alreadyEmail) {
                psr_fail(
                    'This email address has already submitted this specific survey. Please contact the admin for support at edtech@hivecolab.com.',
                    $token,
                    409
                );
            }
        }

        if ($venture_id) {
            $dupVenture = $conn->prepare('
                SELECT id
                FROM survey_responses
                WHERE survey_id = ?
                  AND venture_id = ?
                LIMIT 1
            ');
            $dupVenture->bind_param('ii', $survey_id, $venture_id);
            $dupVenture->execute();
            $alreadyVenture = $dupVenture->get_result()->fetch_assoc();
            $dupVenture->close();

            if ($alreadyVenture) {
                psr_fail(
                    'You have already submitted this specific survey. Please contact the admin for support at edtech@hivecolab.com.',
                    $token,
                    409
                );
            }
        }
    }

    $questions = [];

    $qs = $conn->prepare('
        SELECT
            id,
            field_type,
            is_required,
            file_allowed_types,
            file_max_size_mb,
            file_max_count
        FROM survey_questions
        WHERE survey_id = ? AND status = 1
        ORDER BY sort_order ASC, id ASC
    ');
    $qs->bind_param('i', $survey_id);
    $qs->execute();
    $qres = $qs->get_result();

    while ($row = $qres->fetch_assoc()) {
        $questions[] = $row;
    }

    $qs->close();

    if (!$questions) {
        psr_fail('This survey has no active questions.', $token, 422);
    }

    $errors = [];

    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $type = (string)$q['field_type'];
        $required = (int)$q['is_required'] === 1;

        if ($type === 'file_upload') {
            $file_entry = psr_file_for_question($qid);
            $files = $file_entry ? psr_normalise_file_entry($file_entry) : [];

            if ($required && !$files) {
                $errors[] = "Question #{$qid} requires a file upload.";
                continue;
            }

            if ($files) {
                $max_mb = max(1, (int)($q['file_max_size_mb'] ?? 10));
                $max_count = max(1, (int)($q['file_max_count'] ?? 1));
                $max_bytes = $max_mb * 1024 * 1024;

                if (count($files) > $max_count) {
                    $errors[] = "Question #{$qid} allows a maximum of {$max_count} file(s).";
                }

                foreach ($files as $file) {
                    if ((int)$file['size'] > $max_bytes) {
                        $errors[] = '"' . $file['name'] . "\" exceeds the {$max_mb} MB limit.";
                    }
                }
            }

            continue;
        }

        $answers = psr_string_answers(psr_value_for_question($qid));

        if ($required && !$answers) {
            $errors[] = "Question #{$qid} is required.";
        }
    }

    if ($errors) {
        psr_json(false, [
            'message' => 'Please complete all required questions.',
            'errors'  => $errors,
        ], 422);
    }

    $conn->begin_transaction();

    try {
        $response_token = psr_random_token();
        // Preserve respondent privacy for anonymous surveys.
        $ip_address = '';
        $user_agent = '';
        $status = 'submitted';

        $submitted_at_sql = psr_now_sql();
        $submitted_at_email = psr_now_readable();

        $insert_response = $conn->prepare('
            INSERT INTO survey_responses
                (
                    survey_id,
                    venture_id,
                    venture_name,
                    respondent_name,
                    respondent_email,
                    external_token,
                    ip_address,
                    user_agent,
                    status,
                    submitted_at
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $insert_response->bind_param(
            'iissssssss',
            $survey_id,
            $venture_id,
            $venture_name,
            $respondent_name,
            $respondent_email,
            $response_token,
            $ip_address,
            $user_agent,
            $status,
            $submitted_at_sql
        );

        $insert_response->execute();
        $response_id = (int)$conn->insert_id;
        $insert_response->close();

        if ($response_id < 1) {
            throw new RuntimeException('Response row was not created.');
        }

        $insert_answer = $conn->prepare('
            INSERT INTO survey_response_answers
                (response_id, question_id, answer_value)
            VALUES
                (?, ?, ?)
        ');

        $saved_answer_count = 0;

        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $type = (string)$q['field_type'];

            if ($type === 'file_upload') {
                $file_entry = psr_file_for_question($qid);

                if (!$file_entry) {
                    continue;
                }

                $saved_files = psr_process_file_question(
                    $file_entry,
                    $survey_id,
                    $qid,
                    max(1, (int)($q['file_max_size_mb'] ?? 10)),
                    max(1, (int)($q['file_max_count'] ?? 1)),
                    (string)($q['file_allowed_types'] ?? 'any')
                );

                foreach ($saved_files as $file_url) {
                    $insert_answer->bind_param('iis', $response_id, $qid, $file_url);
                    $insert_answer->execute();
                    $saved_answer_count++;
                }

                continue;
            }

            $answers = psr_string_answers(psr_value_for_question($qid));

            foreach ($answers as $answer) {
                $insert_answer->bind_param('iis', $response_id, $qid, $answer);
                $insert_answer->execute();
                $saved_answer_count++;
            }
        }

        $insert_answer->close();

        if ($saved_answer_count < 1) {
            throw new RuntimeException('No answers were saved. Check survey input names and active questions.');
        }

        $conn->commit();

        psr_notify_admins_of_response(
            $conn,
            $survey,
            $response_id,
            $respondent_name,
            $respondent_email,
            $venture_name,
            $submitted_at_email
        );

        psr_json(true, [
            'message'        => 'Survey response submitted successfully.',
            'response_id'    => $response_id,
            'response_token' => $response_token,
            'answers_saved'  => $saved_answer_count,
            'submitted_at'   => $submitted_at_sql,
            'timezone'       => 'Africa/Nairobi',
            'redirect'       => '../survey.php?t=' . urlencode($token) . '&done=1',
        ]);
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_error) {
            error_log('[process-survey-response] Rollback failed: ' . $rollback_error->getMessage());
        }

        error_log('[process-survey-response] Save failed: ' . $e->getMessage());

        psr_json(false, [
            'message' => 'Submission failed. ' . $e->getMessage(),
            'errors'  => [$e->getMessage()],
        ], 500);
    }
} catch (Throwable $e) {
    error_log('[process-survey-response] Unhandled: ' . $e->getMessage());

    psr_json(false, [
        'message' => 'Submission failed. ' . $e->getMessage(),
        'errors'  => [$e->getMessage()],
    ], 500);
}