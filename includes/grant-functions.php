<?php

declare(strict_types=1);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

function generate_loa_docx(
    array $fields,
    string $templatePath,
    string $outputPath
): bool {
    if (!file_exists($templatePath)) {
        error_log("generate_loa_docx: template not found at {$templatePath}");
        return false;
    }

    $outputDir = dirname($outputPath);

    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
        error_log("generate_loa_docx: could not create output directory {$outputDir}");
        return false;
    }

    if (!copy($templatePath, $outputPath)) {
        error_log("generate_loa_docx: could not copy template to {$outputPath}");
        return false;
    }

    $zip = new ZipArchive();

    if ($zip->open($outputPath) !== true) {
        error_log("generate_loa_docx: could not open {$outputPath} as zip");
        return false;
    }

    $xml = $zip->getFromName('word/document.xml');

    if ($xml === false) {
        error_log('generate_loa_docx: word/document.xml not found in template');
        $zip->close();
        return false;
    }

    foreach ($fields as $token => $value) {
        $safeValue = htmlspecialchars(
            (string)$value,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $xml = str_replace(
            '{{' . $token . '}}',
            $safeValue,
            $xml
        );
    }

    $zip->addFromString(
        'word/document.xml',
        $xml
    );

    $zip->close();

    return true;
}

function generate_loa_for_venture(
    mysqli $conn,
    int $loaId,
    string $templatePath,
    string $outputDir
): ?string {
    $stmt = $conn->prepare(
        'SELECT * FROM loa_agreements WHERE id = ? LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $loaId);
    $stmt->execute();

    $loa = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$loa) {
        return null;
    }

    try {
        $effectiveDate = !empty($loa['effective_date'])
            ? (new DateTime((string)$loa['effective_date']))->format('jS F Y')
            : '';

        $endDate = !empty($loa['end_date'])
            ? (new DateTime((string)$loa['end_date']))->format('jS F Y')
            : '';
    } catch (Throwable $e) {
        error_log('generate_loa_for_venture date error: ' . $e->getMessage());
        return null;
    }

    $fields = [
        'FELLOW_LEGAL_NAME' => $loa['fellow_legal_name'] ?? '',
        'ENTITY_TYPE' => $loa['entity_type'] ?? '',
        'FELLOW_ADDRESS' => $loa['fellow_address'] ?? '',
        'REP_TITLE' => $loa['rep_title'] ?? '',
        'REP_NAME' => $loa['rep_name'] ?? '',
        'EFFECTIVE_DATE' => $effectiveDate,
        'END_DATE' => $endDate,
        'GRANT_TOTAL' => 'USD ' . number_format((float)($loa['grant_total'] ?? 0), 0),
        'GRANT_TOTAL_WORDS' => $loa['grant_total_words'] ?? '',
    ];

    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
        return null;
    }

    $safeName = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        (string)($loa['fellow_legal_name'] ?? 'agreement')
    ) ?: 'agreement';

    $fileName = "LoA_{$safeName}_{$loaId}.docx";
    $outputPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

    if (!generate_loa_docx($fields, $templatePath, $outputPath)) {
        return null;
    }

    $update = $conn->prepare("
        UPDATE loa_agreements
        SET
            status = 'generated',
            generated_file_path = ?,
            generated_at = NOW()
        WHERE id = ?
    ");

    if ($update) {
        $update->bind_param('si', $fileName, $loaId);
        $update->execute();
        $update->close();
    }

    return $outputPath;
}

function get_venture_tranches(mysqli $conn, int $ventureId): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM grant_tranches
        WHERE venture_id = ?
        ORDER BY sort_order ASC, id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $ventureId);
    $stmt->execute();

    $tranches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($tranches as &$tranche) {
        $trancheId = (int)($tranche['id'] ?? 0);

        $tranche['requirements'] =
            get_tranche_requirements($conn, $trancheId);

        $tranche['latest_submission'] =
            get_latest_submission($conn, $trancheId);

        $tranche['submissions'] =
            get_tranche_submissions($conn, $trancheId);

        $tranche['disbursement'] =
            get_tranche_disbursement($conn, $trancheId);
    }

    unset($tranche);

    return $tranches;
}

function get_tranche_requirements(mysqli $conn, int $trancheId): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM tranche_requirements
        WHERE tranche_id = ?
        ORDER BY sort_order ASC, id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $trancheId);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function get_latest_submission(mysqli $conn, int $trancheId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM tranche_submissions
        WHERE tranche_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $trancheId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $row['responses'] =
            get_submission_responses(
                $conn,
                (int)$row['id']
            );

        $row['files'] =
            get_submission_files(
                $conn,
                (int)$row['id']
            );
    }

    return $row;
}

function get_tranche_submissions(mysqli $conn, int $trancheId): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM tranche_submissions
        WHERE tranche_id = ?
        ORDER BY submitted_at DESC, id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $trancheId);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $submissionId = (int)($row['id'] ?? 0);

        $row['responses'] =
            get_submission_responses(
                $conn,
                $submissionId
            );

        $row['files'] =
            get_submission_files(
                $conn,
                $submissionId
            );
    }

    unset($row);

    return $rows;
}

