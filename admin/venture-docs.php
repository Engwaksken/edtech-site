<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/venture-doc-folders.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

ven_ensure_document_folders($conn);

$venture_id = (int)($_GET['venture_id'] ?? 0);

if ($venture_id <= 0) {
    header('Location: ventures.php');
    exit;
}

$ven_stmt = $conn->prepare("SELECT * FROM ventures WHERE id = ? LIMIT 1");
$ven_stmt->bind_param('i', $venture_id);
$ven_stmt->execute();
$venture = $ven_stmt->get_result()->fetch_assoc();
$ven_stmt->close();

if (!$venture) {
    header('Location: ventures.php');
    exit;
}

/* ============================================================
   CURRENT FOLDER + BREADCRUMB
============================================================ */

$current_folder_id = (int)($_GET['folder'] ?? 0);

if ($current_folder_id > 0) {
    $fchk = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
    $fchk->bind_param('ii', $current_folder_id, $venture_id);
    $fchk->execute();

    if (!$fchk->get_result()->fetch_assoc()) {
        $current_folder_id = 0;
    }

    $fchk->close();
}

$breadcrumb = $current_folder_id > 0 ? ven_folder_breadcrumb($conn, $venture_id, $current_folder_id) : [];

/* ============================================================
   SUBFOLDERS OF CURRENT FOLDER
============================================================ */

