<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/encryption.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Confirm that PHPMailer is available through Composer before attempting
 * to instantiate it. This prevents an uncaught "Class PHPMailer not found"
 * fatal error when vendor/phpmailer has not been deployed to the server.
 */
function phpmailer_available(): bool
{
    return class_exists(PHPMailer::class);
}

/**
 * Create a PHPMailer instance safely.
 *
 * @throws RuntimeException when PHPMailer is not installed/deployed.
 */
function make_phpmailer(): PHPMailer
{
    if (!phpmailer_available()) {
        throw new RuntimeException(
            'PHPMailer is not installed. Run "composer require phpmailer/phpmailer" '
            . 'in the project root and deploy composer.json, composer.lock and vendor/.'
        );
    }

    return new PHPMailer(true);
}


function getEmailSettings() {
    global $conn;
    if (!isset($conn)) {
        $conn = db_connect();
    }
    $stmt = $conn->prepare("SELECT * FROM email_settings WHERE status = 1 LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc() ?: null;
    $stmt->close();
    if ($settings) {
        $settings['smtp_password'] = site_decrypt_secret((string)$settings['smtp_password'], 'smtp:password:' . (int)$settings['id']);
    }
    return $settings;
}


function sendEmail(
    $to,
    $subject,
    $body,
    $altBody      = null,
    $cc           = [],
    $bcc          = [],
    $attachments  = [],
    $inlineImages = []
) {
    $settings = getEmailSettings();

    if (!$settings) {
        error_log("sendEmail: No active email settings found.");
        return "Email settings not configured.";
    }

    /*
     * Gmail and other SMTP providers can temporarily reject a message
     * with 4xx responses such as:
     *   451 4.3.0 Mail server temporarily rejected message
     *
     * Those are retryable SMTP responses. Permanent 5xx failures are not.
     */
    $maxAttempts = 3;
    $retryDelays = [2, 5, 10];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $mail = null;

        try {
            $mail = make_phpmailer();
            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['smtp_user'];
            $mail->Password   = $settings['smtp_password'];
            $mail->SMTPSecure = $settings['smtp_secure'];
            $mail->Port       = (int)$settings['smtp_port'];

            /*
             * Keep timeouts reasonable so scheduled tasks do not hang
             * indefinitely when the SMTP provider is temporarily busy.
             */
            $mail->Timeout       = 30;
            $mail->Timelimit     = 60;
            $mail->SMTPKeepAlive = false;

            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';

            $mail->setFrom(
                $settings['from_email'],
                $settings['from_name']
            );

            if (is_array($to)) {
                foreach ($to as $email) {
                    $email = trim((string)$email);
                    if ($email !== '') {
                        $mail->addAddress($email);
                    }
                }
            } else {
                $email = trim((string)$to);
                if ($email !== '') {
                    $mail->addAddress($email);
                }
            }

            foreach ($cc as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $mail->addCC($email);
                }
            }

            foreach ($bcc as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $mail->addBCC($email);
                }
            }

            foreach ($attachments as $file) {
                if (is_string($file) && file_exists($file)) {
                    $mail->addAttachment($file);
                }
            }

            foreach ($inlineImages as $img) {
                if (
                    isset($img['path'], $img['cid'])
                    && is_string($img['path'])
                    && file_exists($img['path'])
                ) {
                    $mail->addEmbeddedImage(
                        $img['path'],
                        (string)$img['cid']
                    );
                }
            }

            $mail->isHTML(true);
            $mail->Subject = (string)$subject;
            $mail->Body    = (string)$body;
            $mail->AltBody = $altBody ?: trim(strip_tags((string)$body));

            /*
             * Recommended headers for automated transactional mail.
             */
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');

            $mail->send();

            if ($attempt > 1) {
                error_log(
                    "sendEmail recovered after retry {$attempt}/{$maxAttempts} "
                    . "for subject [{$subject}]"
                );
            }

            return true;

        } catch (Throwable $e) {
            $errorInfo = $mail instanceof PHPMailer
                ? trim((string)$mail->ErrorInfo)
                : '';
            $exceptionMessage = trim((string)$e->getMessage());

            $combinedError =
                $errorInfo !== ''
                    ? $errorInfo
                    : $exceptionMessage;

            /*
             * Retry only temporary SMTP failures.
             *
             * Typical retryable responses:
             *   421 Service not available
             *   450 Mailbox temporarily unavailable
             *   451 Local processing error / temporary rejection
             *   452 Insufficient system storage / temporary resource issue
             *   4.x.x enhanced SMTP status codes
             *   connection/reset/timeout errors
             */
            $isTemporary =
                preg_match(
                    '/(?:^|\s)(421|450|451|452)(?:\s|$)|\b4\.[0-9]\.[0-9]\b/i',
                    $combinedError
                ) === 1
                || stripos($combinedError, 'temporarily rejected') !== false
                || stripos($combinedError, 'temporary failure') !== false
                || stripos($combinedError, 'timed out') !== false
                || stripos($combinedError, 'timeout') !== false
                || stripos($combinedError, 'connection reset') !== false
                || stripos($combinedError, 'connection failed') !== false
                || stripos($combinedError, 'data not accepted') !== false;

            error_log(
                "Email attempt {$attempt}/{$maxAttempts} failed "
                . "[{$subject}]: {$combinedError}"
            );

            /*
             * Explicitly close the connection before retrying so PHPMailer
             * does not reuse a half-failed SMTP session.
             */
            try {
                if ($mail instanceof PHPMailer && $mail->smtpConnect()) {
                    $mail->smtpClose();
                }
            } catch (Throwable $closeError) {
                // Ignore cleanup errors; the next attempt creates a new mailer.
            }

            if (!$isTemporary || $attempt >= $maxAttempts) {
                return "Mailer Error: {$combinedError}";
            }

            $delay = $retryDelays[$attempt - 1] ?? 10;
            sleep($delay);
        }
    }

    return "Mailer Error: Unable to send email after {$maxAttempts} attempts.";
}