function get_submission_responses(mysqli $conn, int $submissionId): array
{
    $stmt = $conn->prepare("
        SELECT
            trr.*,
            tr.requirement_text
        FROM tranche_requirement_responses trr
        JOIN tranche_requirements tr
          ON tr.id = trr.requirement_id
        WHERE trr.submission_id = ?
        ORDER BY tr.sort_order ASC, tr.id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $submissionId);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function get_submission_files(mysqli $conn, int $submissionId): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM tranche_submission_files
        WHERE submission_id = ?
        ORDER BY uploaded_at ASC, id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $submissionId);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function get_submission_file(mysqli $conn, int $fileId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            tsf.*,
            ts.tranche_id,
            ts.venture_id
        FROM tranche_submission_files tsf
        JOIN tranche_submissions ts
          ON ts.id = tsf.submission_id
        WHERE tsf.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $fileId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function get_tranche_submission(mysqli $conn, int $submissionId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM tranche_submissions
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $submissionId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $row['responses'] =
            get_submission_responses(
                $conn,
                $submissionId
            );

        $row['files'] =
            get_submission_files(
                $conn,
                $submissionId
            );
    }

    return $row;
}

function get_tranche_disbursement(mysqli $conn, int $trancheId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM fund_disbursements
        WHERE tranche_id = ?
        ORDER BY disbursed_date DESC, id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $trancheId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function get_venture_fund_summary(mysqli $conn, int $ventureId): array
{
    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(gt.amount), 0) AS total_committed,
            COALESCE(
                SUM(
                    CASE
                        WHEN gt.status = 'disbursed'
                        THEN gt.amount
                        ELSE 0
                    END
                ),
                0
            ) AS total_disbursed,
            COALESCE(
                SUM(
                    CASE
                        WHEN gt.status IN ('submitted', 'approved')
                        THEN gt.amount
                        ELSE 0
                    END
                ),
                0
            ) AS total_pending
        FROM grant_tranches gt
        WHERE gt.venture_id = ?
    ");

    if (!$stmt) {
        return [
            'total_committed' => 0,
            'total_disbursed' => 0,
            'total_pending' => 0,
            'total_remaining' => 0,
        ];
    }

    $stmt->bind_param('i', $ventureId);
    $stmt->execute();

    $summary = $stmt->get_result()->fetch_assoc() ?: [
        'total_committed' => 0,
        'total_disbursed' => 0,
        'total_pending' => 0,
    ];

    $stmt->close();

    $summary['total_remaining'] =
        (float)$summary['total_committed']
        - (float)$summary['total_disbursed'];

    return $summary;
}

function get_all_ventures_fund_summary(mysqli $conn): array
{
    $sql = "
        SELECT
            v.id AS venture_id,
            v.name AS venture_name,
            la.status AS loa_status,
            COALESCE(SUM(gt.amount), 0) AS total_committed,
            COALESCE(
                SUM(
                    CASE
                        WHEN gt.status = 'disbursed'
                        THEN gt.amount
                        ELSE 0
                    END
                ),
                0
            ) AS total_disbursed,
            COALESCE(
                SUM(
                    CASE
                        WHEN gt.status = 'submitted'
                        THEN gt.amount
                        ELSE 0
                    END
                ),
                0
            ) AS pending_review,
            COALESCE(
                SUM(
                    CASE
                        WHEN gt.status = 'approved'
                        THEN gt.amount
                        ELSE 0
                    END
                ),
                0
            ) AS awaiting_disbursement
        FROM ventures v
        LEFT JOIN grant_tranches gt
          ON gt.venture_id = v.id
        LEFT JOIN loa_agreements la
          ON la.venture_id = v.id
        GROUP BY
            v.id,
            v.name,
            la.status
        ORDER BY v.name ASC
    ";

    $result = $conn->query($sql);

    return $result
        ? $result->fetch_all(MYSQLI_ASSOC)
        : [];
}

function unlock_next_tranche(
    mysqli $conn,
    int $ventureId,
    int $justDisbursedTrancheId
): void {
    $stmt = $conn->prepare(
        'SELECT sort_order FROM grant_tranches WHERE id = ? LIMIT 1'
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'i',
        $justDisbursedTrancheId
    );

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return;
    }

    $nextOrder =
        (int)$row['sort_order']
        + 1;

    $stmt = $conn->prepare("
        UPDATE grant_tranches
        SET status = 'open'
        WHERE venture_id = ?
          AND sort_order = ?
          AND status = 'locked'
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'ii',
        $ventureId,
        $nextOrder
    );

    $stmt->execute();
    $stmt->close();
}

function grant_allowed_tranche_statuses(): array
{
    return [
        'locked',
        'open',
        'submitted',
        'changes_requested',
        'approved',
        'rejected',
        'disbursed',
    ];
}

function grant_normalize_tranche_status(string $status): string
{
    $status =
        strtolower(
            trim($status)
        );

    return in_array(
        $status,
        grant_allowed_tranche_statuses(),
        true
    )
        ? $status
        : '';
}

function grant_resubmission_allowed(string $status): bool
{
    return in_array(
        strtolower(trim($status)),
        [
            'open',
            'changes_requested',
            'rejected',
        ],
        true
    );
}
