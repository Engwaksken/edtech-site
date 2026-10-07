<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $_SESSION['referral_errors'] = [
        'Database connection error. Please try again later.',
    ];

    header('Location: ../referral.php');
    exit;
}

$conn->set_charset('utf8mb4');


/* ============================================================
   HELPERS
============================================================ */

if (!function_exists('referral_process_redirect')) {
    function referral_process_redirect(string $suffix = ''): never
    {
        $location = '../referral.php';

        if ($suffix !== '') {
            $location .= $suffix;
        }

        header('Location: ' . $location);
        exit;
    }
}


if (!function_exists('referral_process_word_count')) {
    function referral_process_word_count(string $text): int
    {
        $text = trim(strip_tags($text));

        if ($text === '') {
            return 0;
        }

        preg_match_all(
            '/\b[\p{L}\p{N}\'][\p{L}\p{N}\'\-]*\b/u',
            $text,
            $matches
        );

        return count($matches[0] ?? []);
    }
}


if (!function_exists('referral_process_clean')) {
    function referral_process_clean($value): string
    {
        return trim((string)($value ?? ''));
    }
}


if (!function_exists('referral_process_h')) {
    function referral_process_h($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


if (!function_exists('referral_process_ensure_table')) {
    function referral_process_ensure_table(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS venture_referrals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                full_name VARCHAR(180) NOT NULL,
                organisation VARCHAR(220) NOT NULL,
                role_position VARCHAR(180) NOT NULL,
                email VARCHAR(190) NOT NULL,
                telephone VARCHAR(80) NOT NULL,
                relationship_to_venture TEXT NOT NULL,

                venture_name VARCHAR(220) NOT NULL,
                founder_name VARCHAR(180) NOT NULL,
                founder_email VARCHAR(190) NOT NULL,
                website_link VARCHAR(500) NULL,
                referral_reason TEXT NOT NULL,

                venture_informed ENUM('yes','no') NOT NULL,
                information_accurate TINYINT(1) NOT NULL DEFAULT 0,

                cohort_label VARCHAR(100) NOT NULL DEFAULT 'Cohort 2',

                status ENUM(
                    'new',
                    'contacted',
                    'reviewing',
                    'invited',
                    'not_eligible',
                    'closed'
                ) NOT NULL DEFAULT 'new',

                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(500) NULL,

                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),

                INDEX idx_referral_email (email),
                INDEX idx_referral_founder_email (founder_email),
                INDEX idx_referral_status (status),
                INDEX idx_referral_created_at (created_at)

            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'Could not create or verify the venture referrals table.'
            );
        }
    }
}


