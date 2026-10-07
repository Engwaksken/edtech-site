<?php
declare(strict_types=1);


if (
    !isset($conn)
    || !($conn instanceof mysqli)
) {
    require_once __DIR__
        . '/../../includes/config.php';
}

if (
    session_status()
    === PHP_SESSION_NONE
) {
    session_start();
}


if (!function_exists('arp_admin_referrals_url')) {
    function arp_admin_referrals_url(): string
    {
       
        $scriptName = (string)(
            $_SERVER['SCRIPT_NAME']
            ?? ''
        );

        if (
            preg_match(
                '#^(.*?/admin)(?:/.*)?$#',
                $scriptName,
                $matches
            )
        ) {
            return rtrim(
                (string)$matches[1],
                '/'
            ) . '/referrals';
        }

        return '/admin/referrals';
    }
}


if (!function_exists('arp_safe_return_url')) {
    function arp_safe_return_url(): string
    {
        $default =
            arp_admin_referrals_url();

        $returnTo = trim(
            (string)(
                $_POST['return_to']
                ?? ''
            )
        );

        if ($returnTo === '') {
            return $default;
        }

        /*
         * Only allow a local referrals URL.
         * This prevents an open redirect.
         */
        $decoded = html_entity_decode(
            $returnTo,
            ENT_QUOTES,
            'UTF-8'
        );

        $parts = parse_url($decoded);

        if ($parts === false) {
            return $default;
        }

        if (
            isset($parts['scheme'])
            || isset($parts['host'])
        ) {
            return $default;
        }

        $path = (string)(
            $parts['path']
            ?? ''
        );

        /*
         * Accept either:
         * referrals
         * /admin/referrals
         * /folder/admin/referrals
         */
        if (
            !preg_match(
                '#(?:^|/)admin/referrals$#',
                $path
            )
            && $path !== 'referrals'
        ) {
            return $default;
        }

        $query = trim(
            (string)(
                $parts['query']
                ?? ''
            )
        );

        return $default
            . (
                $query !== ''
                    ? '?' . $query
                    : ''
            );
    }
}


if (!function_exists('arp_redirect')) {
    function arp_redirect(
        string $message,
        string $type = 'success'
    ): never {
        if ($type === 'success') {
            $_SESSION['referrals_success'] =
                $message;
        } else {
            $_SESSION['referrals_error'] =
                $message;
        }

        header(
            'Location: '
            . arp_safe_return_url()
        );

        exit;
    }
}


/* ============================================================
   DATABASE
============================================================ */

if (
    !isset($conn)
    || !($conn instanceof mysqli)
) {
    $_SESSION['referrals_error'] =
        'Database connection error.';

    header(
        'Location: '
        . arp_admin_referrals_url()
    );

    exit;
}

$conn->set_charset('utf8mb4');


/* ============================================================
   REQUEST METHOD
============================================================ */

if (
    strtoupper(
        (string)(
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        )
    ) !== 'POST'
) {
    arp_redirect(
        'Invalid referral management request.',
        'error'
    );
}


/* ============================================================
   ACTION
============================================================ */

$action = trim(
    (string)(
        $_POST['referral_action']
        ?? $_POST['action']
        ?? ''
    )
);

$id = (int)(
    $_POST['id']
    ?? 0
);

if ($id <= 0) {
    arp_redirect(
        'Invalid referral selected.',
        'error'
    );
}


/* ============================================================
   TABLE CHECK
============================================================ */

$table = $conn->query(
    "SHOW TABLES LIKE 'venture_referrals'"
);

if (
    !($table instanceof mysqli_result)
    || $table->num_rows === 0
) {
    arp_redirect(
        'The venture referrals table does not exist.',
        'error'
    );
}


/* ============================================================
   VERIFY REFERRAL
============================================================ */

$check = $conn->prepare("
    SELECT
        id,
        venture_name
    FROM venture_referrals
    WHERE id = ?
    LIMIT 1
");

if (!$check) {
    arp_redirect(
        'Could not verify the referral.',
        'error'
    );
}

$check->bind_param(
    'i',
    $id
);

$check->execute();

$referral = $check
    ->get_result()
    ->fetch_assoc();

$check->close();

if (!$referral) {
    arp_redirect(
        'Referral record was not found.',
        'error'
    );
}


/* ============================================================
   UPDATE STATUS
============================================================ */

if ($action === 'update_status') {
    $status = trim(
        (string)(
            $_POST['status']
            ?? ''
        )
    );

    $allowedStatuses = [
        'new',
        'contacted',
        'reviewing',
        'invited',
        'not_eligible',
        'closed',
    ];

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        arp_redirect(
            'Invalid referral status.',
            'error'
        );
    }

    $stmt = $conn->prepare("
        UPDATE venture_referrals
        SET
            status = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        arp_redirect(
            'Could not prepare the status update.',
            'error'
        );
    }

    $stmt->bind_param(
        'si',
        $status,
        $id
    );

    if (!$stmt->execute()) {
        error_log(
            'Referral status update error: '
            . $stmt->error
        );

        $stmt->close();

        arp_redirect(
            'The referral status could not be updated.',
            'error'
        );
    }

    $stmt->close();

    arp_redirect(
        'Referral status updated successfully.'
    );
}


/* ============================================================
   DELETE REFERRAL
============================================================ */

if ($action === 'delete') {
    $ventureName = trim(
        (string)(
            $referral['venture_name']
            ?? 'Referral'
        )
    );

    $stmt = $conn->prepare("
        DELETE FROM venture_referrals
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        arp_redirect(
            'Could not prepare the referral deletion.',
            'error'
        );
    }

    $stmt->bind_param(
        'i',
        $id
    );

    if (!$stmt->execute()) {
        error_log(
            'Referral delete error: '
            . $stmt->error
        );

        $stmt->close();

        arp_redirect(
            'The referral could not be deleted.',
            'error'
        );
    }

    $affectedRows =
        $stmt->affected_rows;

    $stmt->close();

    if ($affectedRows < 1) {
        arp_redirect(
            'Referral was not deleted because the record no longer exists.',
            'error'
        );
    }

    arp_redirect(
        $ventureName
        . ' referral deleted successfully.'
    );
}


/* ============================================================
   UNKNOWN ACTION
============================================================ */

arp_redirect(
    'Unknown referral management action.',
    'error'
);
