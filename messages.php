<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$page_title  = 'Messages';
$current_nav = 'messages.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$venture_id = (int)($venture_id ?? $_SESSION['venture_id'] ?? $_SESSION['user_id'] ?? 0);

if ($venture_id <= 0) {
    die('Invalid venture session.');
}

$venture_name = trim(
    (string)(
        $VENTURE['name']
        ?? $_SESSION['venture_name']
        ?? $_SESSION['name']
        ?? 'Venture'
    )
);

$folder = strtolower(trim((string)($_GET['folder'] ?? 'inbox')));

$allowed_folders = [
    'inbox',
    'sent',
    'drafts',
    'all',
];

if (!in_array($folder, $allowed_folders, true)) {
    $folder = 'inbox';
}

$search = trim((string)($_GET['q'] ?? ''));
$active_thread = trim((string)($_GET['thread'] ?? ''));
$draft_id = max(0, (int)($_GET['draft'] ?? 0));

function msg_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function msg_format_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    $ts = strtotime($date);

    if (!$ts) {
        return '';
    }

    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('g:i A', $ts);
    }

    if (date('Y', $ts) === date('Y')) {
        return date('M j', $ts);
    }

    return date('M j, Y', $ts);
}

function msg_initials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'M';
    }

    $parts = preg_split('/\s+/', $name) ?: [$name];

    if (count($parts) >= 2) {
        return strtoupper(
            mb_substr($parts[0], 0, 1)
            . mb_substr($parts[1], 0, 1)
        );
    }

    return strtoupper(mb_substr($name, 0, 2));
}

function msg_resolve_party(
    mysqli $conn,
    string $type,
    int $id,
    string $ventureName
): string {
    if ($type === 'venture') {
        return $ventureName;
    }

    if ($type === 'mentor' && $id > 0) {
        $stmt = $conn->prepare("
            SELECT full_name
            FROM mentors
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['full_name'])) {
                return (string)$row['full_name'];
            }
        }

        return 'Mentor';
    }

    return 'Programme Team';
}

function msg_ensure_draft_table(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS venture_message_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            venture_id INT UNSIGNED NOT NULL,
            recipient_mentor_id INT UNSIGNED NOT NULL DEFAULT 0,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vmd_venture_updated (venture_id, updated_at),
            KEY idx_vmd_recipient (recipient_mentor_id)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
    ");
}

msg_ensure_draft_table($conn);

/* --------------------------------------------------------------------------
   Mark opened conversation as read
   -------------------------------------------------------------------------- */

if ($active_thread !== '') {
    $stmt = $conn->prepare("
        UPDATE mentor_messages
        SET is_read = 1
        WHERE thread_id = ?
          AND recipient_type = 'venture'
          AND recipient_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param(
            'si',
            $active_thread,
            $venture_id
        );

        $stmt->execute();
        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Folder counts
   -------------------------------------------------------------------------- */

$count_inbox = 0;
$count_sent = 0;
$count_all = 0;
$count_drafts = 0;
$total_unread = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT CASE
            WHEN recipient_type = 'venture'
             AND recipient_id = ?
            THEN thread_id
        END) AS inbox_count,

        COUNT(DISTINCT CASE
            WHEN sender_type = 'venture'
             AND sender_id = ?
            THEN thread_id
        END) AS sent_count,

        COUNT(DISTINCT thread_id) AS all_count,

        SUM(
            is_read = 0
            AND recipient_type = 'venture'
            AND recipient_id = ?
        ) AS unread_count
    FROM mentor_messages
    WHERE (
            sender_type = 'venture'
        AND sender_id = ?
    )
    OR (
            recipient_type = 'venture'
        AND recipient_id = ?
    )
");

if ($stmt) {
    $stmt->bind_param(
        'iiiii',
        $venture_id,
        $venture_id,
        $venture_id,
        $venture_id,
        $venture_id
    );

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $count_inbox = (int)($row['inbox_count'] ?? 0);
    $count_sent = (int)($row['sent_count'] ?? 0);
    $count_all = (int)($row['all_count'] ?? 0);
    $total_unread = (int)($row['unread_count'] ?? 0);
}

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM venture_message_drafts
    WHERE venture_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $venture_id);
    $stmt->execute();
    $count_drafts = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
}

/* --------------------------------------------------------------------------
   Assigned mentors
   -------------------------------------------------------------------------- */