if (!function_exists('referral_process_site_setting')) {
    function referral_process_site_setting(
        mysqli $conn,
        string $key,
        string $default = ''
    ): string {
        if (function_exists('get_setting')) {
            try {
                return trim((string)get_setting($conn, $key, $default));
            } catch (Throwable $e) {
                error_log(
                    'Referral get_setting error [' . $key . ']: '
                    . $e->getMessage()
                );
            }
        }

        $table = $conn->query("SHOW TABLES LIKE 'site_settings'");

        if (!$table || $table->num_rows === 0) {
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

        $value = trim((string)($row['setting_value'] ?? ''));

        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('referral_process_send_notifications')) {
    function referral_process_send_notifications(
        mysqli $conn,
        int $referralId,
        array $data
    ): void {
        $mailFile = __DIR__ . '/mail-function.php';

        if (!file_exists($mailFile)) {
            return;
        }

        try {
            require_once $mailFile;
        } catch (Throwable $e) {
            error_log(
                'Referral mail helper load error: ' . $e->getMessage()
            );
            return;
        }

        if (!function_exists('sendEmail')) {
            return;
        }

        $siteName = referral_process_site_setting(
            $conn,
            'site_name',
            'Hive Colab'
        );

        $siteEmail = referral_process_site_setting(
            $conn,
            'site_email',
            ''
        );

        /*
         * --------------------------------------------------------
         * Programme notification
         * --------------------------------------------------------
         */
        if (
            $siteEmail !== '' &&
            filter_var($siteEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $subject =
                'New Cohort 2 Venture Referral: '
                . $data['venture_name'];

            $content = '
                <h3>New Venture Referral Received</h3>

                <p>
                    A new venture referral has been submitted for the
                    Mastercard Foundation EdTech Fellowship - Uganda,
                    Cohort 2.
                </p>

                <div class="info-box">
                    <strong>Referral ID:</strong>
                    #' . $referralId . '<br>

                    <strong>Venture:</strong>
                    ' . referral_process_h($data['venture_name']) . '<br>

                    <strong>Founder / Primary Contact:</strong>
                    ' . referral_process_h($data['founder_name']) . '<br>

                    <strong>Founder Email:</strong>
                    ' . referral_process_h($data['founder_email']) . '<br>

                    <strong>Website / Product Link:</strong>
                    ' . (
                        $data['website_link'] !== ''
                            ? referral_process_h($data['website_link'])
                            : 'Not provided'
                    ) . '<br>

                    <strong>Referred By:</strong>
                    ' . referral_process_h($data['full_name']) . '<br>

                    <strong>Organisation:</strong>
                    ' . referral_process_h($data['organisation']) . '<br>

                    <strong>Role / Position:</strong>
                    ' . referral_process_h($data['role_position']) . '<br>

                    <strong>Referrer Email:</strong>
                    ' . referral_process_h($data['email']) . '<br>

                    <strong>Telephone:</strong>
                    ' . referral_process_h($data['telephone']) . '<br>

                    <strong>Venture Informed:</strong>
                    ' . (
                        $data['venture_informed'] === 'yes'
                            ? 'Yes'
                            : 'No'
                    ) . '
                </div>

                <h3>Why the Venture Was Referred</h3>

                <p>'
                    . nl2br(
                        referral_process_h(
                            $data['referral_reason']
                        )
                    )
                . '</p>

                <h3>Relationship to Venture</h3>

                <p>'
                    . nl2br(
                        referral_process_h(
                            $data['relationship_to_venture']
                        )
                    )
                . '</p>

                <p>
                    Please review the referral and contact the venture
                    where appropriate.
                </p>
            ';

            $body = function_exists('email_wrapper')
                ? email_wrapper($content)
                : $content;

            $result = sendEmail(
                $siteEmail,
                $subject,
                $body
            );

            if ($result !== true) {
                error_log(
                    'Referral programme notification failed: '
                    . (string)$result
                );
            }
        }


        /*
         * --------------------------------------------------------
         * Referrer acknowledgement
         * --------------------------------------------------------
         */
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $subject =
                'Venture referral received - '
                . $data['venture_name'];

            $content = '
                <h3>Thank you for your referral</h3>

                <p>
                    Hello '
                    . referral_process_h($data['full_name'])
                    . ',
                </p>

                <p>
                    Thank you for referring
                    <strong>'
                    . referral_process_h($data['venture_name'])
                    . '</strong>
                    for consideration for the Mastercard Foundation
                    EdTech Fellowship - Uganda, Cohort 2.
                </p>

                <div class="info-box">
                    <strong>Referral ID:</strong>
                    #' . $referralId . '<br>

                    <strong>Venture:</strong>
                    ' . referral_process_h($data['venture_name']) . '<br>

                    <strong>Founder / Primary Contact:</strong>
                    ' . referral_process_h($data['founder_name']) . '
                </div>

                <p>
                    Please note that a referral does not constitute
                    acceptance into the Fellowship. The referred venture
                    may be contacted and invited to submit an application,
                    subject to meeting the programme eligibility
                    requirements.
                </p>

                <p>
                    Thank you,<br>
                    '
                    . referral_process_h($siteName)
                    . '
                </p>
            ';

            $body = function_exists('email_wrapper')
                ? email_wrapper($content)
                : $content;

            $result = sendEmail(
                $data['email'],
                $subject,
                $body
            );

            if ($result !== true) {
                error_log(
                    'Referral acknowledgement email failed: '
                    . (string)$result
                );
            }
        }
    }
}


/* ============================================================
   REQUEST METHOD
============================================================ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['referral_errors'] = [
        'Invalid referral submission request.',
    ];

    referral_process_redirect();
}


/* ============================================================
   CSRF
============================================================ */

$csrfToken = referral_process_clean(
    $_POST['csrf_token'] ?? ''
);

$sessionToken = (string)(
    $_SESSION['referral_csrf'] ?? ''
);

