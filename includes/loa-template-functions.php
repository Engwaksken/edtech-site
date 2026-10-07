<?php

declare(strict_types=1);

const LOA_TEMPLATE_REQUIRED_PLACEHOLDERS = [
    'FELLOW_LEGAL_NAME',
    'ENTITY_TYPE',
    'FELLOW_ADDRESS',
    'REP_TITLE',
    'REP_NAME',
    'EFFECTIVE_DATE',
    'END_DATE',
    'GRANT_TOTAL',
    'GRANT_TOTAL_WORDS',
];

const LOA_TEMPLATE_MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const LOA_TEMPLATE_MIME_PDF  = 'application/pdf';

function loa_template_mime_for_extension(string $ext): ?string
{
    return match (strtolower($ext)) {
        'docx'  => LOA_TEMPLATE_MIME_DOCX,
        'pdf'   => LOA_TEMPLATE_MIME_PDF,
        default => null,
    };
}

function loa_template_is_docx(string $mimeType): bool
{
    return $mimeType === LOA_TEMPLATE_MIME_DOCX;
}

function loa_template_is_pdf(string $mimeType): bool
{
    return $mimeType === LOA_TEMPLATE_MIME_PDF;
}


function loa_template_icon_class(string $mimeType): string
{
    if (loa_template_is_pdf($mimeType)) {
        return 'fa-solid fa-file-pdf';
    }
    if (loa_template_is_docx($mimeType)) {
        return 'fa-solid fa-file-word';
    }
    return 'fa-solid fa-file';
}

function get_active_loa_template(mysqli $conn, bool $withBlob = false): ?array
{
    $cols = $withBlob
        ? '*'
        : 'id, name, file_name, mime_type, file_size, version, is_active, uploaded_by, notes, created_at';

    $res = $conn->query("SELECT {$cols} FROM loa_templates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    if (!$res) {
        return null;
    }
    return $res->fetch_assoc() ?: null;
}

function get_loa_template_by_id(mysqli $conn, int $id, bool $withBlob = true): ?array
{
    $cols = $withBlob
        ? '*'
        : 'id, name, file_name, mime_type, file_size, version, is_active, uploaded_by, notes, created_at';

    $stmt = $conn->prepare("SELECT {$cols} FROM loa_templates WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/** Full version history, newest first. Never includes the blob. */
function list_loa_templates(mysqli $conn): array
{
    $res = $conn->query("
        SELECT id, name, file_name, mime_type, file_size, version, is_active, uploaded_by, notes, created_at
        FROM loa_templates
        ORDER BY version DESC, id DESC
    ");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Pull the {{TOKENS}} out of a docx's word/document.xml so we can verify
 * an uploaded template still has every placeholder the generator expects.
 * Word frequently splits text across multiple <w:r> runs, so tags are
 * stripped before matching — this is a heuristic, not a full XML parse.
 *
 * Only meaningful for .docx files — returns an empty array for anything
 * else (there's no equivalent structure in a PDF to extract tokens from).
 */
function extract_loa_template_placeholders(string $binaryDocx): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'loa_tpl_check_');
    if ($tmp === false) {
        return [];
    }
    file_put_contents($tmp, $binaryDocx);

    $placeholders = [];
    $zip = new ZipArchive();
    if ($zip->open($tmp) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml !== false) {
            $stripped = preg_replace('/<[^>]+>/', '', $xml);
            preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', (string)$stripped, $m);
            $placeholders = array_values(array_unique($m[1]));
        }
    }

    @unlink($tmp);
    return $placeholders;
}


function missing_loa_template_placeholders(string $binaryDocx, string $mimeType = LOA_TEMPLATE_MIME_DOCX): array
{
    if (!loa_template_is_docx($mimeType)) {
        return [];
    }

    $found = extract_loa_template_placeholders($binaryDocx);
    return array_values(array_diff(LOA_TEMPLATE_REQUIRED_PLACEHOLDERS, $found));
}


function save_new_loa_template(
    mysqli $conn,
    string $name,
    string $fileName,
    string $binaryData,
    string $mimeType,
    ?int $uploadedBy,
    ?string $notes,
    bool $activate = true
): int {
    $maxVersion = 0;
    $res = $conn->query('SELECT MAX(version) AS v FROM loa_templates');
    if ($res) {
        $maxVersion = (int)($res->fetch_assoc()['v'] ?? 0);
    }
    $version  = $maxVersion + 1;
    $fileSize = strlen($binaryData);
    $isActive = $activate ? 1 : 0;

    if ($activate) {
        $conn->query('UPDATE loa_templates SET is_active = 0 WHERE is_active = 1');
    }

    $stmt = $conn->prepare('
        INSERT INTO loa_templates
            (name, file_name, mime_type, file_data, file_size, version, is_active, uploaded_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param(
        'ssssiiiis',
        $name, $fileName, $mimeType, $binaryData, $fileSize, $version, $isActive, $uploadedBy, $notes
    );
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    return $newId;
}

/** Make an existing version the active one (e.g. rollback). */
function activate_loa_template(mysqli $conn, int $templateId): bool
{
    $conn->begin_transaction();
    try {
        $conn->query('UPDATE loa_templates SET is_active = 0 WHERE is_active = 1');

        $stmt = $conn->prepare('UPDATE loa_templates SET is_active = 1 WHERE id = ?');
        $stmt->bind_param('i', $templateId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();
        return $affected > 0;
    } catch (\Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}


function get_active_loa_template_tmp_path(mysqli $conn): ?string
{
    $tpl = get_active_loa_template($conn, true);

    if (!$tpl && defined('LOA_TEMPLATE_PATH') && file_exists(LOA_TEMPLATE_PATH)) {
        $binary = file_get_contents(LOA_TEMPLATE_PATH);
        if ($binary !== false && $binary !== '') {
            save_new_loa_template(
                $conn,
                'Standard LoA Template',
                basename(LOA_TEMPLATE_PATH),
                $binary,
                LOA_TEMPLATE_MIME_DOCX,
                null,
                'Auto-seeded from assets/loa_template.docx on first use.',
                true
            );
            $tpl = get_active_loa_template($conn, true);
        }
    }

    if (!$tpl) {
        return null;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'loa_tpl_') . '.docx';
    file_put_contents($tmpPath, $tpl['file_data']);
    return $tmpPath;
}