/* ============================================================
   BULK SENDING (reused SMTP connection)
============================================================

   sendEmail() above is fine for one-off transactional emails
   (password resets, notifications, etc). But it opens a brand new
   PHPMailer instance -> brand new SMTP connection (TCP handshake +
   TLS negotiation + AUTH) for every single call.

   When sending a newsletter to many recipients, calling sendEmail()
   once per recipient means paying that full connection cost again
   and again. On some SMTP providers that alone is 1-3 seconds per
   recipient before a single message byte is even sent, which is why
   a 30-50 recipient newsletter can visibly take a minute or more.

   The functions below open ONE SMTP connection (PHPMailer's
   SMTPKeepAlive) and reuse it for every recipient in the batch,
   only closing it once the whole batch is done. This is the
   standard PHPMailer pattern for bulk/newsletter sending.
============================================================ */

/**
 * Create a single PHPMailer instance configured to keep its SMTP
 * connection open across multiple send() calls. Call sendBulkEmail()
 * once per recipient using the returned instance, then call
 * closeBulkMailer() once after the whole batch is done.
 *
 * Returns null if email settings are missing/invalid so callers can
 * fall back to sendEmail() or fail clearly.
 */
function createBulkMailer(): ?PHPMailer {
    $settings = getEmailSettings();

    if (!$settings) {
        error_log("createBulkMailer: No active email settings found.");
        return null;
    }

    try {
        $mail = make_phpmailer();

        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_user'];
        $mail->Password   = $settings['smtp_password'];
        $mail->SMTPSecure = $settings['smtp_secure'];
        $mail->Port       = $settings['smtp_port'];

        /*
         * This is the key setting: PHPMailer will keep the SMTP
         * socket open after send() instead of disconnecting, and
         * will reuse it on the next send() automatically.
         */
        $mail->SMTPKeepAlive = true;

        /*
         * WITHOUT THIS, PHPMailer defaults to ISO-8859-1. The rest of
         * this app (DB connection, HTML meta tag, JS) all uses UTF-8,
         * so any accented/special character (e.g. "Caf�", curly
         * quotes, em dashes) would be sent as valid UTF-8 bytes but
         * declared as ISO-8859-1 in the email's MIME headers - which
         * is exactly what makes "Caf�" show up as "Café" in the
         * recipient's inbox, even though it looks correct everywhere
         * in the admin (builder, preview) because those are plain
         * UTF-8 web pages with no charset mismatch.
         */
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom($settings['from_email'], $settings['from_name']);
        $mail->isHTML(true);

        return $mail;

    } catch (Throwable $e) {
        error_log('createBulkMailer error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send one message using an already-created bulk mailer (see
 * createBulkMailer()). Safe to call repeatedly in a loop - the
 * underlying SMTP connection is reused, not reopened, as long as
 * $mail->SMTPKeepAlive is true.
 *
 * Returns true on success, or a string error message on failure
 * (matching sendEmail()'s return contract).
 */
function sendBulkEmail(
    PHPMailer $mail,
    $to,
    $subject,
    $body,
    $altBody     = null,
    $cc          = [],
    $bcc         = [],
    $attachments = []
) {
    $maxAttempts = 3;
    $retryDelays = [2, 5, 10];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->clearReplyTos();

            if (is_array($to)) {
                foreach ($to as $email) {
                    $email = trim((string)$email);
                    if ($email !== '') {
                        $mail->addAddress($email);
                    }
                }
            } else {
                $email = trim((string)$to);
                if ($email !== '') {
                    $mail->addAddress($email);
                }
            }

            foreach ($cc as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $mail->addCC($email);
                }
            }

            foreach ($bcc as $email) {
                $email = trim((string)$email);
                if ($email !== '') {
                    $mail->addBCC($email);
                }
            }

            foreach ($attachments as $file) {
                if (is_string($file) && file_exists($file)) {
                    $mail->addAttachment($file);
                }
            }

            $mail->Subject = (string)$subject;
            $mail->Body    = (string)$body;
            $mail->AltBody = $altBody ?: trim(strip_tags((string)$body));

            $mail->send();
            return true;

        } catch (Exception $e) {
            $errorInfo = trim((string)$mail->ErrorInfo);
            $combinedError =
                $errorInfo !== ''
                    ? $errorInfo
                    : trim((string)$e->getMessage());

            $isTemporary =
                preg_match(
                    '/(?:^|\s)(421|450|451|452)(?:\s|$)|\b4\.[0-9]\.[0-9]\b/i',
                    $combinedError
                ) === 1
                || stripos($combinedError, 'temporarily rejected') !== false
                || stripos($combinedError, 'temporary failure') !== false
                || stripos($combinedError, 'timed out') !== false
                || stripos($combinedError, 'timeout') !== false
                || stripos($combinedError, 'connection reset') !== false
                || stripos($combinedError, 'connection failed') !== false
                || stripos($combinedError, 'data not accepted') !== false;

            error_log(
                "Bulk Email attempt {$attempt}/{$maxAttempts} failed "
                . "[{$subject}] to "
                . (is_array($to) ? implode(',', $to) : $to)
                . ": {$combinedError}"
            );

            if (!$isTemporary || $attempt >= $maxAttempts) {
                return "Mailer Error: {$combinedError}";
            }

            /*
             * Reconnect before retrying because a DATA END 451 can leave
             * the kept-alive SMTP session unusable.
             */
            try {
                $mail->smtpClose();
                $mail->smtpConnect();
            } catch (Throwable $reconnectError) {
                error_log(
                    'Bulk SMTP reconnect failed: '
                    . $reconnectError->getMessage()
                );
            }

            $delay = $retryDelays[$attempt - 1] ?? 10;
            sleep($delay);
        }
    }

    return "Mailer Error: Unable to send email after {$maxAttempts} attempts.";
}