if (
    $csrfToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $csrfToken)
) {
    $_SESSION['referral_errors'] = [
        'Your form session expired. Please refresh the page and try again.',
    ];

    referral_process_redirect();
}


/* ============================================================
   COLLECT FORM DATA
============================================================ */

$data = [
    'full_name' =>
        referral_process_clean(
            $_POST['full_name'] ?? ''
        ),

    'organisation' =>
        referral_process_clean(
            $_POST['organisation'] ?? ''
        ),

    'role_position' =>
        referral_process_clean(
            $_POST['role_position'] ?? ''
        ),

    'email' =>
        referral_process_clean(
            $_POST['email'] ?? ''
        ),

    'telephone' =>
        referral_process_clean(
            $_POST['telephone'] ?? ''
        ),

    'relationship_to_venture' =>
        referral_process_clean(
            $_POST['relationship_to_venture'] ?? ''
        ),

    'venture_name' =>
        referral_process_clean(
            $_POST['venture_name'] ?? ''
        ),

    'founder_name' =>
        referral_process_clean(
            $_POST['founder_name'] ?? ''
        ),

    'founder_email' =>
        referral_process_clean(
            $_POST['founder_email'] ?? ''
        ),

    'website_link' =>
        referral_process_clean(
            $_POST['website_link'] ?? ''
        ),

    'referral_reason' =>
        referral_process_clean(
            $_POST['referral_reason'] ?? ''
        ),

    'venture_informed' =>
        referral_process_clean(
            $_POST['venture_informed'] ?? ''
        ),

    'information_accurate' =>
        isset($_POST['information_accurate'])
            ? '1'
            : '0',
];


/*
 * Preserve entered data if validation fails.
 */
$_SESSION['referral_old'] = $data;


/* ============================================================
   VALIDATION
============================================================ */

$errors = [];

$requiredFields = [
    'full_name' =>
        'Full name',

    'organisation' =>
        'Organisation',

    'role_position' =>
        'Role / Position',

    'email' =>
        'Email address',

    'telephone' =>
        'Telephone number',

    'relationship_to_venture' =>
        'How you know this venture',

    'venture_name' =>
        'Venture name',

    'founder_name' =>
        'Founder / Primary contact name',

    'founder_email' =>
        'Founder / Primary contact email',

    'referral_reason' =>
        'Reason for referral',

    'venture_informed' =>
        'Venture informed confirmation',
];

foreach ($requiredFields as $key => $label) {
    if ($data[$key] === '') {
        $errors[] = $label . ' is required.';
    }
}


/* Email */
if (
    $data['email'] !== '' &&
    !filter_var(
        $data['email'],
        FILTER_VALIDATE_EMAIL
    )
) {
    $errors[] =
        'Please enter a valid email address for yourself.';
}


/* Founder email */
if (
    $data['founder_email'] !== '' &&
    !filter_var(
        $data['founder_email'],
        FILTER_VALIDATE_EMAIL
    )
) {
    $errors[] =
        'Please enter a valid founder/primary contact email address.';
}


/* Website */
if (
    $data['website_link'] !== '' &&
    !filter_var(
        $data['website_link'],
        FILTER_VALIDATE_URL
    )
) {
    $errors[] =
        'Please enter a valid website or product link including https://';
}


/* Venture informed */
if (
    !in_array(
        $data['venture_informed'],
        ['yes', 'no'],
        true
    )
) {
    $errors[] =
        'Please confirm whether the venture has been informed about the referral.';
}


/* Confirmation */
if ($data['information_accurate'] !== '1') {
    $errors[] =
        'You must confirm that the information provided is accurate to the best of your knowledge.';
}


/* 150 word referral reason */
$reasonWordCount = referral_process_word_count(
    $data['referral_reason']
);

if ($reasonWordCount > 150) {
    $errors[] =
        'The referral reason must not exceed 150 words.';
}


/* Basic maximum lengths */
$lengthRules = [
    'full_name'      => [180, 'Full name'],
    'organisation'   => [220, 'Organisation'],
    'role_position'  => [180, 'Role / Position'],
    'email'          => [190, 'Email address'],
    'telephone'      => [80, 'Telephone number'],
    'venture_name'   => [220, 'Venture name'],
    'founder_name'   => [180, 'Founder name'],
    'founder_email'  => [190, 'Founder email'],
    'website_link'   => [500, 'Website / Product link'],
];