if ($current_folder_id > 0) {
    $folders_stmt = $conn->prepare("
        SELECT f.*,
               (SELECT COUNT(*) FROM venture_document_folders sf WHERE sf.parent_id = f.id) AS subfolder_count,
               (SELECT COUNT(*) FROM venture_documents dd WHERE dd.folder_id = f.id AND dd.is_latest = 1) AS doc_count
        FROM venture_document_folders f
        WHERE f.venture_id = ? AND f.parent_id = ?
        ORDER BY f.folder_name ASC
    ");
    $folders_stmt->bind_param('ii', $venture_id, $current_folder_id);
} else {
    $folders_stmt = $conn->prepare("
        SELECT f.*,
               (SELECT COUNT(*) FROM venture_document_folders sf WHERE sf.parent_id = f.id) AS subfolder_count,
               (SELECT COUNT(*) FROM venture_documents dd WHERE dd.folder_id = f.id AND dd.is_latest = 1) AS doc_count
        FROM venture_document_folders f
        WHERE f.venture_id = ? AND f.parent_id IS NULL
        ORDER BY f.folder_name ASC
    ");
    $folders_stmt->bind_param('i', $venture_id);
}
$folders_stmt->execute();
$folders_result = $folders_stmt->get_result();
$folders_arr = [];
while ($f = $folders_result->fetch_assoc()) {
    $folders_arr[] = $f;
}
$folders_stmt->close();

/* ============================================================
   DOCUMENTS IN CURRENT FOLDER
============================================================ */

if ($current_folder_id > 0) {
    $docs_stmt = $conn->prepare("
        SELECT d.*,
               (
                 SELECT COUNT(*)
                 FROM venture_documents dv
                 WHERE dv.venture_id = d.venture_id
                   AND dv.doc_group = d.doc_group
               ) AS version_count
        FROM venture_documents d
        WHERE d.venture_id = ?
          AND d.is_latest = 1
          AND d.folder_id = ?
        ORDER BY d.category ASC, d.uploaded_at DESC
    ");
    $docs_stmt->bind_param('ii', $venture_id, $current_folder_id);
} else {
    $docs_stmt = $conn->prepare("
        SELECT d.*,
               (
                 SELECT COUNT(*)
                 FROM venture_documents dv
                 WHERE dv.venture_id = d.venture_id
                   AND dv.doc_group = d.doc_group
               ) AS version_count
        FROM venture_documents d
        WHERE d.venture_id = ?
          AND d.is_latest = 1
          AND (d.folder_id IS NULL OR d.folder_id = 0)
        ORDER BY d.category ASC, d.uploaded_at DESC
    ");
    $docs_stmt->bind_param('i', $venture_id);
}
$docs_stmt->execute();
$docs = $docs_stmt->get_result();
$docs_stmt->close();

$doc_categories = [
    'pitch_deck'      => ['label'=>'Pitch Deck',       'icon'=>'fa-file-powerpoint', 'color'=>'#ef4444'],
    'financial_model' => ['label'=>'Financial Model',  'icon'=>'fa-file-excel',      'color'=>'#10b981'],
    'business_plan'   => ['label'=>'Business Plan',    'icon'=>'fa-file-word',       'color'=>'#3b82f6'],
    'legal'           => ['label'=>'Legal Documents',  'icon'=>'fa-file-contract',   'color'=>'#8b5cf6'],
    'product_demo'    => ['label'=>'Product Demo',     'icon'=>'fa-file-video',      'color'=>'#f59e0b'],
    'team_profile'    => ['label'=>'Team Profiles',    'icon'=>'fa-users',           'color'=>'#06b6d4'],
    'due_diligence'   => ['label'=>'Due Diligence',    'icon'=>'fa-search-dollar',   'color'=>'#6366f1'],
    'impact_report'   => ['label'=>'Impact Report',    'icon'=>'fa-chart-bar',       'color'=>'#84cc16'],
    'other'           => ['label'=>'Other',            'icon'=>'fa-file-alt',        'color'=>'#6b7280'],
];

$s3_enabled = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY') && defined('AWS_SECRET_KEY') && defined('AWS_REGION');

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';

function fmt_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 2) . ' MB';
}

function doc_status_badge(array $doc): string
{
    $approval = strtolower((string)($doc['approval_status'] ?? 'pending'));
    $change   = strtolower((string)($doc['change_request_status'] ?? 'none'));

    if ($change === 'requested') {
        return '<span class="doc-status doc-status-requested"><i class="fa fa-clock"></i> Change Requested</span>';
    }

    if ($change === 'allowed') {
        return '<span class="doc-status doc-status-allowed"><i class="fa fa-unlock"></i> Re-upload Allowed</span>';
    }

    if ($change === 'rejected') {
        return '<span class="doc-status doc-status-rejected"><i class="fa fa-times-circle"></i> Request Rejected</span>';
    }

    if ($approval === 'approved') {
        return '<span class="doc-status doc-status-approved"><i class="fa fa-check-circle"></i> Approved</span>';
    }

    if ($approval === 'rejected') {
        return '<span class="doc-status doc-status-rejected"><i class="fa fa-times-circle"></i> Rejected</span>';
    }

    return '<span class="doc-status doc-status-pending"><i class="fa fa-hourglass-half"></i> Pending Review</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Documents - <?= h($venture['name']) ?> - <?= h($site_name) ?> Admin</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css">

<style>
/* ---- breadcrumb ---- */
.doc-breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 14px;
    margin-bottom: 16px;
    background: var(--surface, #f8fafc);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    font-size: 13.5px;
}
.breadcrumb-link {
    color: var(--text-muted, #6b7280);
    text-decoration: none;
    padding: 3px 8px;
    border-radius: 6px;
    transition: background .15s, outline-color .15s;
}
.breadcrumb-link:last-child { color: var(--text, #111827); font-weight: 600; }
.breadcrumb-link.drag-over-target { background: #dbeafe; outline: 2px dashed #3b82f6; }
.breadcrumb-sep { color: var(--text-muted, #9ca3af); }

/* ---- section label ---- */
.section-label {
    font-size: 12px; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; color: var(--text-muted, #6b7280);
    margin: 18px 0 8px;
}

/* ---- folder grid ---- */
.folder-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px; margin-bottom: 8px;
}
.folder-card {
    position: relative; display: flex; flex-direction: column;
    border: 1px solid var(--border, #e2e8f0); border-radius: 12px;
    background: #fff; padding: 12px;
    cursor: grab; user-select: none;
    transition: box-shadow .15s, border-color .15s, background .15s;
}
.folder-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }
.folder-card.dragging { opacity: .4; }
.folder-card.drag-over-target {
    border-color: #3b82f6; background: #eff6ff;
    box-shadow: 0 0 0 2px #93c5fd inset;
}
.folder-card-link {
    text-decoration: none; color: inherit; display: block;
    /* CRITICAL: prevent anchor's native drag from hijacking the card's drag */
    -webkit-user-drag: none;
}
.folder-icon { font-size: 26px; color: #f59e0b; margin-bottom: 6px; }
.folder-name { font-weight: 600; font-size: 14px; color: var(--text, #111827); overflow-wrap: anywhere; }
.folder-meta { font-size: 12px; color: var(--text-muted, #6b7280); margin-top: 2px; }
.folder-card-actions { display: flex; gap: 4px; margin-top: 10px; }

/* ---- doc grid drop overlay ---- */
.drag-over-page {
    outline: 2px dashed #3b82f6;
    outline-offset: 4px;
    background: rgba(59,130,246,.04);
    border-radius: 12px;
}
.doc-card { user-select: none; }
.doc-card.dragging { opacity: .4; }

/* ---- upload progress toast ---- */
.upload-progress-toast {
    position: fixed; right: 20px; bottom: 20px; z-index: 4000;
    background: #111827; color: #fff; padding: 12px 16px;
    border-radius: 10px; font-size: 13px; min-width: 220px;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    display: none;
}
.upload-progress-toast.visible { display: block; }
.upload-progress-bar-track {
    height: 5px; background: rgba(255,255,255,.2);
    border-radius: 3px; margin-top: 8px; overflow: hidden;
}
.upload-progress-bar-fill {
    height: 100%; width: 0%; background: #3b82f6; transition: width .2s;
}

/* ---- full-page drop zone (covers entire admin-content when OS files dragged in) ---- */
.page-drop-zone {
    position: relative;
}
.page-drop-zone.drag-active::after {
    content: '';
    position: absolute; inset: 0; z-index: 1000;
    border: 3px dashed #3b82f6;
    border-radius: 12px;
    background: rgba(59,130,246,.06);
    pointer-events: none;
}
</style>

</head>

<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
    <div class="topbar-left">
        <div class="topbar-breadcrumb">
            <a href="index.php">Dashboard</a> &gt;
            <a href="ventures.php">Ventures</a> &gt;
            <strong><?= h($venture['name']) ?> - Documents</strong>
        </div>
    </div>

    <div class="topbar-right">
        <div class="admin-avatar">
            <div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1)) ?></div>
        </div>
    </div>
</header>

<div class="admin-content page-drop-zone" id="pageDropZone">
<?php show_flash('docs'); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($venture['name']) ?> - Document Vault</h1>
        <p class="page-subtitle">
            <?= $s3_enabled ? '<span class="s3-badge"><i class="fa fa-cloud"></i> AWS S3</span>&nbsp;&nbsp;' : '' ?>
            Organize documents into folders, review, approve files, and grant re-upload permission when requested.
        </p>
    </div>

    <div class="page-actions">
        <a href="ventures.php" class="btn btn-secondary">
            <i class="fa fa-arrow-left"></i> Back to Ventures
        </a>

        <button class="btn btn-secondary" type="button" onclick="openNewFolderModal()">
            <i class="fa fa-folder-plus"></i> New Folder
        </button>

        <button class="btn btn-secondary" type="button" onclick="document.getElementById('uploadFolderInput').click()">
            <i class="fa fa-upload"></i> Upload Folder
        </button>
        <input type="file" id="uploadFolderInput" webkitdirectory directory multiple style="display:none">

        <button class="btn btn-primary" onclick="openDocModal()">
            <i class="fa fa-upload"></i> Upload Document
        </button>
    </div>
</div>

<!-- Breadcrumb -->
<div class="doc-breadcrumb" id="vaultBreadcrumb">
    <a href="?venture_id=<?= (int)$venture_id ?>&folder=0"
       class="breadcrumb-link drop-target"
       draggable="false"
       data-folder-id="0">
        <i class="fa fa-vault"></i> Vault
    </a>
    <?php foreach ($breadcrumb as $bc): ?>
        <span class="breadcrumb-sep">/</span>
        <a href="?venture_id=<?= (int)$venture_id ?>&folder=<?= (int)$bc['id'] ?>"
           class="breadcrumb-link drop-target"
           draggable="false"
           data-folder-id="<?= (int)$bc['id'] ?>">
            <?= h($bc['folder_name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
$docs_arr = [];
while ($d = $docs->fetch_assoc()) {
    $docs_arr[] = $d;
}

$by_cat = [];
foreach ($docs_arr as $d) {
    $by_cat[$d['category']][] = $d;
}
?>

<?php if (empty($docs_arr) && empty($folders_arr)): ?>

<div class="empty-state legacy-style-7b18a7f5cd">
    <i class="fa fa-folder-open legacy-style-16a5d0b969"></i>
    <h3>Nothing here yet</h3>
    <p>Create a folder, upload a document, or drag files/folders in.</p>

    <button class="btn btn-primary" onclick="openDocModal()">
       Upload Document
    </button>
</div>

<?php else: ?>

<?php if (!empty($folders_arr)): ?>
    <div class="section-label">Folders</div>
    <div class="folder-grid" id="folderGrid">
        <?php foreach ($folders_arr as $folder): ?>
            <div class="folder-card drop-target"
                 draggable="true"
                 data-item-type="folder"
                 data-item-id="<?= (int)$folder['id'] ?>"
                 data-folder-id="<?= (int)$folder['id'] ?>">
                <a class="folder-card-link"
                   draggable="false"
                   href="?venture_id=<?= (int)$venture_id ?>&folder=<?= (int)$folder['id'] ?>">
                    <div class="folder-icon"><i class="fa fa-folder"></i></div>
                    <div class="folder-name"><?= h($folder['folder_name']) ?></div>
                    <div class="folder-meta">
                        <?= (int)$folder['subfolder_count'] ?> folder<?= (int)$folder['subfolder_count'] === 1 ? '' : 's' ?>
                        &nbsp;.&nbsp;
                        <?= (int)$folder['doc_count'] ?> file<?= (int)$folder['doc_count'] === 1 ? '' : 's' ?>
                    </div>
                </a>
                <div class="folder-card-actions">
                    <button type="button" class="btn btn-sm btn-secondary" title="Rename"
                            onclick="openRenameFolderModal(<?= (int)$folder['id'] ?>, '<?= h(addslashes($folder['folder_name'])) ?>')">
                        <i class="fa fa-pen"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary" title="Move"
                            onclick="openMoveModal('folder', <?= (int)$folder['id'] ?>, '<?= h(addslashes($folder['folder_name'])) ?>')">
                        <i class="fa fa-arrows-alt"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" title="Delete"
                            onclick="deleteFolder(<?= (int)$folder['id'] ?>, '<?= h(addslashes($folder['folder_name'])) ?>')">
                        <i class="fa fa-trash"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($docs_arr)): ?>
<div class="section-label">Files</div>

<?php foreach ($doc_categories as $cat_key => $cat_info): ?>
    <?php if (empty($by_cat[$cat_key])) continue; ?>

    <div class="cat-header">
        <i class="fa <?= h($cat_info['icon']) ?>" style="color:<?= h($cat_info['color']) ?>"></i>
        <?= h($cat_info['label']) ?>
        <span class="legacy-style-c5846dba5b">
            <?= count($by_cat[$cat_key]) ?>
        </span>
    </div>

    <div class="doc-grid">

    <?php foreach ($by_cat[$cat_key] as $doc): ?>
        <?php
            $approval_status = strtolower((string)($doc['approval_status'] ?? 'pending'));
            $change_status   = strtolower((string)($doc['change_request_status'] ?? 'none'));

            $has_change_request = $approval_status === 'approved' && $change_status === 'requested';
        ?>

        <div class="doc-card"
             draggable="true"
             data-item-type="document"
             data-item-id="<?= (int)$doc['id'] ?>"
             data-doc-group="<?= h($doc['doc_group']) ?>">

            <div class="doc-card-header">
                <div class="doc-icon" style="background:<?= h($cat_info['color']) ?>">
                    <i class="fa <?= h($cat_info['icon']) ?>"></i>
                </div>

                <div class="legacy-style-19fb48c164">
                    <strong class="legacy-style-60f5e7e7b2">
                        <?= h($doc['doc_name']) ?>
                    </strong>

                    <div class="doc-meta">
                        <?= fmt_bytes((int)$doc['file_size']) ?>
                        &nbsp;.&nbsp; <?= strtoupper(h($doc['file_ext'])) ?>

                        <?php if ((int)$doc['version_count'] > 1): ?>
                            &nbsp;.&nbsp; <span class="version-badge">v<?= (int)$doc['version_number'] ?></span>
                        <?php endif; ?>

                        <?php if (($doc['storage_type'] ?? '') === 's3'): ?>
                            &nbsp;<span class="s3-badge"><i class="fa fa-cloud"></i> S3</span>
                        <?php endif; ?>
                    </div>

                    <?= doc_status_badge($doc) ?>
                </div>
            </div>

            <div class="doc-card-body">

                <?php if (!empty($doc['description'])): ?>
                    <p class="legacy-style-93c921cd94">
                        <?= h(mb_strimwidth($doc['description'], 0, 100, '...')) ?>
                    </p>
                <?php endif; ?>

                <div class="doc-meta legacy-style-fe7b4979fe">
                    Uploaded by <?= h($doc['uploaded_by_name'] ?? 'Admin') ?>
                    . <?= h(date('M j, Y', strtotime($doc['uploaded_at']))) ?>
                </div>

                <?php if (!empty($doc['change_request_reason'])): ?>
                    <div class="request-box">
                        <strong>Venture change request reason:</strong>
                        <?= nl2br(h($doc['change_request_reason'])) ?>

                        <?php if (!empty($doc['change_request_at'])): ?>
                            <div class="legacy-style-79a8963541">
                                Requested on <?= h(date('M j, Y g:ia', strtotime($doc['change_request_at']))) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="approval-row">

                    <?php if ($approval_status !== 'approved'): ?>
                        <form method="POST" action="includes/process-ventures.php" class="legacy-style-cccfa4560d">
                            <input type="hidden" name="action" value="approve_doc">
                            <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
                            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">
                            <button class="btn btn-sm btn-success">
                                <i class="fa fa-check"></i> Approve
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($approval_status !== 'rejected'): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            onclick="openRejectDocModal(<?= (int)$doc['id'] ?>, '<?= h(addslashes($doc['doc_name'])) ?>')"
                        >
                            <i class="fa fa-times"></i> Reject
                        </button>
                    <?php endif; ?>

                    <?php if ($has_change_request): ?>
                        <form method="POST" action="includes/process-ventures.php" class="legacy-style-cccfa4560d">
                            <input type="hidden" name="action" value="allow_doc_change">
                            <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
                            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">
                            <button class="btn btn-sm btn-primary">
                                <i class="fa fa-unlock"></i> Allow Re-upload
                            </button>
                        </form>

                        <button
                            type="button"
                            class="btn btn-sm btn-secondary"
                            onclick="openRejectChangeModal(<?= (int)$doc['id'] ?>, '<?= h(addslashes($doc['doc_name'])) ?>')"
                        >
                            <i class="fa fa-ban"></i> Reject Request
                        </button>
                    <?php endif; ?>

                </div>
            </div>

            <div class="doc-card-footer">
                <div class="doc-actions">
                    <a href="includes/process-ventures.php?action=view&id=<?= (int)$doc['id'] ?>"
                       class="btn btn-sm btn-primary js-view-doc"
                       target="_blank" rel="noopener" draggable="false"
                       data-doc-id="<?= (int)$doc['id'] ?>"
                       data-doc-name="<?= h($doc['doc_name']) ?>"
                       data-file-ext="<?= h(strtolower((string)$doc['file_ext'])) ?>"
                       title="View file">
                        <i class="fa fa-eye"></i>
                    </a>

                    <a href="includes/process-ventures.php?action=download&id=<?= (int)$doc['id'] ?>"
                       class="btn btn-sm btn-secondary"
                       draggable="false"
                       title="Download">
                        <i class="fa fa-download"></i>
                    </a>

                    <?php if ((int)$doc['version_count'] > 1): ?>
                        <button
                            class="btn btn-sm btn-teal"
                            title="Version history"
                            onclick="loadVersionHistory(
                                <?= (int)$doc['id'] ?>,
                                '<?= h(addslashes($doc['doc_name'])) ?>',
                                '<?= h($doc['doc_group']) ?>'
                            )"
                        >
                            <i class="fa fa-history"></i> <?= (int)$doc['version_count'] ?>
                        </button>
                    <?php endif; ?>

                    <button
                        class="btn btn-sm btn-secondary"
                        title="Admin upload new version"
                        onclick="openDocModal(
                            '<?= (int)$doc['venture_id'] ?>',
                            '<?= h($doc['doc_group']) ?>',
                            '<?= h($doc['category']) ?>',
                            '<?= h(addslashes($doc['doc_name'])) ?>'
                        )"
                    >
                        <i class="fa fa-redo"></i>
                    </button>

                    <button
                        class="btn btn-sm btn-secondary"
                        title="Move to folder"
                        onclick="openMoveModal('document', <?= (int)$doc['id'] ?>, '<?= h(addslashes($doc['doc_name'])) ?>', '<?= h($doc['doc_group']) ?>')"
                    >
                        <i class="fa fa-arrows-alt"></i>
                    </button>

                    <form method="POST"
                          action="includes/process-ventures.php"
                          onsubmit="return confirm('Delete all versions of this document?')" class="legacy-style-cccfa4560d">
                        <input type="hidden" name="action" value="delete_doc">
                        <input type="hidden" name="doc_group" value="<?= h($doc['doc_group']) ?>">
                        <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
                        <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">
                        <button class="btn btn-sm btn-danger">
                            <i class="fa fa-trash"></i>
                        </button>
                    </form>
                </div>

                <?php if (!empty($doc['is_visible_to_investors'])): ?>
                    <span class="legacy-style-89602ad150">
                        <i class="fa fa-eye"></i> Visible
                    </span>
                <?php else: ?>
                    <span class="legacy-style-7491483f4d">
                        <i class="fa fa-eye-slash"></i> Private
                    </span>
                <?php endif; ?>
            </div>

        </div>

    <?php endforeach; ?>

    </div>

<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

</div><!-- /.admin-content .page-drop-zone -->
</div>

<!-- Upload Modal -->
<div class="modal-overlay" id="docModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="docModalTitle">Upload Document</h2>
            <button type="button" class="modal-close" onclick="closeDocModal()">x</button>
        </div>

        <form method="POST" action="includes/process-ventures.php" enctype="multipart/form-data">
            <input type="hidden" name="action" id="doc_action_input" value="upload_doc">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <input type="hidden" name="doc_group" id="doc_group_input" value="">
            <input type="hidden" name="folder_id" id="doc_folder_id_input" value="<?= (int)$current_folder_id ?>">
            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">

            <div class="modal-body">
                <div class="form-grid form-grid-2">

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="doc_cat_input" class="form-control" onchange="toggleUploadMode()">
                            <?php foreach ($doc_categories as $k => $v): ?>
                                <option value="<?= h($k) ?>"><?= h($v['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Visibility</label>
                        <select name="is_visible_to_investors" class="form-control">
                            <option value="0">Private</option>
                            <option value="1">Visible to investors</option>
                        </select>
                    </div>

                    <!-- Single-file mode -->
                    <div id="singleFileSection" class="legacy-style-ae125572d5">

                        <div class="form-group full">
                            <label>Document Name <span class="req">*</span></label>
                            <input type="text" name="doc_name" id="doc_name_input" class="form-control">
                        </div>

                        <div class="form-group full">
                            <label>Description / Notes</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="form-group full">
                            <label>File <span class="req">*</span></label>

                            <div class="upload-zone"
                                 id="docDropZone"
                                 onclick="document.getElementById('doc_file_input').click()"
                                 ondragover="handleModalDragOver(event)"
                                 ondragleave="handleModalDragLeave(event)"
                                 ondrop="handleModalDrop(event)">
                                <div id="docDropContent">
                                    <i class="fa fa-cloud-upload-alt"></i>
                                    <div class="legacy-style-a27006d43c">Click or drag file here</div>
                                    <div class="legacy-style-865c33f0ef">
                                        PDF, DOCX, XLSX, PPTX, MP4, ZIP - max <?= $s3_enabled ? '500 MB' : '50 MB' ?>
                                    </div>
                                </div>
                            </div>

                            <input type="file"
                                   name="doc_file"
                                   id="doc_file_input"
                                   onchange="handleFileSelect(this)"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.mov,.zip,.rar,.png,.jpg,.jpeg" class="legacy-style-6b99de8b69">
                        </div>

                    </div>

                    <!-- Multi-file mode: only for new "Other" document uploads -->
                    <div id="multiFileSection" class="form-group full legacy-style-6b99de8b69">
                        <label>Files <span class="req">*</span></label>
                        <p class="legacy-style-17c5073033">
                            "Other" documents can be uploaded in bulk. Add as many files as you need -
                            each one gets its own name and description and is saved as a separate document.
                        </p>

                        <div id="multiFileRows"></div>

                        <button type="button" class="btn btn-sm btn-secondary" onclick="addMultiFileRow()">
                            <i class="fa fa-plus"></i> Add Another File
                        </button>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDocModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-upload"></i> Upload
                </button>
            </div>
        </form>
    </div>
</div>

<!-- New Folder Modal -->
<div class="modal-overlay" id="newFolderModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">New Folder</h2>
            <button type="button" class="modal-close" onclick="closeNewFolderModal()">x</button>
        </div>

        <form method="POST" action="includes/process-ventures.php">
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <input type="hidden" name="parent_id" value="<?= (int)$current_folder_id ?>">

            <div class="modal-body">
                <div class="form-group full">
                    <label>Folder Name <span class="req">*</span></label>
                    <input type="text" name="folder_name" class="form-control" required autofocus>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewFolderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-folder-plus"></i> Create Folder
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Rename Folder Modal -->
<div class="modal-overlay" id="renameFolderModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Rename Folder</h2>
            <button type="button" class="modal-close" onclick="closeRenameFolderModal()">x</button>
        </div>

        <form method="POST" action="includes/process-ventures.php">
            <input type="hidden" name="action" value="rename_folder">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <input type="hidden" name="folder_id" id="rename_folder_id">
            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">

            <div class="modal-body">
                <div class="form-group full">
                    <label>Folder Name <span class="req">*</span></label>
                    <input type="text" name="folder_name" id="rename_folder_name" class="form-control" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRenameFolderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-pen"></i> Rename
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Move Modal (documents and folders) -->
<div class="modal-overlay" id="moveModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="moveModalTitle">Move Item</h2>
            <button type="button" class="modal-close" onclick="closeMoveModal()">x</button>
        </div>

        <div class="modal-body">
            <div class="form-group full">
                <label>Destination Folder</label>
                <select id="moveTargetSelect" class="form-control">
                    <option value="0">Vault (root)</option>
                </select>
            </div>
            <p id="moveModalError" style="color:#dc2626;font-size:13px;display:none"></p>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeMoveModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitMove()">
                <i class="fa fa-arrows-alt"></i> Move
            </button>
        </div>
    </div>
</div>

<!-- Reject Document Modal -->
<div class="modal-overlay" id="rejectDocModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Reject Document</h2>
            <button type="button" class="modal-close" onclick="closeRejectDocModal()">x</button>
        </div>

        <form method="POST" action="includes/process-ventures.php">
            <input type="hidden" name="action" value="reject_doc">
            <input type="hidden" name="document_id" id="reject_doc_id">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">

            <div class="modal-body">
                <p class="legacy-style-f0aa701894">
                    Rejecting this document will notify the venture that this file was not approved.
                </p>

                <div class="form-group">
                    <label>Document</label>
                    <input type="text" id="reject_doc_name" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label>Reason / Admin note</label>
                    <textarea name="admin_note" class="form-control" rows="4" placeholder="Explain why the document was rejected..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRejectDocModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fa fa-times"></i> Reject Document
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Change Request Modal -->
<div class="modal-overlay" id="rejectChangeModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Reject Re-upload Request</h2>
            <button type="button" class="modal-close" onclick="closeRejectChangeModal()">x</button>
        </div>

        <form method="POST" action="includes/process-ventures.php">
            <input type="hidden" name="action" value="reject_doc_change">
            <input type="hidden" name="document_id" id="reject_change_doc_id">
            <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
            <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label>Document</label>
                    <input type="text" id="reject_change_doc_name" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label>Reason / Admin note</label>
                    <textarea name="admin_note" class="form-control" rows="4" placeholder="Explain why the re-upload request is rejected..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRejectChangeModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fa fa-ban"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Version Modal -->
<div class="modal-overlay" id="versionModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="versionModalTitle">Version History</h2>
            <button type="button" class="modal-close" onclick="closeVersionModal()">x</button>
        </div>

        <div class="modal-body" id="versionModalBody">
            <p class="legacy-style-ec62245f9e">Loading...</p>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeVersionModal()">Close</button>
        </div>
    </div>
</div>

<!-- Upload progress toast -->
<div class="upload-progress-toast" id="uploadToast">
    <div id="uploadToastLabel">Uploading...</div>
    <div class="upload-progress-bar-track">
        <div class="upload-progress-bar-fill" id="uploadToastFill"></div>
    </div>
</div>

<script>
const VENTURE_ID = <?= (int)$venture_id ?>;
const CURRENT_FOLDER_ID = <?= (int)$current_folder_id ?>;
const PROCESS_URL = 'includes/process-ventures.php';

document.getElementById('sidebarToggle')?.addEventListener('click', function () {
    document.getElementById('adminSidebar')?.classList.toggle('collapsed');
    document.getElementById('adminMain')?.classList.toggle('collapsed');
});

const docModal = document.getElementById('docModal');
const versionModal = document.getElementById('versionModal');
const rejectDocModal = document.getElementById('rejectDocModal');
const rejectChangeModal = document.getElementById('rejectChangeModal');
const newFolderModal = document.getElementById('newFolderModal');
const renameFolderModal = document.getElementById('renameFolderModal');
const moveModal = document.getElementById('moveModal');

/* ============================================================
   MULTI-FILE ("OTHER" CATEGORY) ROWS
============================================================ */

function multiFileRowTemplate() {
    return '' +
        '<div class="multi-file-row">' +
            '<div class="multi-file-row-main">' +
                '<input type="file" name="other_files[]" class="form-control" ' +
                       'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.mov,.zip,.rar,.png,.jpg,.jpeg">' +
                '<input type="text" name="other_names[]" class="form-control" ' +
                       'placeholder="Name (optional - defaults to filename)">' +
                '<textarea name="other_descriptions[]" class="form-control" rows="2" ' +
                          'placeholder="Description / notes"></textarea>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-danger multi-file-row-remove" ' +
                    'onclick="removeMultiFileRow(this)" title="Remove file">' +
                '<i class="fa fa-times"></i>' +
            '</button>' +
        '</div>';
}

function addMultiFileRow() {
    document.getElementById('multiFileRows').insertAdjacentHTML('beforeend', multiFileRowTemplate());
}

function removeMultiFileRow(btn) {
    var rows = document.getElementById('multiFileRows');
    var row = btn.closest('.multi-file-row');

    if (rows.children.length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input,textarea').forEach(function (el) { el.value = ''; });
    }
}

function resetMultiFileRows() {
    document.getElementById('multiFileRows').innerHTML = '';
    addMultiFileRow();
}

/* ============================================================
   SINGLE vs MULTI UPLOAD MODE
============================================================ */

function toggleUploadMode() {
    var category = document.getElementById('doc_cat_input').value;
    var isNewVersion = !!document.getElementById('doc_group_input').value;
    var multiMode = category === 'other' && !isNewVersion;

    document.getElementById('singleFileSection').style.display = multiMode ? 'none' : 'contents';
    document.getElementById('multiFileSection').style.display = multiMode ? '' : 'none';

    document.getElementById('doc_name_input').required = !multiMode;
    document.getElementById('doc_file_input').required = !multiMode;

    document.getElementById('doc_action_input').value = multiMode ? 'upload_other_docs' : 'upload_doc';
}

function openDocModal(ventureId, group, category, name) {
    docModal.querySelector('form').reset();

    document.getElementById('doc_group_input').value = group || '';
    document.getElementById('doc_folder_id_input').value = CURRENT_FOLDER_ID;

    if (category) {
        document.getElementById('doc_cat_input').value = category;
    }

    if (name) {
        document.getElementById('doc_name_input').value = name;
    }

    document.getElementById('docModalTitle').textContent = group ? 'Upload New Version' : 'Upload Document';

    document.getElementById('docDropContent').innerHTML =
        '<i class="fa fa-cloud-upload-alt"></i>' +
        '<div class="legacy-style-a27006d43c">Click or drag file here</div>' +
        '<div class="legacy-style-865c33f0ef">PDF, DOCX, XLSX, PPTX, MP4, ZIP</div>';

    resetMultiFileRows();
    toggleUploadMode();

    docModal.classList.add('open');
}

function closeDocModal() { docModal.classList.remove('open'); }

docModal.addEventListener('click', function (e) { if (e.target === docModal) closeDocModal(); });

/* ---- modal drag-drop zone ---- */
function handleModalDragOver(e) { e.preventDefault(); document.getElementById('docDropZone').classList.add('drag-over'); }
function handleModalDragLeave(e) { document.getElementById('docDropZone').classList.remove('drag-over'); }
function handleModalDrop(e) {
    e.preventDefault();
    document.getElementById('docDropZone').classList.remove('drag-over');
    var file = e.dataTransfer.files[0];
    if (!file) return;
    var dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('doc_file_input').files = dt.files;
    showFileInfo(file);
    if (!document.getElementById('doc_name_input').value) {
        document.getElementById('doc_name_input').value = file.name.replace(/\.[^.]+$/, '');
    }
}

function handleFileSelect(input) {
    if (input.files && input.files[0]) {
        showFileInfo(input.files[0]);
        if (!document.getElementById('doc_name_input').value) {
            document.getElementById('doc_name_input').value = input.files[0].name.replace(/\.[^.]+$/, '');
        }
    }
}

function showFileInfo(file) {
    var mb = (file.size / 1048576).toFixed(2);
    document.getElementById('docDropContent').innerHTML =
        '<i class="fa fa-file-check legacy-style-118e1505a3"></i>' +
        '<div class="legacy-style-9ab25a324c">' + file.name + '</div>' +
        '<div class="legacy-style-865c33f0ef">' + mb + ' MB</div>';
}

/* ---- reject / change modals ---- */
function openRejectDocModal(id, name) {
    document.getElementById('reject_doc_id').value = id;
    document.getElementById('reject_doc_name').value = name;
    rejectDocModal.classList.add('open');
}
function closeRejectDocModal() { rejectDocModal.classList.remove('open'); }

function openRejectChangeModal(id, name) {
    document.getElementById('reject_change_doc_id').value = id;
    document.getElementById('reject_change_doc_name').value = name;
    rejectChangeModal.classList.add('open');
}
function closeRejectChangeModal() { rejectChangeModal.classList.remove('open'); }

rejectDocModal.addEventListener('click', function (e) { if (e.target === rejectDocModal) closeRejectDocModal(); });
rejectChangeModal.addEventListener('click', function (e) { if (e.target === rejectChangeModal) closeRejectChangeModal(); });

/* ---- version modal ---- */
function closeVersionModal() { versionModal.classList.remove('open'); }
versionModal.addEventListener('click', function (e) { if (e.target === versionModal) closeVersionModal(); });

async function loadVersionHistory(docId, docName, docGroup) {
    document.getElementById('versionModalTitle').textContent = 'Version History - ' + docName;
    document.getElementById('versionModalBody').innerHTML = '<p class="legacy-style-ec62245f9e">Loading...</p>';
    versionModal.classList.add('open');

    try {
        var res = await fetch(PROCESS_URL + '?action=version_history&doc_group=' +
            encodeURIComponent(docGroup) + '&venture_id=' + VENTURE_ID);
        document.getElementById('versionModalBody').innerHTML = await res.text();
    } catch (err) {
        document.getElementById('versionModalBody').innerHTML = '<p class="legacy-style-d29a829acb">Failed to load history.</p>';
    }
}

/* ============================================================
   FOLDER MODALS
============================================================ */

function openNewFolderModal() {
    newFolderModal.querySelector('form').reset();
    newFolderModal.classList.add('open');
}
function closeNewFolderModal() { newFolderModal.classList.remove('open'); }
newFolderModal.addEventListener('click', function (e) { if (e.target === newFolderModal) closeNewFolderModal(); });

function openRenameFolderModal(id, name) {
    document.getElementById('rename_folder_id').value = id;
    document.getElementById('rename_folder_name').value = name;
    renameFolderModal.classList.add('open');
}
function closeRenameFolderModal() { renameFolderModal.classList.remove('open'); }
renameFolderModal.addEventListener('click', function (e) { if (e.target === renameFolderModal) closeRenameFolderModal(); });

function deleteFolder(id, name) {
    if (!confirm('Delete folder "' + name + '"? The folder must be empty.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = PROCESS_URL;
    var fields = { action: 'delete_folder', venture_id: VENTURE_ID, folder_id: id, return_folder: CURRENT_FOLDER_ID };
    Object.keys(fields).forEach(function (key) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = key; input.value = fields[key];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

/* ============================================================
   MOVE MODAL
============================================================ */

var moveContext = null;

async function openMoveModal(type, id, name, docGroup) {
    moveContext = { type: type, id: id, name: name, docGroup: docGroup || null };
    document.getElementById('moveModalTitle').textContent = 'Move "' + name + '"';
    document.getElementById('moveModalError').style.display = 'none';

    var select = document.getElementById('moveTargetSelect');
    select.innerHTML = '<option value="0">Vault (root)</option>';

    try {
        var res = await fetch(PROCESS_URL + '?action=list_folders&venture_id=' + VENTURE_ID);
        var data = await res.json();
        if (data.success) {
            data.folders.forEach(function (f) {
                if (type === 'folder' && f.id === id) return;
                var opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = '\u00A0\u00A0'.repeat(f.depth) + f.name;
                select.appendChild(opt);
            });
        }
    } catch (err) { /* root is always there */ }

    moveModal.classList.add('open');
}

function closeMoveModal() { moveModal.classList.remove('open'); moveContext = null; }
moveModal.addEventListener('click', function (e) { if (e.target === moveModal) closeMoveModal(); });

async function submitMove() {
    if (!moveContext) return;
    var targetFolderId = document.getElementById('moveTargetSelect').value;
    var errorEl = document.getElementById('moveModalError');
    errorEl.style.display = 'none';

    var body = new URLSearchParams();
    body.set('venture_id', VENTURE_ID);
    body.set('target_folder_id', targetFolderId);

    if (moveContext.type === 'folder') {
        body.set('action', 'move_folder');
        body.set('folder_id', moveContext.id);
    } else {
        body.set('action', 'move_document');
        body.set('doc_group', moveContext.docGroup);
    }

    try {
        var res = await fetch(PROCESS_URL, { method: 'POST', body: body });
        var data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            errorEl.textContent = data.message || 'Move failed.';
            errorEl.style.display = 'block';
        }
    } catch (err) {
        errorEl.textContent = 'Move failed. Please try again.';
        errorEl.style.display = 'block';
    }
}

/* ============================================================
   DRAG AND DROP - internal moves (files/folders onto folders)
   ============================================================
   Key fixes:
   1. All <a> tags inside draggable elements use draggable="false"
      so the browser's native link-drag doesn't hijack the card.
   2. dragleave uses a counter (dragEnterCount) instead of a simple
      toggle, because entering a child element fires dragleave on
      the parent - without a counter the highlight flickers and
      drops fail mid-flicker.
   3. drop handlers call stopPropagation() so the page-level
      external-file handler never interferes with internal moves.
============================================================ */

var dragPayload = null;

document.querySelectorAll('[data-item-type]').forEach(function (el) {
    el.addEventListener('dragstart', function (e) {
        dragPayload = {
            type: el.dataset.itemType,
            id: parseInt(el.dataset.itemId, 10),
            docGroup: el.dataset.docGroup || null
        };
        el.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/x-vault-drag', JSON.stringify(dragPayload)); } catch (ex) {}
        try { e.dataTransfer.setData('text/plain', ''); } catch (ex) {}
    });

    el.addEventListener('dragend', function () {
        el.classList.remove('dragging');
        dragPayload = null;
    });
});

/* --- set up drop targets (folder cards + breadcrumb links) --- */
document.querySelectorAll('.drop-target').forEach(function (el) {
    var dragEnterCount = 0;

    el.addEventListener('dragenter', function (e) {
        e.preventDefault();
        dragEnterCount++;
        el.classList.add('drag-over-target');
    });

    el.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = e.dataTransfer.types.includes('Files') ? 'copy' : 'move';
    });

    el.addEventListener('dragleave', function (e) {
        dragEnterCount--;
        if (dragEnterCount <= 0) {
            dragEnterCount = 0;
            el.classList.remove('drag-over-target');
        }
    });

    el.addEventListener('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dragEnterCount = 0;
        el.classList.remove('drag-over-target');

        var targetFolderId = parseInt(el.dataset.folderId, 10) || 0;

        /* External OS files dropped onto a specific folder card */
        if (e.dataTransfer.types.includes('Files')) {
            handleExternalDrop(e, targetFolderId);
            return;
        }

        /* Internal drag-and-drop move */
        if (!dragPayload) return;
        moveItem(dragPayload, targetFolderId);
    });
});

async function moveItem(payload, targetFolderId) {
    var body = new URLSearchParams();
    body.set('venture_id', VENTURE_ID);
    body.set('target_folder_id', targetFolderId);

    if (payload.type === 'folder') {
        if (payload.id === targetFolderId) return;
        body.set('action', 'move_folder');
        body.set('folder_id', payload.id);
    } else {
        body.set('action', 'move_document');
        body.set('doc_group', payload.docGroup);
    }

    try {
        var res = await fetch(PROCESS_URL, { method: 'POST', body: body });
        var data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Move failed.');
        }
    } catch (err) {
        alert('Move failed. Please try again.');
    }
}

/* ============================================================
   DRAG AND DROP - OS files/folders in (upload to current folder)
   ============================================================
   The entire admin-content area acts as a drop zone for external
   files. We use dragenter/dragleave counting on the page zone
   so the visual overlay is stable.
============================================================ */

(function () {
    var zone = document.getElementById('pageDropZone');
    var zoneEnterCount = 0;

    zone.addEventListener('dragenter', function (e) {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        zoneEnterCount++;
        zone.classList.add('drag-active');
    });

    zone.addEventListener('dragover', function (e) {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    });

    zone.addEventListener('dragleave', function (e) {
        if (!e.dataTransfer.types.includes('Files')) return;
        zoneEnterCount--;
        if (zoneEnterCount <= 0) {
            zoneEnterCount = 0;
            zone.classList.remove('drag-active');
        }
    });

    zone.addEventListener('drop', function (e) {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        zoneEnterCount = 0;
        zone.classList.remove('drag-active');
        handleExternalDrop(e, CURRENT_FOLDER_ID);
    });
})();

/* ---- upload progress toast ---- */
function showUploadToast(label, percent) {
    var toast = document.getElementById('uploadToast');
    document.getElementById('uploadToastLabel').textContent = label;
    document.getElementById('uploadToastFill').style.width = percent + '%';
    toast.classList.add('visible');
}
function hideUploadToast() { document.getElementById('uploadToast').classList.remove('visible'); }

/* ---- recursively read a FileSystemDirectoryEntry ---- */
function collectEntry(entry, pathPrefix, fileList) {
    return new Promise(function (resolve) {
        if (entry.isFile) {
            entry.file(function (file) {
                fileList.push({ file: file, relPath: pathPrefix });
                resolve();
            }, function () { resolve(); });
        } else if (entry.isDirectory) {
            var dirPath = pathPrefix ? pathPrefix + '/' + entry.name : entry.name;
            var reader = entry.createReader();
            var allEntries = [];

            /* readEntries returns batches of <=100; loop until empty */
            var readBatch = function () {
                reader.readEntries(function (batch) {
                    if (!batch.length) {
                        Promise.all(allEntries.map(function (child) {
                            return collectEntry(child, dirPath, fileList);
                        })).then(resolve);
                        return;
                    }
                    allEntries = allEntries.concat(Array.from(batch));
                    readBatch();
                }, function () { resolve(); });
            };
            readBatch();
        } else {
            resolve();
        }
    });
}

async function handleExternalDrop(event, targetFolderId) {
    var items = event.dataTransfer.items;
    var fileList = [];

    if (items && items.length && typeof items[0].webkitGetAsEntry === 'function') {
        var entries = [];
        for (var i = 0; i < items.length; i++) {
            var entry = items[i].webkitGetAsEntry();
            if (entry) entries.push(entry);
        }
        await Promise.all(entries.map(function (ent) {
            return collectEntry(ent, '', fileList);
        }));
    } else {
        var files = event.dataTransfer.files;
        for (var j = 0; j < files.length; j++) {
            fileList.push({ file: files[j], relPath: '' });
        }
    }

    if (!fileList.length) return;
    await uploadFilesSequentially(fileList, targetFolderId);
}

/* ---- "Upload Folder" button (webkitdirectory input) ---- */
document.getElementById('uploadFolderInput').addEventListener('change', function () {
    var input = this;
    if (!input.files || !input.files.length) return;

    var fileList = [];
    for (var i = 0; i < input.files.length; i++) {
        var file = input.files[i];
        var rel = file.webkitRelativePath || '';
        var parts = rel.split('/');
        parts.pop(); // remove filename
        fileList.push({ file: file, relPath: parts.join('/') });
    }

    uploadFilesSequentially(fileList, CURRENT_FOLDER_ID).finally(function () {
        input.value = '';
    });
});

/* ---- AJAX upload one file at a time ---- */
async function uploadOneFile(entry, targetFolderId) {
    var formData = new FormData();
    formData.append('action', 'upload_doc_ajax');
    formData.append('venture_id', VENTURE_ID);
    formData.append('folder_id', targetFolderId);
    formData.append('folder_path', entry.relPath || '');
    formData.append('category', 'other');
    formData.append('is_visible_to_investors', '0');
    formData.append('doc_file', entry.file, entry.file.name);

    var res = await fetch(PROCESS_URL, { method: 'POST', body: formData });
    return res.json();
}

async function uploadFilesSequentially(fileList, targetFolderId) {
    var done = 0;
    var total = fileList.length;
    var failures = [];

    showUploadToast('Uploading 1 of ' + total + '...', 0);

    for (var i = 0; i < fileList.length; i++) {
        var entry = fileList[i];
        showUploadToast('Uploading ' + (done + 1) + ' of ' + total + '...', Math.round((done / total) * 100));

        try {
            var result = await uploadOneFile(entry, targetFolderId);
            if (!result.success) {
                failures.push((entry.relPath ? entry.relPath + '/' : '') + entry.file.name + ': ' + result.message);
            }
        } catch (err) {
            failures.push(entry.file.name + ': upload failed.');
        }

        done++;
        showUploadToast('Uploaded ' + done + ' of ' + total, Math.round((done / total) * 100));
    }

    showUploadToast('Done - ' + done + ' file(s)', 100);

    if (failures.length) {
        alert('Some files could not be uploaded:\n' + failures.join('\n'));
    }

    setTimeout(function () {
        hideUploadToast();
        window.location.reload();
    }, 800);
}

/* ---- global keyboard ---- */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeDocModal();
        closeVersionModal();
        closeRejectDocModal();
        closeRejectChangeModal();
        closeNewFolderModal();
        closeRenameFolderModal();
        closeMoveModal();
    }
});
</script>

</body>
</html>