$cohort_id = (int)($VENTURE['cohort_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Compose recipients
|--------------------------------------------------------------------------
| The venture can select any active mentor from the programme.
|--------------------------------------------------------------------------
*/

$mentor_rows = [];

$mentor_result = $conn->query("
    SELECT
        id,
        full_name
    FROM mentors
    WHERE status = 'active'
    ORDER BY full_name ASC
");

if ($mentor_result instanceof mysqli_result) {
    while ($r = $mentor_result->fetch_assoc()) {
        $mentor_rows[] = $r;
    }
}


/* --------------------------------------------------------------------------
   Conversation rows
   -------------------------------------------------------------------------- */

$thread_rows = [];

if ($folder !== 'drafts') {
    $folder_where = '';

    if ($folder === 'inbox') {
        $folder_where = "
            AND EXISTS (
                SELECT 1
                FROM mentor_messages inbox_m
                WHERE inbox_m.thread_id = mm.thread_id
                  AND inbox_m.recipient_type = 'venture'
                  AND inbox_m.recipient_id = ?
            )
        ";
    } elseif ($folder === 'sent') {
        $folder_where = "
            AND EXISTS (
                SELECT 1
                FROM mentor_messages sent_m
                WHERE sent_m.thread_id = mm.thread_id
                  AND sent_m.sender_type = 'venture'
                  AND sent_m.sender_id = ?
            )
        ";
    }

    $search_where = '';

    if ($search !== '') {
        $search_where = "
            AND (
                EXISTS (
                    SELECT 1
                    FROM mentor_messages sm
                    WHERE sm.thread_id = mm.thread_id
                      AND (
                            sm.subject LIKE ?
                         OR sm.body LIKE ?
                      )
                )
            )
        ";
    }

    $sql = "
        SELECT
            mm.thread_id,
            MAX(mm.created_at) AS last_at,

            SUM(
                mm.is_read = 0
                AND mm.recipient_type = 'venture'
                AND mm.recipient_id = ?
            ) AS unread,

            (
                SELECT m1.subject
                FROM mentor_messages m1
                WHERE m1.thread_id = mm.thread_id
                  AND m1.subject IS NOT NULL
                  AND m1.subject <> ''
                ORDER BY m1.id ASC
                LIMIT 1
            ) AS subject,

            (
                SELECT m2.body
                FROM mentor_messages m2
                WHERE m2.thread_id = mm.thread_id
                ORDER BY m2.id DESC
                LIMIT 1
            ) AS last_body,

            (
                SELECT m3.sender_type
                FROM mentor_messages m3
                WHERE m3.thread_id = mm.thread_id
                ORDER BY m3.id DESC
                LIMIT 1
            ) AS last_sender_type,

            (
                SELECT m4.sender_id
                FROM mentor_messages m4
                WHERE m4.thread_id = mm.thread_id
                ORDER BY m4.id DESC
                LIMIT 1
            ) AS last_sender_id,

            (
                SELECT m5.recipient_type
                FROM mentor_messages m5
                WHERE m5.thread_id = mm.thread_id
                ORDER BY m5.id DESC
                LIMIT 1
            ) AS last_recipient_type,

            (
                SELECT m6.recipient_id
                FROM mentor_messages m6
                WHERE m6.thread_id = mm.thread_id
                ORDER BY m6.id DESC
                LIMIT 1
            ) AS last_recipient_id

        FROM mentor_messages mm

        WHERE (
                mm.sender_type = 'venture'
            AND mm.sender_id = ?
        )
        OR (
                mm.recipient_type = 'venture'
            AND mm.recipient_id = ?
        )
    ";

    /*
     * Wrap the venture ownership condition so folder/search filters apply to
     * the entire conversation set.
     */
    $sql = "
        SELECT grouped.*
        FROM (
            {$sql}
            GROUP BY mm.thread_id
        ) grouped
        WHERE 1=1
    ";

    $outer_conditions = [];
    $outer_params = [];
    $outer_types = '';

    if ($folder === 'inbox') {
        $outer_conditions[] = "
            EXISTS (
                SELECT 1
                FROM mentor_messages inbox_m
                WHERE inbox_m.thread_id = grouped.thread_id
                  AND inbox_m.recipient_type = 'venture'
                  AND inbox_m.recipient_id = ?
            )
        ";
        $outer_params[] = $venture_id;
        $outer_types .= 'i';
    } elseif ($folder === 'sent') {
        $outer_conditions[] = "
            EXISTS (
                SELECT 1
                FROM mentor_messages sent_m
                WHERE sent_m.thread_id = grouped.thread_id
                  AND sent_m.sender_type = 'venture'
                  AND sent_m.sender_id = ?
            )
        ";
        $outer_params[] = $venture_id;
        $outer_types .= 'i';
    }

    if ($search !== '') {
        $outer_conditions[] = "
            (
                grouped.subject LIKE ?
                OR grouped.last_body LIKE ?
            )
        ";

        $like = '%' . $search . '%';

        $outer_params[] = $like;
        $outer_params[] = $like;
        $outer_types .= 'ss';
    }

    if ($outer_conditions) {
        $sql .= "
            AND "
            . implode(
                ' AND ',
                $outer_conditions
            );
    }

    $sql .= "
        ORDER BY grouped.last_at DESC
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $params = [
            $venture_id,
            $venture_id,
            $venture_id,
            ...$outer_params,
        ];

        $types = 'iii' . $outer_types;

        $stmt->bind_param(
            $types,
            ...$params
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $last_sender_type = (string)($row['last_sender_type'] ?? '');
            $last_sender_id = (int)($row['last_sender_id'] ?? 0);
            $last_recipient_type = (string)($row['last_recipient_type'] ?? '');
            $last_recipient_id = (int)($row['last_recipient_id'] ?? 0);

            if (
                $last_sender_type === 'venture'
                && $last_sender_id === $venture_id
            ) {
                $party_type = $last_recipient_type;
                $party_id = $last_recipient_id;
                $direction = 'sent';
            } else {
                $party_type = $last_sender_type;
                $party_id = $last_sender_id;
                $direction = 'received';
            }

            $row['party_name'] = msg_resolve_party(
                $conn,
                $party_type,
                $party_id,
                $venture_name
            );

            $row['direction'] = $direction;
            $thread_rows[] = $row;
        }

        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Draft rows
   -------------------------------------------------------------------------- */

$draft_rows = [];

if ($folder === 'drafts') {
    $sql = "
        SELECT *
        FROM venture_message_drafts
        WHERE venture_id = ?
    ";

    $params = [$venture_id];
    $types = 'i';

    if ($search !== '') {
        $sql .= "
            AND (
                subject LIKE ?
                OR body LIKE ?
            )
        ";

        $like = '%' . $search . '%';

        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $sql .= "
        ORDER BY updated_at DESC
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param(
            $types,
            ...$params
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($r = $result->fetch_assoc()) {
            $recipient_id = (int)($r['recipient_mentor_id'] ?? 0);

            $r['party_name'] = $recipient_id > 0
                ? msg_resolve_party(
                    $conn,
                    'mentor',
                    $recipient_id,
                    $venture_name
                )
                : 'Programme Team';

            $draft_rows[] = $r;
        }

        $stmt->close();
    }
}

/* --------------------------------------------------------------------------
   Active thread
   -------------------------------------------------------------------------- */

$thread_messages = [];
$thread_subject = '';
$thread_party_name = '';
$thread_party_type = '';
$thread_party_id = 0;

if ($active_thread !== '') {
    $stmt = $conn->prepare("
        SELECT *
        FROM mentor_messages
        WHERE thread_id = ?
          AND (
                (
                    sender_type = 'venture'
                    AND sender_id = ?
                )
                OR
                (
                    recipient_type = 'venture'
                    AND recipient_id = ?
                )
          )
        ORDER BY id ASC
    ");

    if ($stmt) {
        $stmt->bind_param(
            'sii',
            $active_thread,
            $venture_id,
            $venture_id
        );

        $stmt->execute();
        $result = $stmt->get_result();

        while ($r = $result->fetch_assoc()) {
            $thread_messages[] = $r;

            if (
                $thread_subject === ''
                && !empty($r['subject'])
            ) {
                $thread_subject = (string)$r['subject'];
            }

            if (
                (string)$r['sender_type'] !== 'venture'
            ) {
                $thread_party_type = (string)$r['sender_type'];
                $thread_party_id = (int)$r['sender_id'];
            } elseif (
                (string)$r['recipient_type'] !== 'venture'
            ) {
                $thread_party_type = (string)$r['recipient_type'];
                $thread_party_id = (int)$r['recipient_id'];
            }
        }

        $stmt->close();
    }

    $thread_party_name = msg_resolve_party(
        $conn,
        $thread_party_type,
        $thread_party_id,
        $venture_name
    );
}

/* --------------------------------------------------------------------------
   Active draft
   -------------------------------------------------------------------------- */

$active_draft = null;

if ($draft_id > 0) {
    $stmt = $conn->prepare("
        SELECT *
        FROM venture_message_drafts
        WHERE id = ?
          AND venture_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $draft_id,
            $venture_id
        );

        $stmt->execute();
        $active_draft = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

include __DIR__ . '/layout.php';
?>

<style>
:root{
    --mail-border:#e5e7eb;
    --mail-muted:#64748b;
    --mail-text:#1f2937;
    --mail-surface:#f8fafc;
    --mail-hover:#f1f5f9;
    --mail-primary:#f4512c;
    --mail-primary-soft:#fff2ed;
    --mail-sidebar:#f8fafc;
}

.mail-shell{
    width:100%;
    min-height:calc(100vh - 170px);
    display:grid;
    grid-template-columns:230px minmax(0,1fr);
    overflow:hidden;
    border:1px solid var(--mail-border);
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 26px rgba(15,23,42,.05);
}

.mail-sidebar{
    padding:16px 12px;
    background:var(--mail-sidebar);
    border-right:1px solid var(--mail-border);
}

.mail-compose-btn{
    width:100%;
    min-height:46px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    border:0;
    border-radius:13px;
    background:#fff;
    color:#c2410c;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 4px 12px rgba(15,23,42,.08);
}

.mail-compose-btn:hover{
    background:#fff7ed;
}

.mail-nav{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.mail-nav-link{
    min-height:40px;
    padding:8px 11px;
    display:flex;
    align-items:center;
    gap:11px;
    border-radius:0 18px 18px 0;
    color:#475569;
    font-size:13px;
    font-weight:650;
    text-decoration:none!important;
}

.mail-nav-link:hover{
    background:#eef2f7;
    color:#1f2937;
}

.mail-nav-link.active{
    background:#fee2e2;
    color:#9f1239;
    font-weight:800;
}

.mail-nav-link i{
    width:18px;
    text-align:center;
}

.mail-nav-count{
    margin-left:auto;
    min-width:22px;
    height:20px;
    padding:0 6px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:rgba(15,23,42,.07);
    font-size:10px;
    font-weight:850;
}

.mail-nav-link.active .mail-nav-count{
    background:rgba(159,18,57,.10);
}

.mail-main{
    min-width:0;
    display:flex;
    flex-direction:column;
    background:#fff;
}

.mail-topbar{
    min-height:68px;
    padding:12px 16px;
    display:flex;
    align-items:center;
    gap:12px;
    border-bottom:1px solid var(--mail-border);
}

.mail-title-wrap{
    min-width:180px;
}

.mail-title{
    margin:0;
    color:var(--mail-text);
    font-family:'Syne',sans-serif;
    font-size:18px;
    font-weight:850;
}

.mail-subtitle{
    margin:2px 0 0;
    color:#94a3b8;
    font-size:11px;
}

.mail-search{
    position:relative;
    flex:1 1 auto;
    max-width:620px;
}

.mail-search i{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#94a3b8;
}

.mail-search input{
    width:100%;
    height:42px;
    padding:8px 14px 8px 38px;
    border:1px solid transparent;
    border-radius:12px;
    background:#f1f5f9;
    color:var(--mail-text);
    outline:none;
    font:inherit;
}

.mail-search input:focus{
    border-color:#fdba74;
    background:#fff;
    box-shadow:0 0 0 3px rgba(249,115,22,.08);
}

.mail-content{
    min-height:0;
    flex:1 1 auto;
    display:grid;
    grid-template-columns:minmax(420px,46%) minmax(0,54%);
}

.mail-list-pane{
    min-width:0;
    overflow:auto;
    border-right:1px solid var(--mail-border);
}

.mail-list-head{
    position:sticky;
    top:0;
    z-index:3;
    min-height:42px;
    padding:8px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    background:rgba(255,255,255,.96);
    border-bottom:1px solid var(--mail-border);
    backdrop-filter:blur(8px);
}

.mail-list-head strong{
    color:#475569;
    font-size:11px;
    letter-spacing:.04em;
    text-transform:uppercase;
}

.mail-row{
    min-height:72px;
    padding:10px 14px;
    display:grid;
    grid-template-columns:38px minmax(0,1fr) auto;
    gap:10px;
    align-items:center;
    color:inherit;
    text-decoration:none!important;
    border-bottom:1px solid #f1f5f9;
    background:#fff;
    transition:background .12s ease;
}

.mail-row:hover{
    background:#f8fafc;
}

.mail-row.active{
    background:#fff7ed;
    box-shadow:inset 3px 0 0 var(--mail-primary);
}

.mail-row.unread{
    background:#f8fbff;
}

.mail-row.unread .mail-row-party,
.mail-row.unread .mail-row-subject{
    color:#111827;
    font-weight:850;
}

.mail-avatar{
    width:36px;
    height:36px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:linear-gradient(135deg,#f4512c,#f59e0b);
    color:#fff;
    font-size:11px;
    font-weight:850;
}

.mail-row-body{
    min-width:0;
}

.mail-row-top{
    display:flex;
    align-items:center;
    gap:8px;
}

.mail-row-party{
    min-width:0;
    flex:1;
    overflow:hidden;
    color:#334155;
    font-size:12px;
    font-weight:700;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.mail-direction{
    color:#94a3b8;
    font-size:9px;
    font-weight:750;
    text-transform:uppercase;
}

.mail-row-subject{
    margin-top:2px;
    overflow:hidden;
    color:#475569;
    font-size:12.5px;
    font-weight:650;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.mail-row-preview{
    margin-top:2px;
    overflow:hidden;
    color:#94a3b8;
    font-size:11px;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.mail-row-date{
    align-self:start;
    padding-top:2px;
    color:#94a3b8;
    font-size:10px;
    white-space:nowrap;
}

.mail-unread-dot{
    width:7px;
    height:7px;
    display:inline-block;
    border-radius:50%;
    background:#2563eb;
}

.mail-reader{
    min-width:0;
    min-height:0;
    display:flex;
    flex-direction:column;
    background:#fff;
}

.mail-reader-empty{
    height:100%;
    min-height:420px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    gap:9px;
    color:#94a3b8;
    text-align:center;
    background:#fafafa;
}

.mail-reader-empty i{
    font-size:44px;
    color:#cbd5e1;
}

.mail-reader-head{
    padding:18px 20px;
    border-bottom:1px solid var(--mail-border);
}

.mail-reader-head h2{
    margin:0;
    color:#1f2937;
    font-family:'Syne',sans-serif;
    font-size:18px;
    line-height:1.35;
}

.mail-reader-meta{
    margin-top:6px;
    display:flex;
    align-items:center;
    gap:8px;
    color:#64748b;
    font-size:11px;
}

.mail-reader-messages{
    min-height:0;
    flex:1 1 auto;
    overflow:auto;
    padding:10px 20px 18px;
}

.mail-message{
    padding:14px 0;
    border-bottom:1px solid #f1f5f9;
}

.mail-message:last-child{
    border-bottom:0;
}

.mail-message-head{
    display:flex;
    align-items:flex-start;
    gap:10px;
}

.mail-message-meta{
    min-width:0;
    flex:1;
}

.mail-message-name{
    color:#1f2937;
    font-size:12.5px;
    font-weight:800;
}

.mail-message-to{
    margin-top:1px;
    color:#94a3b8;
    font-size:10px;
}

.mail-message-date{
    color:#94a3b8;
    font-size:10px;
    white-space:nowrap;
}

.mail-message-body{
    margin:10px 0 0 46px;
    color:#334155;
    font-size:13.5px;
    line-height:1.7;
    white-space:normal;
    overflow-wrap:anywhere;
}

.mail-reply{
    padding:12px 20px;
    border-top:1px solid var(--mail-border);
    background:#fff;
}

.mail-reply textarea{
    width:100%;
    min-height:78px;
    padding:11px 12px;
    border:1px solid var(--mail-border);
    border-radius:10px;
    resize:vertical;
    outline:none;
    font:inherit;
    font-size:13px;
}

.mail-reply textarea:focus{
    border-color:#fdba74;
    box-shadow:0 0 0 3px rgba(249,115,22,.07);
}

.mail-reply-actions{
    margin-top:8px;
    display:flex;
    justify-content:flex-end;
}

.mail-empty-list{
    padding:52px 20px;
    text-align:center;
    color:#94a3b8;
}

.mail-empty-list i{
    display:block;
    margin-bottom:10px;
    font-size:32px;
    color:#cbd5e1;
}

.mail-flash{
    margin:0 0 14px;
    padding:11px 14px;
    border:1px solid #bbf7d0;
    border-radius:10px;
    background:#ecfdf5;
    color:#047857;
    font-size:12.5px;
}

.mail-flash.error{
    border-color:#fecaca;
    background:#fef2f2;
    color:#b91c1c;
}

/* Compose modal */
.mail-compose-overlay{
    position:fixed;
    inset:0;
    z-index:1000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:rgba(15,23,42,.42);
    backdrop-filter:blur(4px);
}

.mail-compose-overlay.open{
    display:flex;
}

.mail-compose{
    width:min(680px,calc(100vw - 40px));
    max-height:calc(100vh - 48px);
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.55);
    border-radius:16px;
    background:#fff;
    box-shadow:0 30px 80px rgba(15,23,42,.28);
    animation:mailComposeIn .18s ease-out;
}

@keyframes mailComposeIn{
    from{
        opacity:0;
        transform:translateY(10px) scale(.985);
    }
    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}

.mail-compose-head{
    min-height:54px;
    padding:12px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    background:#1f2937;
    color:#fff;
}

.mail-compose-head strong{
    font-size:13px;
    font-weight:800;
}

.mail-compose-close{
    width:32px;
    height:32px;
    display:grid;
    place-items:center;
    border:0;
    border-radius:8px;
    background:transparent;
    color:#cbd5e1;
    cursor:pointer;
}

.mail-compose-close:hover{
    background:rgba(255,255,255,.08);
    color:#fff;
}

.mail-compose-fields{
    overflow:auto;
    background:#fff;
}

.mail-compose-line{
    min-height:50px;
    padding:7px 16px;
    display:grid;
    grid-template-columns:70px minmax(0,1fr);
    align-items:center;
    gap:10px;
    border-bottom:1px solid var(--mail-border);
}

.mail-compose-line label{
    width:auto;
    color:#64748b;
    font-size:10.5px;
    font-weight:800;
    letter-spacing:.04em;
    text-transform:uppercase;
}

.mail-compose-line input,
.mail-compose-line select{
    width:100%;
    min-width:0;
    min-height:38px;
    padding:7px 10px;
    border:1px solid #dbe3ee;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#1f2937;
    font:inherit;
    font-size:13px;
}

.mail-compose-line input:focus,
.mail-compose-line select:focus{
    border-color:#fdba74;
    box-shadow:0 0 0 3px rgba(249,115,22,.08);
}

.mail-compose-body{
    min-height:260px;
    width:100%;
    padding:16px;
    border:0;
    outline:0;
    resize:vertical;
    color:#1f2937;
    font:inherit;
    font-size:13.5px;
    line-height:1.7;
}

.mail-compose-foot{
    min-height:58px;
    padding:10px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-top:1px solid var(--mail-border);
    background:#fff;
}

.mail-compose-actions{
    display:flex;
    align-items:center;
    gap:8px;
}

.mail-btn{
    min-height:38px;
    padding:8px 14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid var(--mail-border);
    border-radius:9px;
    background:#fff;
    color:#475569;
    font-weight:750;
    cursor:pointer;
}

.mail-btn.primary{
    border-color:var(--mail-primary);
    background:var(--mail-primary);
    color:#fff;
}

.mail-btn.danger{
    color:#b91c1c;
    border-color:#fecaca;
    background:#fef2f2;
}

.mail-btn:hover{
    filter:brightness(.98);
}

.mail-draft-label{
    color:#dc2626;
    font-size:10px;
    font-weight:850;
}

@media(max-width:1050px){
    .mail-shell{
        grid-template-columns:190px minmax(0,1fr);
    }

    .mail-content{
        grid-template-columns:minmax(340px,44%) minmax(0,56%);
    }
}

@media(max-width:820px){
    .mail-shell{
        grid-template-columns:1fr;
    }

    .mail-sidebar{
        padding:10px;
        border-right:0;
        border-bottom:1px solid var(--mail-border);
    }

    .mail-compose-btn{
        width:auto;
        margin-bottom:10px;
        padding-inline:18px;
    }

    .mail-nav{
        flex-direction:row;
        overflow:auto;
    }

    .mail-nav-link{
        flex:0 0 auto;
        border-radius:18px;
        white-space:nowrap;
    }

    .mail-content{
        grid-template-columns:1fr;
    }

    .mail-list-pane{
        border-right:0;
    }

    .mail-reader{
        min-height:420px;
        border-top:1px solid var(--mail-border);
    }
}

@media(max-width:600px){
    .mail-topbar{
        align-items:stretch;
        flex-direction:column;
    }

    .mail-title-wrap{
        min-width:0;
    }

    .mail-search{
        width:100%;
        max-width:none;
    }

    .mail-row{
        grid-template-columns:34px minmax(0,1fr);
    }

    .mail-row-date{
        display:none;
    }

    .mail-message-body{
        margin-left:0;
    }

    .mail-compose-overlay{
        align-items:flex-end;
        padding:0;
    }

    .mail-compose{
        width:100vw;
        max-height:94vh;
        border-radius:16px 16px 0 0;
    }

    .mail-compose-line{
        grid-template-columns:58px minmax(0,1fr);
        padding-inline:12px;
    }

    .mail-compose-body{
        min-height:220px;
    }
}
</style>

<?php
$flash = $_SESSION['messages_flash'] ?? null;
unset($_SESSION['messages_flash']);
?>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
    <div class="mail-flash <?= ($flash['type'] ?? '') === 'error' ? 'error' : '' ?>">
        <i class="fas <?= ($flash['type'] ?? '') === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
        <?= msg_h($flash['message']) ?>
    </div>
<?php endif; ?>

<div class="mail-shell">

    <aside class="mail-sidebar">

        <button
            type="button"
            class="mail-compose-btn"
            onclick="openMailCompose()"
        >
            <i class="fas fa-pen"></i>
            Compose
        </button>

        <nav class="mail-nav">

            <a
                href="messages.php?folder=inbox"
                class="mail-nav-link <?= $folder === 'inbox' ? 'active' : '' ?>"
            >
                <i class="fas fa-inbox"></i>
                Inbox

                <?php if ($total_unread > 0): ?>
                    <span class="mail-nav-count"><?= $total_unread ?></span>
                <?php elseif ($count_inbox > 0): ?>
                    <span class="mail-nav-count"><?= $count_inbox ?></span>
                <?php endif; ?>
            </a>

            <a
                href="messages.php?folder=sent"
                class="mail-nav-link <?= $folder === 'sent' ? 'active' : '' ?>"
            >
                <i class="fas fa-paper-plane"></i>
                Sent

                <?php if ($count_sent > 0): ?>
                    <span class="mail-nav-count"><?= $count_sent ?></span>
                <?php endif; ?>
            </a>

            <a
                href="messages.php?folder=drafts"
                class="mail-nav-link <?= $folder === 'drafts' ? 'active' : '' ?>"
            >
                <i class="fas fa-file-alt"></i>
                Drafts

                <?php if ($count_drafts > 0): ?>
                    <span class="mail-nav-count"><?= $count_drafts ?></span>
                <?php endif; ?>
            </a>

            <a
                href="messages.php?folder=all"
                class="mail-nav-link <?= $folder === 'all' ? 'active' : '' ?>"
            >
                <i class="fas fa-envelope-open-text"></i>
                All Mail

                <?php if ($count_all > 0): ?>
                    <span class="mail-nav-count"><?= $count_all ?></span>
                <?php endif; ?>
            </a>

        </nav>

    </aside>

    <main class="mail-main">

        <header class="mail-topbar">

            <div class="mail-title-wrap">
                <h1 class="mail-title">
                    <?= msg_h(
                        match ($folder) {
                            'sent' => 'Sent',
                            'drafts' => 'Drafts',
                            'all' => 'All Mail',
                            default => 'Inbox',
                        }
                    ) ?>
                </h1>

                <p class="mail-subtitle">
                    Messages with mentors and the programme team
                </p>
            </div>

            <form method="GET" class="mail-search">
                <input
                    type="hidden"
                    name="folder"
                    value="<?= msg_h($folder) ?>"
                >

                <i class="fas fa-search"></i>

                <input
                    type="search"
                    name="q"
                    value="<?= msg_h($search) ?>"
                    placeholder="Search mail"
                    autocomplete="off"
                >
            </form>

        </header>

        <section class="mail-content">

            <div class="mail-list-pane">

                <div class="mail-list-head">
                    <strong>
                        <?= $folder === 'drafts'
                            ? count($draft_rows) . ' draft' . (count($draft_rows) === 1 ? '' : 's')
                            : count($thread_rows) . ' conversation' . (count($thread_rows) === 1 ? '' : 's') ?>
                    </strong>

                    <?php if ($search !== ''): ?>
                        <a
                            href="messages.php?folder=<?= msg_h($folder) ?>"
                            class="mail-btn"
                            style="min-height:30px;padding:5px 9px;font-size:10px"
                        >
                            <i class="fas fa-times"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($folder === 'drafts'): ?>

                    <?php if (!$draft_rows): ?>
                        <div class="mail-empty-list">
                            <i class="far fa-file-alt"></i>
                            <strong>No drafts</strong>
                            <p>Your saved drafts will appear here.</p>
                        </div>
                    <?php else: ?>

                        <?php foreach ($draft_rows as $draft): ?>

                            <a
                                href="messages.php?folder=drafts&amp;draft=<?= (int)$draft['id'] ?>"
                                class="mail-row <?= $draft_id === (int)$draft['id'] ? 'active' : '' ?>"
                            >
                                <div class="mail-avatar">
                                    <?= msg_h(msg_initials((string)$draft['party_name'])) ?>
                                </div>

                                <div class="mail-row-body">

                                    <div class="mail-row-top">
                                        <span class="mail-row-party">
                                            <?= msg_h((string)$draft['party_name']) ?>
                                        </span>

                                        <span class="mail-draft-label">Draft</span>
                                    </div>

                                    <div class="mail-row-subject">
                                        <?= msg_h(
                                            trim((string)$draft['subject']) !== ''
                                                ? (string)$draft['subject']
                                                : '(no subject)'
                                        ) ?>
                                    </div>

                                    <div class="mail-row-preview">
                                        <?= msg_h(
                                            mb_strimwidth(
                                                trim((string)$draft['body']),
                                                0,
                                                90,
                                                '...'
                                            )
                                        ) ?>
                                    </div>

                                </div>

                                <div class="mail-row-date">
                                    <?= msg_h(msg_format_date((string)$draft['updated_at'])) ?>
                                </div>

                            </a>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php else: ?>

                    <?php if (!$thread_rows): ?>
                        <div class="mail-empty-list">
                            <i class="far fa-envelope-open"></i>
                            <strong>No messages</strong>
                            <p>No conversations match this folder.</p>
                        </div>
                    <?php else: ?>

                        <?php foreach ($thread_rows as $thread): ?>

                            <?php
                            $unread = (int)($thread['unread'] ?? 0) > 0;
                            $is_active = $active_thread === (string)$thread['thread_id'];
                            ?>

                            <a
                                href="messages.php?folder=<?= msg_h($folder) ?>&amp;thread=<?= rawurlencode((string)$thread['thread_id']) ?>"
                                class="mail-row <?= $unread ? 'unread' : '' ?> <?= $is_active ? 'active' : '' ?>"
                            >
                                <div class="mail-avatar">
                                    <?= msg_h(msg_initials((string)$thread['party_name'])) ?>
                                </div>

                                <div class="mail-row-body">

                                    <div class="mail-row-top">
                                        <span class="mail-row-party">
                                            <?= msg_h((string)$thread['party_name']) ?>
                                        </span>

                                        <?php if ($unread): ?>
                                            <span class="mail-unread-dot" title="Unread"></span>
                                        <?php endif; ?>

                                        <?php if (($thread['direction'] ?? '') === 'sent'): ?>
                                            <span class="mail-direction">
                                                Sent
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mail-row-subject">
                                        <?= msg_h(
                                            trim((string)($thread['subject'] ?? '')) !== ''
                                                ? (string)$thread['subject']
                                                : '(no subject)'
                                        ) ?>
                                    </div>

                                    <div class="mail-row-preview">
                                        <?= msg_h(
                                            mb_strimwidth(
                                                trim((string)($thread['last_body'] ?? '')),
                                                0,
                                                90,
                                                '...'
                                            )
                                        ) ?>
                                    </div>

                                </div>

                                <div class="mail-row-date">
                                    <?= msg_h(msg_format_date((string)$thread['last_at'])) ?>
                                </div>

                            </a>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

            <?php if ($folder === 'drafts' && $active_draft): ?>

                <div class="mail-reader">

                    <div class="mail-reader-head">
                        <h2>
                            <?= msg_h(
                                trim((string)$active_draft['subject']) !== ''
                                    ? (string)$active_draft['subject']
                                    : '(no subject)'
                            ) ?>
                        </h2>

                        <div class="mail-reader-meta">
                            <span class="mail-draft-label">Draft</span>
                            <span>
                                Updated
                                <?= msg_h(msg_format_date((string)$active_draft['updated_at'])) ?>
                            </span>
                        </div>
                    </div>

                    <div class="mail-reader-messages">

                        <div class="mail-message">

                            <div class="mail-message-head">

                                <div class="mail-avatar">
                                    <?= msg_h(msg_initials($venture_name)) ?>
                                </div>

                                <div class="mail-message-meta">
                                    <div class="mail-message-name">
                                        <?= msg_h($venture_name) ?>
                                    </div>

                                    <div class="mail-message-to">
                                        Draft message
                                    </div>
                                </div>

                            </div>

                            <div class="mail-message-body">
                                <?= nl2br(msg_h((string)$active_draft['body'])) ?>
                            </div>

                        </div>

                    </div>

                    <div class="mail-reply">
                        <div class="mail-reply-actions">
                            <button
                                type="button"
                                class="mail-btn primary"
                                onclick="openDraftCompose()"
                            >
                                <i class="fas fa-pen"></i>
                                Continue editing
                            </button>
                        </div>
                    </div>

                </div>

            <?php elseif ($active_thread !== '' && $thread_messages): ?>

                <div class="mail-reader">

                    <div class="mail-reader-head">

                        <h2>
                            <?= msg_h(
                                $thread_subject !== ''
                                    ? $thread_subject
                                    : 'Conversation'
                            ) ?>
                        </h2>

                        <div class="mail-reader-meta">
                            <i class="fas fa-user-circle"></i>
                            <?= msg_h($thread_party_name) ?>
                            <span>&middot;</span>
                            <?= count($thread_messages) ?>
                            message<?= count($thread_messages) === 1 ? '' : 's' ?>
                        </div>

                    </div>

                    <div
                        class="mail-reader-messages"
                        id="mailReaderScroll"
                    >

                        <?php foreach ($thread_messages as $message): ?>

                            <?php
                            $is_mine =
                                (string)$message['sender_type'] === 'venture'
                                && (int)$message['sender_id'] === $venture_id;

                            $sender_name = msg_resolve_party(
                                $conn,
                                (string)$message['sender_type'],
                                (int)$message['sender_id'],
                                $venture_name
                            );

                            $recipient_name = msg_resolve_party(
                                $conn,
                                (string)$message['recipient_type'],
                                (int)$message['recipient_id'],
                                $venture_name
                            );
                            ?>

                            <article class="mail-message">

                                <div class="mail-message-head">

                                    <div class="mail-avatar">
                                        <?= msg_h(msg_initials($sender_name)) ?>
                                    </div>

                                    <div class="mail-message-meta">

                                        <div class="mail-message-name">
                                            <?= msg_h($sender_name) ?>
                                        </div>

                                        <div class="mail-message-to">
                                            to <?= msg_h($recipient_name) ?>
                                        </div>

                                    </div>

                                    <div class="mail-message-date">
                                        <?= msg_h(
                                            date(
                                                'M j, Y, g:i A',
                                                strtotime((string)$message['created_at'])
                                            )
                                        ) ?>
                                    </div>

                                </div>

                                <div class="mail-message-body">
                                    <?= nl2br(msg_h((string)$message['body'])) ?>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                    <div class="mail-reply">

                        <form
                            method="POST"
                            action="includes/process-messages.php"
                        >
                            <input
                                type="hidden"
                                name="action"
                                value="reply"
                            >

                            <input
                                type="hidden"
                                name="thread_id"
                                value="<?= msg_h($active_thread) ?>"
                            >

                            <textarea
                                name="body"
                                placeholder="Reply to <?= msg_h($thread_party_name) ?>..."
                                required
                            ></textarea>

                            <div class="mail-reply-actions">
                                <button
                                    type="submit"
                                    class="mail-btn primary"
                                >
                                    <i class="fas fa-paper-plane"></i>
                                    Send
                                </button>
                            </div>

                        </form>

                    </div>

                </div>

            <?php else: ?>

                <div class="mail-reader">

                    <div class="mail-reader-empty">
                        <i class="far fa-envelope-open"></i>

                        <strong>
                            Select a message to read
                        </strong>

                        <span>
                            Choose a conversation from the list or compose a new message.
                        </span>
                    </div>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<!-- Compose -->
<div
    class="mail-compose-overlay <?= $active_draft ? 'open' : '' ?>"
    id="mailComposeOverlay"
>
    <div
        class="mail-compose"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mailComposeTitle"
    >

        <div class="mail-compose-head">

            <strong id="mailComposeTitle">
                <?= $active_draft ? 'Edit Draft' : 'New Message' ?>
            </strong>

            <button
                type="button"
                class="mail-compose-close"
                onclick="closeMailCompose()"
                aria-label="Close"
            >
                <i class="fas fa-times"></i>
            </button>

        </div>

        <form
            method="POST"
            action="includes/process-messages.php"
            id="mailComposeForm"
        >

            <input
                type="hidden"
                name="draft_id"
                id="mailDraftId"
                value="<?= (int)($active_draft['id'] ?? 0) ?>"
            >

            <div class="mail-compose-fields">

                <div class="mail-compose-line">

                    <label for="mailRecipient">
                        To
                    </label>

                    <select
                        name="recipient_mentor_id"
                        id="mailRecipient"
                        required
                    >
                        <option value="">
                            Select recipient
                        </option>

                        <?php foreach ($mentor_rows as $mentor): ?>
                            <option
                                value="<?= (int)$mentor['id'] ?>"
                                <?= (int)($active_draft['recipient_mentor_id'] ?? -1) === (int)$mentor['id']
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= msg_h((string)$mentor['full_name']) ?>
                            </option>
                        <?php endforeach; ?>

                        <option
                            value="0"
                            <?= isset($active_draft)
                                && (int)($active_draft['recipient_mentor_id'] ?? -1) === 0
                                ? 'selected'
                                : '' ?>
                        >
                            Programme Team
                        </option>

                    </select>

                </div>

                <div class="mail-compose-line">

                    <label for="mailSubject">
                        Subject
                    </label>

                    <input
                        type="text"
                        name="subject"
                        id="mailSubject"
                        value="<?= msg_h((string)($active_draft['subject'] ?? '')) ?>"
                        placeholder="Subject"
                    >

                </div>

                <textarea
                    name="body"
                    id="mailBody"
                    class="mail-compose-body"
                    placeholder="Write your message..."
                ><?= msg_h((string)($active_draft['body'] ?? '')) ?></textarea>

            </div>

            <div class="mail-compose-foot">

                <div class="mail-compose-actions">

                    <button
                        type="submit"
                        name="action"
                        value="send"
                        class="mail-btn primary"
                    >
                        <i class="fas fa-paper-plane"></i>
                        Send
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="save_draft"
                        class="mail-btn"
                    >
                        <i class="far fa-save"></i>
                        Save Draft
                    </button>

                </div>

                <?php if ($active_draft): ?>
                    <button
                        type="submit"
                        name="action"
                        value="delete_draft"
                        class="mail-btn danger"
                        formnovalidate
                    >
                        <i class="fas fa-trash"></i>
                    </button>
                <?php endif; ?>

            </div>

        </form>

    </div>
</div>

<script>
const mailComposeOverlay =
    document.getElementById(
        'mailComposeOverlay'
    );

function openMailCompose(){
    if (!mailComposeOverlay) return;

    mailComposeOverlay.classList.add(
        'open'
    );

    const subject =
        document.getElementById(
            'mailSubject'
        );

    if (subject) {
        setTimeout(
            function(){
                subject.focus();
            },
            80
        );
    }
}

function closeMailCompose(){
    if (!mailComposeOverlay) return;

    mailComposeOverlay.classList.remove(
        'open'
    );
}


if (mailComposeOverlay) {
    mailComposeOverlay.addEventListener(
        'click',
        function(event){
            if (event.target === mailComposeOverlay) {
                closeMailCompose();
            }
        }
    );
}

function openDraftCompose(){
    openMailCompose();
}

document.addEventListener(
    'keydown',
    function(event){
        if (
            event.key === 'Escape'
            && mailComposeOverlay
            && mailComposeOverlay.classList.contains('open')
        ) {
            closeMailCompose();
        }
    }
);

const reader =
    document.getElementById(
        'mailReaderScroll'
    );

if (reader) {
    reader.scrollTop =
        reader.scrollHeight;
}
</script>

</body>
</html>