foreach ($lengthRules as $key => [$max, $label]) {
    if (
        $data[$key] !== '' &&
        mb_strlen($data[$key]) > $max
    ) {
        $errors[] =
            $label
            . ' is too long. Maximum '
            . $max
            . ' characters.';
    }
}


if ($errors) {
    $_SESSION['referral_errors'] = $errors;
    referral_process_redirect();
}


/* ============================================================
   CREATE / VERIFY TABLE
============================================================ */

try {
    referral_process_ensure_table($conn);
} catch (Throwable $e) {
    error_log(
        'Referral table error: '
        . $e->getMessage()
    );

    $_SESSION['referral_errors'] = [
        'The referral form is temporarily unavailable. Please try again later.',
    ];

    referral_process_redirect();
}


/* ============================================================
   OPTIONAL DUPLICATE PROTECTION
   Prevents repeated submissions for the same venture/contact
   within a short period.
============================================================ */

$duplicateStmt = $conn->prepare("
    SELECT id
    FROM venture_referrals
    WHERE founder_email = ?
      AND venture_name = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    LIMIT 1
");

if ($duplicateStmt) {
    $duplicateStmt->bind_param(
        'ss',
        $data['founder_email'],
        $data['venture_name']
    );

    $duplicateStmt->execute();

    $duplicate = $duplicateStmt
        ->get_result()
        ->fetch_assoc();

    $duplicateStmt->close();

    if ($duplicate) {
        unset($_SESSION['referral_old']);

        $_SESSION['referral_success'] =
            'This venture referral was already received recently. '
            . 'Thank you for your submission.';

        referral_process_redirect('?submitted=1');
    }
}


/* ============================================================
   INSERT REFERRAL
============================================================ */

$ipAddress = referral_process_clean(
    $_SERVER['REMOTE_ADDR'] ?? ''
);

$userAgent = mb_substr(
    referral_process_clean(
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ),
    0,
    500
);

$stmt = $conn->prepare("
    INSERT INTO venture_referrals (
        full_name,
        organisation,
        role_position,
        email,
        telephone,
        relationship_to_venture,

        venture_name,
        founder_name,
        founder_email,
        website_link,
        referral_reason,

        venture_informed,
        information_accurate,

        cohort_label,
        status,

        ip_address,
        user_agent
    )
    VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, 1,
        'Cohort 2',
        'new',
        ?, ?
    )
");

if (!$stmt) {
    error_log(
        'Referral INSERT prepare error: '
        . $conn->error
    );

    $_SESSION['referral_errors'] = [
        'We could not save your referral right now. Please try again.',
    ];

    referral_process_redirect();
}


$stmt->bind_param(
    'ssssssssssssss',
    $data['full_name'],
    $data['organisation'],
    $data['role_position'],
    $data['email'],
    $data['telephone'],
    $data['relationship_to_venture'],

    $data['venture_name'],
    $data['founder_name'],
    $data['founder_email'],
    $data['website_link'],
    $data['referral_reason'],

    $data['venture_informed'],

    $ipAddress,
    $userAgent
);


if (!$stmt->execute()) {
    error_log(
        'Referral INSERT execute error: '
        . $stmt->error
    );

    $stmt->close();

    $_SESSION['referral_errors'] = [
        'We could not save your referral right now. Please try again.',
    ];

    referral_process_redirect();
}


$referralId = (int)$stmt->insert_id;
$stmt->close();


/* ============================================================
   SEND EMAILS
   Email failures do not cancel a successful database submission.
============================================================ */

try {
    referral_process_send_notifications(
        $conn,
        $referralId,
        $data
    );
} catch (Throwable $e) {
    error_log(
        'Referral notification error: '
        . $e->getMessage()
    );
}


/* ============================================================
   SUCCESS
============================================================ */

unset(
    $_SESSION['referral_old'],
    $_SESSION['referral_errors']
);

$_SESSION['referral_csrf'] =
    bin2hex(random_bytes(32));

$_SESSION['referral_success'] =
    'Thank you. Your referral has been submitted successfully. '
    . 'The programme team may contact the referred venture and invite '
    . 'them to submit an application, subject to the programme eligibility requirements.';

referral_process_redirect('?submitted=1');