/**
 * Close the SMTP connection opened by createBulkMailer(). Always
 * call this once after the batch loop finishes (success or failure)
 * so the connection doesn't linger.
 */
function closeBulkMailer(PHPMailer $mail): void {
    try {
        if ($mail->SMTPKeepAlive) {
            $mail->smtpClose();
        }
    } catch (Exception $e) {
        error_log('closeBulkMailer error: ' . $e->getMessage());
    }
}


function email_wrapper(string $content): string {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body        { margin:0; padding:0; background:#f5f6fa; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
            .outer      { padding:30px 15px; }
            .card       { max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;
                          box-shadow:0 4px 15px rgba(0,0,0,0.08); }
            .header     { background:linear-gradient(135deg,#FF5722 0%,#FF9800 100%); padding:30px 35px; text-align:center; }
            .header img { height:40px; margin-bottom:12px; }
            .header h1  { color:#ffffff; margin:0; font-size:22px; font-weight:700; letter-spacing:0.5px; }
            .body       { padding:35px; color:#2c3e50; line-height:1.7; font-size:15px; }
            .body p     { margin:0 0 16px; }
            .body h3    { color:#ff5722; margin:0 0 12px; font-size:18px; }
            .info-box   { background:#f8f9fa; border-left:4px solid #ff5722; border-radius:6px;
                          padding:15px 18px; margin:20px 0; font-size:14px; }
            .info-box strong { display:inline-block; min-width:150px; color:#7f8c8d; }
            .cred-box   { background:#fff8e1; border:2px solid #F39C12; border-radius:8px;
                          padding:20px 22px; margin:20px 0; }
            .cred-box h4 { margin:0 0 12px; color:#e67e22; font-size:16px; }
            .password   { font-family:'Courier New',Courier,monospace; font-size:22px; font-weight:700;
                          color:#c0392b; letter-spacing:3px; background:#fff; border:2px dashed #e74c3c;
                          padding:8px 16px; border-radius:6px; display:inline-block; margin:6px 0; }
            .warning    { background:#fdecea; border-left:4px solid #e74c3c; border-radius:4px;
                          padding:10px 14px; margin-top:14px; font-size:13px; color:#7d1a1a; }
            .btn        { display:inline-block; background:linear-gradient(135deg,#FF5722,#FF9800);
                          color:#ffffff !important; text-decoration:none; padding:13px 28px;
                          border-radius:8px; font-weight:700; font-size:15px; margin:20px 0; }
            .footer     { background:#f8f9fa; padding:20px 35px; text-align:center;
                          font-size:12px; color:#95a5a6; border-top:1px solid #ecf0f1; }
        </style>
    </head>
    <body>
    <div class='outer'>
        <div class='card'>
            <div class='header'>
                <h1>Hive Colab</h1>
            </div>
            <div class='body'>
                $content
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " Hive Colab. All rights reserved.<br>
                This is an automated message - please do not reply directly to this email.
            </div>
        </div>
    </div>
    </body>
    </html>";
}
