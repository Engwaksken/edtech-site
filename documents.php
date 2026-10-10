<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/doc-folders.php';

$page_title  = 'Documents';
$current_nav = 'documents.php';

pp_ensure_document_folders($conn);

$filter_cat  = $_GET['cat'] ?? '';
$open_upload = isset($_GET['action']) && $_GET['action'] === 'upload';

$active_tab = $_GET['tab'] ?? 'vault';
if (!in_array($active_tab, ['vault', 'reports'], true)) {
    $active_tab = 'vault';
}

// --------------------------------------------------------------
//  CURRENT FOLDER (validated against this venture)
// --------------------------------------------------------------
$folder_param       = $_GET['folder'] ?? '';
$current_folder_id  = (ctype_digit((string)$folder_param) && (int)$folder_param > 0) ? (int)$folder_param : 0;

if ($current_folder_id > 0) {
    $fchk = $conn->prepare("SELECT id FROM venture_document_folders WHERE id = ? AND venture_id = ? LIMIT 1");
    $fchk->bind_param('ii', $current_folder_id, $venture_id);
    $fchk->execute();
    if (!$fchk->get_result()->fetch_assoc()) {
        $current_folder_id = 0;
    }
    $fchk->close();
}

$breadcrumb = $current_folder_id > 0 ? pp_folder_breadcrumb($conn, $venture_id, $current_folder_id) : [];

// --------------------------------------------------------------
//  SUBFOLDERS OF CURRENT FOLDER
//  Root safety: also match parent_id = 0 left by old buggy inserts
// --------------------------------------------------------------
if ($current_folder_id > 0) {
    $sub_stmt = $conn->prepare("
        SELECT f.*,
          (SELECT COUNT(*) FROM venture_document_folders sf WHERE sf.parent_id = f.id) AS subfolder_count,
          (SELECT COUNT(*) FROM venture_documents d WHERE d.folder_id = f.id AND d.is_latest = 1) AS doc_count
        FROM venture_document_folders f
        WHERE f.venture_id = ? AND f.parent_id = ?
        ORDER BY f.folder_name ASC
    ");
    $sub_stmt->bind_param('ii', $venture_id, $current_folder_id);
} else {
    $sub_stmt = $conn->prepare("
        SELECT f.*,
          (SELECT COUNT(*) FROM venture_document_folders sf WHERE sf.parent_id = f.id) AS subfolder_count,
          (SELECT COUNT(*) FROM venture_documents d WHERE d.folder_id = f.id AND d.is_latest = 1) AS doc_count
        FROM venture_document_folders f
        WHERE f.venture_id = ? AND (f.parent_id IS NULL OR f.parent_id = 0)
        ORDER BY f.folder_name ASC
    ");
    $sub_stmt->bind_param('i', $venture_id);
}
$sub_stmt->execute();
$folders_arr = $sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sub_stmt->close();

// --------------------------------------------------------------
//  DOCUMENTS IN CURRENT FOLDER (+ optional category filter)
//  Root safety: also match folder_id = 0 left by old buggy inserts
// --------------------------------------------------------------
$base_select = "
    SELECT d.*,
      (SELECT COUNT(*) FROM venture_documents dv WHERE dv.venture_id = d.venture_id AND dv.doc_group = d.doc_group) AS version_count
    FROM venture_documents d
    WHERE d.venture_id = ? AND d.is_latest = 1
";

if ($filter_cat !== '' && $current_folder_id > 0) {
    $docs_stmt = $conn->prepare($base_select . " AND d.category = ? AND d.folder_id = ? ORDER BY d.category, d.uploaded_at DESC");
    $docs_stmt->bind_param('isi', $venture_id, $filter_cat, $current_folder_id);
} elseif ($filter_cat !== '') {
    $docs_stmt = $conn->prepare($base_select . " AND d.category = ? AND (d.folder_id IS NULL OR d.folder_id = 0) ORDER BY d.category, d.uploaded_at DESC");
    $docs_stmt->bind_param('is', $venture_id, $filter_cat);
} elseif ($current_folder_id > 0) {
    $docs_stmt = $conn->prepare($base_select . " AND d.folder_id = ? ORDER BY d.category, d.uploaded_at DESC");
    $docs_stmt->bind_param('ii', $venture_id, $current_folder_id);
} else {
    $docs_stmt = $conn->prepare($base_select . " AND (d.folder_id IS NULL OR d.folder_id = 0) ORDER BY d.category, d.uploaded_at DESC");
    $docs_stmt->bind_param('i', $venture_id);
}
$docs_stmt->execute();
$docs = $docs_stmt->get_result();
$docs_stmt->close();

$doc_categories = [
    'pitch_deck'      => ['Pitch Deck',     'fa-file-powerpoint', '#ef4444'],
    'financial_model' => ['Financial Model','fa-file-excel',      '#10b981'],
    'business_plan'   => ['Business Plan',  'fa-file-word',       '#3b82f6'],
    'legal'           => ['Legal Docs',     'fa-file-contract',   '#8b5cf6'],
    'product_demo'    => ['Product Demo',   'fa-film',            '#f59e0b'],
    'team_profile'    => ['Team Profiles',  'fa-users',           '#06b6d4'],
    'due_diligence'   => ['Due Diligence',  'fa-search-dollar',   '#6366f1'],
    'impact_report'   => ['Impact Report',  'fa-chart-bar',       '#84cc16'],
    'other'           => ['Other',          'fa-file-alt',        '#6b7280'],
];

$s3_enabled = defined('AWS_S3_BUCKET') && defined('AWS_ACCESS_KEY');

function fmt_bytes(int $b): string {
    if ($b<1024) return $b.' B';
    if ($b<1048576) return round($b/1024,1).' KB';
    return round($b/1048576,2).' MB';
}

function fmt_ext_icon(string $ext): array {
    $ext = strtolower($ext);
    $map = [
        'pdf'  => ['fa-file-pdf', '#ef4444'],
        'doc'  => ['fa-file-word', '#3b82f6'],
        'docx' => ['fa-file-word', '#3b82f6'],
        'xls'  => ['fa-file-excel', '#10b981'],
        'xlsx' => ['fa-file-excel', '#10b981'],
        'ppt'  => ['fa-file-powerpoint', '#ef4444'],
        'pptx' => ['fa-file-powerpoint', '#ef4444'],
    ];
    return $map[$ext] ?? ['fa-file-alt', '#6b7280'];
}

function vp_doc_file_meta(string $fileName): array
{
    $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
    $map = [
        'pdf'=>['fa-file-pdf','#ef4444','PDF'],
        'doc'=>['fa-file-word','#3b82f6','Word'], 'docx'=>['fa-file-word','#3b82f6','Word'],
        'xls'=>['fa-file-excel','#10b981','Excel'], 'xlsx'=>['fa-file-excel','#10b981','Excel'],
        'csv'=>['fa-file-csv','#10b981','CSV'],
        'ppt'=>['fa-file-powerpoint','#ef4444','PowerPoint'], 'pptx'=>['fa-file-powerpoint','#ef4444','PowerPoint'],
        'txt'=>['fa-file-alt','#64748b','Text'], 'rtf'=>['fa-file-alt','#64748b','RTF'],
        'odt'=>['fa-file-word','#2563eb','OpenDocument'], 'ods'=>['fa-file-excel','#059669','OpenDocument'],
        'odp'=>['fa-file-powerpoint','#dc2626','OpenDocument'],
        'jpg'=>['fa-file-image','#8b5cf6','Image'], 'jpeg'=>['fa-file-image','#8b5cf6','Image'],
        'png'=>['fa-file-image','#8b5cf6','Image'], 'gif'=>['fa-file-image','#8b5cf6','Image'],
        'webp'=>['fa-file-image','#8b5cf6','Image'], 'svg'=>['fa-file-image','#8b5cf6','SVG'],
        'mp4'=>['fa-file-video','#f59e0b','Video'], 'mov'=>['fa-file-video','#f59e0b','Video'],
        'avi'=>['fa-file-video','#f59e0b','Video'], 'mkv'=>['fa-file-video','#f59e0b','Video'],
        'webm'=>['fa-file-video','#f59e0b','Video'],
        'mp3'=>['fa-file-audio','#06b6d4','Audio'], 'wav'=>['fa-file-audio','#06b6d4','Audio'],
        'm4a'=>['fa-file-audio','#06b6d4','Audio'], 'ogg'=>['fa-file-audio','#06b6d4','Audio'],
        'zip'=>['fa-file-archive','#78716c','Archive'], 'rar'=>['fa-file-archive','#78716c','Archive'],
        '7z'=>['fa-file-archive','#78716c','Archive'],
        'json'=>['fa-file-code','#475569','JSON'], 'xml'=>['fa-file-code','#475569','XML'],
        'html'=>['fa-file-code','#475569','HTML'], 'htm'=>['fa-file-code','#475569','HTML'],
    ];
    return $map[$ext] ?? ['fa-file','#6b7280',$ext !== '' ? strtoupper($ext) : 'File'];
}



$session_reports_arr = [];
$reports_table_exists = $conn->query("SHOW TABLES LIKE 'session_reports'")->num_rows > 0;

if ($reports_table_exists) {
    $reports_stmt = $conn->prepare("
        SELECT sr.*,
               ms.title       AS session_title,
               ms.scheduled_at AS session_date,
               ms.status      AS session_status,
               m.full_name    AS mentor_name,
               m.photo        AS mentor_photo
        FROM session_reports sr
        JOIN mentor_sessions ms ON ms.id = sr.session_id
        JOIN mentors m          ON m.id  = ms.mentor_id
        WHERE ms.venture_id = ?
        ORDER BY sr.uploaded_at DESC
    ");
    $reports_stmt->bind_param('i', $venture_id);
    $reports_stmt->execute();
    $reports_rs = $reports_stmt->get_result();
    while ($r = $reports_rs->fetch_assoc()) {
        $session_reports_arr[] = $r;
    }
    $reports_stmt->close();
}

// Quick counts for tab badges (parameterized, was raw $venture_id interpolation)
$vc_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM venture_documents WHERE venture_id = ? AND is_latest = 1");
$vc_stmt->bind_param('i', $venture_id);
$vc_stmt->execute();
$vault_count = (int)($vc_stmt->get_result()->fetch_assoc()['c'] ?? 0);
$vc_stmt->close();

$reports_count = count($session_reports_arr);

include 'layout.php';
?>

<style>
/* ---- Folder / drag-and-drop additions ---- */
.doc-breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-bottom:16px;font-size:13px;color:var(--muted)}
.doc-breadcrumb a,.doc-breadcrumb .crumb-current{color:var(--muted);text-decoration:none;padding:4px 8px;border-radius:6px;transition:background .15s,color .15s}
.doc-breadcrumb a:hover{background:rgba(0,0,0,.05);color:var(--ink)}
.doc-breadcrumb .crumb-current{color:var(--ink);font-weight:700}
.doc-breadcrumb .crumb-sep{opacity:.5;padding:0 2px}
.doc-breadcrumb .drop-target.drag-over-target{background:rgba(252,127,16,.15);outline:2px dashed var(--gold, #fc7f10)}

.folder-toolbar-row{display:flex;gap:8px;flex-wrap:wrap}

/* page-level overlay shown when OS files are dragged over the vault area */
.vault-drop-zone{position:relative}
.vault-drop-zone.drag-active::after{
  content:'Drop files or folders to upload';
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(252,127,16,.06);border:2px dashed var(--gold, #fc7f10);border-radius:12px;
  font-weight:700;color:var(--gold, #fc7f10);font-size:14px;pointer-events:none;z-index:5;
}

.folder-card{
  border:1px solid var(--border, #e5e7eb);border-radius:12px;padding:14px;
  background:#fff;transition:box-shadow .15s,border-color .15s,background .15s;
  display:flex;flex-direction:column;gap:10px;user-select:none;
}
.folder-card:hover{box-shadow:0 2px 10px rgba(0,0,0,.06);cursor:pointer}
.folder-card.dragging{opacity:.4}
.folder-card.drag-over-target{border-color:var(--gold, #fc7f10);background:rgba(252,127,16,.06);box-shadow:0 0 0 2px rgba(252,127,16,.25)}
.folder-card-top{display:flex;align-items:center;gap:10px}
.folder-icon{width:40px;height:40px;border-radius:10px;background:#fef3e2;color:#fc7f10;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.folder-name{font-weight:700;font-size:14px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.folder-meta{font-size:11.5px;color:var(--muted);margin-top:2px}
.folder-card-actions{display:flex;justify-content:flex-end;gap:6px}
.folder-card-actions form{display:inline}

.drive-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-4px 0 14px}
.drive-location{display:flex;align-items:center;gap:8px;color:var(--ink);font-size:13px;font-weight:750}
.drive-location i{color:#f2b93b}
.drive-view-toggle{display:flex;gap:3px;padding:3px;border:1px solid var(--border);border-radius:9px;background:#fff}
.drive-view-toggle button{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border:0;border-radius:6px;background:transparent;color:var(--muted);font-size:11px;font-weight:700;cursor:pointer}
.drive-view-toggle button.active{background:#fff3e0;color:#a64b00}
.drive-list-heading{display:none}
.drive-list-view .drive-list-heading{display:grid;grid-template-columns:minmax(0,1fr) 180px;gap:12px;padding:8px 14px;border-bottom:1px solid var(--border);color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.drive-list-view .doc-grid{display:flex;flex-direction:column;gap:0}
.drive-list-view .folder-card{display:grid;grid-template-columns:minmax(0,1fr) 180px;align-items:center;gap:12px;min-height:64px;padding:7px 14px;border:0;border-bottom:1px solid #edf0f4;border-radius:0;background:transparent;box-shadow:none}
.drive-list-view .folder-card:hover,.drive-list-view .doc-card:hover{background:#f8fafc;box-shadow:none}
.drive-list-view .folder-card-top{min-width:0;align-items:center}
.drive-list-view .folder-icon{width:34px;height:34px;background:#fff5d8;color:#e4a900}
.drive-list-view .folder-meta{margin:0;color:var(--muted)}
.drive-list-view .folder-card-actions{opacity:0;transition:opacity .15s ease}
.drive-list-view .folder-card:hover .folder-card-actions,.drive-list-view .folder-card:focus-within .folder-card-actions{opacity:1}
.drive-list-view .doc-card{display:grid;grid-template-columns:minmax(0,1fr) 180px;align-items:center;gap:12px;padding:6px 14px;border:0;border-bottom:1px solid #edf0f4;border-radius:0;background:transparent;box-shadow:none}
.drive-list-view .doc-card-top{min-width:0;align-items:center;padding:4px 0}
.drive-list-view .doc-meta{line-height:1.45}
.drive-list-view .doc-card-footer{margin:0;padding:0;border:0;background:transparent}
.drive-list-view .visibility-badge{display:none}
.drive-list-view .doc-card-footer>div:last-child{justify-content:flex-end}

.doc-card{user-select:none}
.doc-card.dragging{opacity:.4}

.upload-progress-toast{
  position:fixed;bottom:20px;right:20px;background:var(--ink,#111);color:#fff;
  padding:10px 16px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;
  box-shadow:0 6px 20px rgba(0,0,0,.25);
}

.section-label{font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:4px 0 10px}

.other-category-name{display:none;margin-top:10px}
.other-category-name.show{display:block}
.file-viewer-modal .vp-modal{width:min(1100px,96vw);max-width:1100px}
.file-viewer-body{padding:0;min-height:65vh;background:#f8fafc;position:relative}
.file-viewer-frame{display:block;width:100%;height:72vh;border:0;background:#fff}
.file-viewer-image{display:block;max-width:100%;max-height:72vh;margin:auto;padding:18px;object-fit:contain}
.file-viewer-video,.file-viewer-audio{display:block;width:min(900px,94%);margin:28px auto}
.file-viewer-text{white-space:pre-wrap;word-break:break-word;padding:22px;margin:0;max-height:72vh;overflow:auto;font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;color:#0f172a;background:#fff}
.file-viewer-fallback{display:flex;min-height:65vh;align-items:center;justify-content:center;text-align:center;padding:30px}
.file-viewer-fallback i{font-size:46px;margin-bottom:14px;color:var(--gold,#fc7f10)}
.file-viewer-actions{display:flex;gap:8px;align-items:center}

/* ---- Phone/tablet responsiveness ---- */
@media (max-width: 768px){
  .doc-toolbar{display:flex!important;flex-direction:column!important;align-items:stretch!important;gap:12px!important}
  .folder-toolbar-row{display:grid!important;grid-template-columns:1fr 1fr;gap:8px;width:100%}
  .folder-toolbar-row .btn{width:100%;justify-content:center;min-height:42px}
  .docs-grid,.folders-grid{grid-template-columns:1fr!important;gap:12px!important}
  .doc-card,.folder-card{width:100%;min-width:0}
  .doc-card-top{padding:14px!important}
  .doc-card-footer{padding:10px 12px!important;gap:8px;align-items:center;flex-wrap:wrap}
  .doc-card-footer>div:last-child,.folder-card-actions{margin-left:auto;display:flex!important;gap:6px!important;flex-wrap:wrap;justify-content:flex-end}
  .doc-card-footer .btn,.folder-card-actions .btn{width:38px;height:38px;padding:0!important;display:inline-flex;align-items:center;justify-content:center}
  .visibility-badge{max-width:48%;white-space:normal}
  .doc-breadcrumb{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px;-webkit-overflow-scrolling:touch}
  .doc-breadcrumb>*{flex:0 0 auto}
  .vp-modal-overlay{padding:10px!important;align-items:flex-start!important;overflow-y:auto!important}
  .vp-modal{width:100%!important;max-width:100%!important;margin:10px auto!important;max-height:calc(100dvh - 20px)!important;overflow:hidden!important}
  .vp-modal-header{padding:12px 14px!important;gap:8px;flex-wrap:wrap}
  .vp-modal-body{padding:14px!important;overflow-y:auto!important}
  .vp-modal-footer{padding:12px 14px!important;display:flex;gap:8px;flex-wrap:wrap}
  .vp-modal-footer .btn{flex:1 1 130px;justify-content:center}
  .file-viewer-modal .vp-modal{height:calc(100dvh - 20px)!important;display:flex;flex-direction:column}
  .file-viewer-modal .vp-modal-header{flex:0 0 auto}
  .file-viewer-body{flex:1 1 auto;min-height:0!important;overflow:auto}
  .file-viewer-frame{height:100%!important;min-height:60vh}
  .file-viewer-image{max-height:calc(100dvh - 150px);padding:8px}
  .file-viewer-actions{margin-left:auto}
  .file-viewer-actions .btn{font-size:0;width:38px;height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center}
  .file-viewer-actions .btn i{font-size:14px}
  .drive-list-view .drive-list-heading{grid-template-columns:minmax(0,1fr) auto;padding-inline:8px}
  .drive-list-view .folder-card,.drive-list-view .doc-card{grid-template-columns:minmax(0,1fr) auto;gap:6px;padding-inline:8px}
  .drive-list-view .folder-meta{display:none}
  .drive-list-view .folder-card-actions{opacity:1}
  .drive-list-view .doc-card-footer{grid-column:2;grid-row:1}
  .drive-list-view .doc-card-top{grid-column:1;grid-row:1}
  .drive-list-view .doc-card-footer>div:last-child{gap:2px!important}
  .drive-list-view .doc-card-footer .btn{width:32px;height:32px}
}
@media (max-width: 420px){
  .folder-toolbar-row{grid-template-columns:1fr}
  .visibility-badge{max-width:100%;width:100%}
  .doc-card-footer>div:last-child{width:100%;justify-content:flex-end}
}

</style>

<div class="doc-toolbar">
  <div>
    <h2 style="font-family:'Syne',sans-serif;font-size:19px;font-weight:800;color:var(--ink)">Documents</h2>
    <p style="font-size:12.5px;color:var(--muted);margin-top:2px">
      <?= $s3_enabled ? '<span style="color:var(--gold);font-weight:600"><i class="fa fa-cloud"></i> AWS S3</span> &middot; ' : '' ?>
      Your document vault, plus session reports shared by you and your mentors
    </p>
  </div>
  <?php if ($active_tab === 'vault'): ?>
    <div class="folder-toolbar-row">
      <button class="btn btn-outline" onclick="openNewFolderModal()">
        <i class="fa fa-folder-plus"></i> New Folder
      </button>
      <button class="btn btn-outline" onclick="document.getElementById('upFolderInput').click()">
        <i class="fa fa-upload"></i> Upload Folder
      </button>
      <button class="btn btn-primary" onclick="openUploadModal()">
        <i class="fa fa-cloud-upload-alt"></i> Upload Document
      </button>
    </div>
    <input type="file" id="upFolderInput" webkitdirectory directory multiple style="display:none">
  <?php endif; ?>
</div>

<!-- Tabs: Document Vault vs Session Reports -->
<div class="doc-tabs">
  <a href="?tab=vault" class="doc-tab <?= $active_tab==='vault'?'active':'' ?>">
    <i class="fa fa-folder-open"></i> Document Vault
    <span class="tab-count"><?= (int)$vault_count ?></span>
  </a>
  <a href="?tab=reports" class="doc-tab <?= $active_tab==='reports'?'active':'' ?>">
    <i class="fa fa-file-medical-alt"></i> Session Reports
    <span class="tab-count"><?= (int)$reports_count ?></span>
  </a>
</div>


<?php if ($active_tab === 'vault'): ?>
<!-- ════════════════════════════════════════════════════════════
     TAB: DOCUMENT VAULT (venture's own documents)
═════════════════════════════════════════════════════════════ -->
<div class="vault-browser drive-list-view" id="vaultBrowser">

<!-- Breadcrumb -->
<div class="doc-breadcrumb">
  <a href="?tab=vault<?= $filter_cat!==''?'&cat='.urlencode($filter_cat):'' ?>" class="drop-target" draggable="false" data-folder-id="0">
    <i class="fa fa-house"></i> Vault
  </a>
  <?php foreach ($breadcrumb as $i => $crumb):
        $is_last = $i === count($breadcrumb) - 1; ?>
    <span class="crumb-sep"><i class="fa fa-chevron-right" style="font-size:10px"></i></span>
    <?php if ($is_last): ?>
      <span class="crumb-current drop-target" data-folder-id="<?= (int)$crumb['id'] ?>"><?= h($crumb['folder_name']) ?></span>
    <?php else: ?>
      <a href="?tab=vault&folder=<?= (int)$crumb['id'] ?><?= $filter_cat!==''?'&cat='.urlencode($filter_cat):'' ?>" class="drop-target" draggable="false" data-folder-id="<?= (int)$crumb['id'] ?>">
        <?= h($crumb['folder_name']) ?>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="drive-toolbar">
  <div class="drive-location"><i class="fa fa-hdd"></i> <?= $current_folder_id > 0 ? h($breadcrumb ? $breadcrumb[count($breadcrumb) - 1]['folder_name'] : 'Current folder') : 'My Drive' ?></div>
  <div class="drive-view-toggle" role="group" aria-label="Folder display mode">
    <button type="button" data-vault-view="list" onclick="setVaultView('list')" aria-label="List view" title="List view"><i class="fa fa-list"></i><span>List</span></button>
    <button type="button" data-vault-view="grid" onclick="setVaultView('grid')" aria-label="Grid view" title="Grid view"><i class="fa fa-th-large"></i><span>Grid</span></button>
  </div>
</div>

<!-- Category filters -->
<div class="cat-filters" style="margin-bottom:18px">
  <a href="?tab=vault<?= $current_folder_id>0?'&folder='.$current_folder_id:'' ?>" class="cat-filter-btn <?= $filter_cat===''?'active':'' ?>">All</a>
  <?php foreach ($doc_categories as $k => [$label,$icon,$color]): ?>
    <a href="?tab=vault&cat=<?= urlencode($k) ?><?= $current_folder_id>0?'&folder='.$current_folder_id:'' ?>" class="cat-filter-btn <?= $filter_cat===$k?'active':'' ?>"
       style="<?= $filter_cat===$k?'border-color:'.$color.';background:'.$color.';color:#fff':'' ?>">
      <i class="fa <?= $icon ?>"></i> <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php
$docs_arr = [];
while ($d = $docs->fetch_assoc()) $docs_arr[] = $d;

$has_folders = !empty($folders_arr);
$has_docs    = !empty($docs_arr);
?>

<div class="vault-drop-zone" id="vaultDropZone">

<?php if (!$has_folders && !$has_docs): ?>
<div class="empty-state-block">
  <i class="fa fa-folder-open"></i>
  <h3>This folder is empty</h3>
  <p>Upload a document, create a subfolder, or drag files in from your computer.</p>
  <button class="btn btn-primary" onclick="openUploadModal()"> Upload First Document</button>
</div>
<?php else: ?>

<?php if ($has_folders): ?>
<div class="section-label">Folders</div>
<div class="drive-list-heading" aria-hidden="true"><span>Name &amp; contents</span><span>Actions</span></div>
<div class="doc-grid drive-folder-grid" style="margin-bottom:22px">
<?php foreach ($folders_arr as $folder): ?>
  <div class="folder-card drop-target" draggable="true"
       data-item-type="folder"
       data-folder-id="<?= (int)$folder['id'] ?>"
       data-folder-name="<?= h(addslashes($folder['folder_name'])) ?>"
       onclick="if(!window.__vpDragging) navFolder(<?= (int)$folder['id'] ?>)">
    <div class="folder-card-top">
      <div class="folder-icon"><i class="fa fa-folder"></i></div>
      <div style="min-width:0;flex:1">
        <div class="folder-name"><?= h($folder['folder_name']) ?></div>
        <div class="folder-meta">
          <?= (int)$folder['subfolder_count'] ?> subfolder<?= (int)$folder['subfolder_count']===1?'':'s' ?>
          &middot; <?= (int)$folder['doc_count'] ?> file<?= (int)$folder['doc_count']===1?'':'s' ?>
        </div>
      </div>
    </div>
    <div class="folder-card-actions" onclick="event.stopPropagation()">
      <button type="button" class="btn btn-outline btn-sm js-rename-folder" title="Rename folder"
              data-folder-id="<?= (int)$folder['id'] ?>" data-folder-name="<?= h((string)$folder['folder_name']) ?>">
        <i class="fa fa-pen"></i>
      </button>
      <button type="button" class="btn btn-outline btn-sm js-move-item" title="Move folder"
              data-item-type="folder" data-item-id="<?= (int)$folder['id'] ?>" data-item-name="<?= h((string)$folder['folder_name']) ?>">
        <i class="fa fa-arrows-alt"></i>
      </button>
      <form method="POST" action="includes/portal-process.php" class="js-delete-folder-form" data-folder-name="<?= h((string)$folder['folder_name']) ?>">
        <input type="hidden" name="action" value="delete_folder">
        <input type="hidden" name="venture_id" value="<?= (int)$venture_id ?>">
        <input type="hidden" name="id" value="<?= (int)$folder['id'] ?>">
        <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">
        <button type="submit" class="btn btn-outline btn-sm" title="Delete folder"><i class="fa fa-trash"></i></button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($has_docs): ?>
<div class="section-label">Files</div>
<div class="drive-list-heading" aria-hidden="true"><span>Name &amp; file details</span><span>Actions</span></div>
<div class="doc-grid" id="docsGrid">
<?php foreach ($docs_arr as $doc):
  [$cat_label, $cat_icon, $cat_color] = $doc_categories[$doc['category']] ?? ['Other','fa-file-alt','#6b7280'];
  $storedFileName = (string)($doc['original_name'] ?? $doc['file_name'] ?? $doc['file_path'] ?? $doc['doc_name'] ?? '');
  [$file_icon, $file_color, $file_type_label] = vp_doc_file_meta($storedFileName);
  $otherCategoryName = trim((string)($doc['other_category_name'] ?? ''));
  if (($doc['category'] ?? '') === 'other' && $otherCategoryName !== '') {
      $cat_label = $otherCategoryName;
  }
?>
<div class="doc-card" draggable="true" data-item-type="doc" data-doc-id="<?= (int)$doc['id'] ?>">
  <div class="doc-card-top">
    <div class="doc-icon" style="background:<?= h($file_color) ?>"><i class="fa <?= h($file_icon) ?>"></i></div>
    <div style="min-width:0;flex:1">
      <div class="doc-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($doc['doc_name']) ?></div>
      <div class="doc-meta">
        <?= h($cat_label) ?> &middot; <?= h($file_type_label) ?> &middot; <?= fmt_bytes((int)$doc['file_size']) ?><br>
        <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?>
        <?php if ($doc['version_count'] > 1): ?> &middot; <span class="version-badge">v<?= $doc['version_number'] ?></span><?php endif; ?>
      </div>
      <?php if (!empty($doc['description'])): ?>
        <div class="doc-meta" style="margin-top:6px;font-style:italic"><?= h(mb_strimwidth($doc['description'],0,80,'...')) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="doc-card-footer">
    <div class="visibility-badge <?= $doc['is_visible_to_investors']?'vis-public':'vis-private' ?>">
      <i class="fa <?= $doc['is_visible_to_investors']?'fa-eye':'fa-eye-slash' ?>"></i>
      <?= $doc['is_visible_to_investors'] ? 'Visible to investors' : 'Private' ?>
    </div>
    <div style="display:flex;gap:6px">
      <?php if ($doc['version_count'] > 1): ?>
        <button type="button" class="btn btn-outline btn-sm js-show-versions" title="Version history"
                data-doc-name="<?= h((string)$doc['doc_name']) ?>" data-doc-group="<?= h((string)$doc['doc_group']) ?>">
          <i class="fa fa-history"></i>
        </button>
      <?php endif; ?>
      <button type="button" class="btn btn-outline btn-sm js-move-item" title="Move to folder"
              data-item-type="doc" data-item-id="<?= (int)$doc['id'] ?>" data-item-name="<?= h((string)$doc['doc_name']) ?>">
        <i class="fa fa-arrows-alt"></i>
      </button>
      <button type="button" class="btn btn-outline btn-sm js-upload-version" title="Upload new version"
              data-doc-group="<?= h((string)$doc['doc_group']) ?>" data-category="<?= h((string)$doc['category']) ?>" data-doc-name="<?= h((string)$doc['doc_name']) ?>">
        <i class="fa fa-redo"></i>
      </button>
      <button type="button" class="btn btn-outline btn-sm js-view-file" title="View file"
              data-doc-id="<?= (int)$doc['id'] ?>" data-doc-name="<?= h((string)$doc['doc_name']) ?>" data-file-name="<?= h((string)$storedFileName) ?>">
        <i class="fa fa-eye"></i>
      </button>
      <a href="includes/portal-process.php?action=download_doc&id=<?= (int)$doc['id'] ?>" class="btn btn-outline btn-sm" title="Download" draggable="false">
        <i class="fa fa-download"></i>
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

</div><!-- /.vault-drop-zone -->
</div><!-- /.vault-browser -->

<?php else: ?>

<?php if (empty($session_reports_arr)): ?>
<div class="empty-state-block">

  <h3>No session reports yet</h3>
  <p>Reports uploaded by you or your mentors after a mentoring session will appear here.</p>
  <a href="sessions.php" class="btn btn-primary">Go to Sessions</a>
</div>
<?php else: ?>
<div class="doc-grid">
<?php foreach ($session_reports_arr as $rep):
  $ext = (string)($rep['file_ext'] ?? pathinfo((string)$rep['file_name'], PATHINFO_EXTENSION));
  [$rep_icon, $rep_color] = fmt_ext_icon($ext);

  $uploaded_by_raw = strtolower(trim((string)($rep['uploaded_by'] ?? '')));
  $is_mentor_upload = ($uploaded_by_raw === 'mentor');
  // Fallback heuristic: if uploaded_by matches the mentor's name, treat as mentor upload.
  if (!$is_mentor_upload && !empty($rep['mentor_name']) && $uploaded_by_raw === strtolower($rep['mentor_name'])) {
      $is_mentor_upload = true;
  }

  $mentor_initials = strtoupper(substr(trim((string)($rep['mentor_name'] ?? 'M')), 0, 2));
  $session_status  = (string)($rep['session_status'] ?? '');
  $session_date    = !empty($rep['session_date']) ? date('M j, Y', strtotime($rep['session_date'])) : 'Date TBC';
?>
<div class="doc-card">
  <div class="doc-card-top">
    <div class="doc-icon" style="background:<?= $rep_color ?>"><i class="fa <?= $rep_icon ?>"></i></div>
    <div style="min-width:0;flex:1">
      <div class="doc-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= h($rep['report_title'] ?: $rep['file_name']) ?>
      </div>
      <div class="doc-meta">
        <?= h(strtoupper($ext)) ?> &middot; <?= fmt_bytes((int)($rep['file_size'] ?? 0)) ?><br>
        <?= !empty($rep['uploaded_at']) ? h(date('M j, Y', strtotime($rep['uploaded_at']))) : '' ?>
      </div>

      <div class="report-card-meta-row">
        <span class="source-badge <?= $is_mentor_upload ? 'source-mentor' : 'source-venture' ?>">
          <i class="fa <?= $is_mentor_upload ? 'fa-user-tie' : 'fa-rocket' ?>"></i>
          <?= $is_mentor_upload ? 'From mentor' : 'Uploaded by you' ?>
        </span>
        <span class="session-status-pill <?= $session_status==='completed'?'status-completed':'status-other' ?>">
          <?= h(ucfirst(str_replace('_',' ', $session_status ?: 'session'))) ?>
        </span>
      </div>

      <div class="report-mentor-mini" style="margin-top:7px">
        <?php if (!empty($rep['mentor_photo'])): ?>
          <img src="<?= SITE_URL . '/' . h($rep['mentor_photo']) ?>" class="report-mentor-avatar" alt="">
        <?php else: ?>
          <div class="report-mentor-initials"><?= h($mentor_initials) ?></div>
        <?php endif; ?>
        <span><?= h($rep['mentor_name'] ?? 'Mentor') ?> &middot; <?= h($rep['session_title'] ?? 'Session') ?> (<?= h($session_date) ?>)</span>
      </div>
    </div>
  </div>

  <?php if (!empty($rep['report_notes'])): ?>
    <div class="report-notes-block"><?= h(mb_strimwidth($rep['report_notes'], 0, 110, '...')) ?></div>
  <?php endif; ?>

  <div class="doc-card-footer">
    <div class="visibility-badge vis-private">
      <i class="fa fa-link"></i>
      <a href="sessions.php" style="color:var(--muted);text-decoration:none">View session</a>
    </div>
    <div style="display:flex;gap:6px">
      <a href="includes/portal-process.php?action=session_report_pdf&id=<?= (int)$rep['id'] ?>" class="btn btn-outline btn-sm" title="Download as PDF" target="_blank">
        <i class="fa fa-file-pdf"></i>
      </a>
      <a href="includes/portal-process.php?action=download_session_report&id=<?= (int)$rep['id'] ?>" class="btn btn-outline btn-sm" title="Download original file">
        <i class="fa fa-download"></i>
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif;  ?>


<!-- ══════════ UPLOAD MODAL (vault only) ══════════ -->
<div class="vp-modal-overlay" id="uploadModal" <?= $open_upload?'style="opacity:1;visibility:visible;pointer-events:all"':'' ?>>
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title" id="uploadModalTitle">Upload Document</div>
    <button type="button" class="vp-modal-close" onclick="closeUploadModal()">&times;</button>
  </div>
  <form method="POST" action="includes/portal-process.php" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload_doc">
    <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
    <input type="hidden" name="doc_group" id="up_doc_group">
    <input type="hidden" name="folder_id" id="up_folder_id" value="<?= (int)$current_folder_id ?>">
    <div class="vp-modal-body">

      <?php if ($current_folder_id > 0 && !empty($breadcrumb)): ?>
        <p style="font-size:12px;color:var(--muted);margin:-4px 0 14px">
          <i class="fa fa-folder"></i> Uploading into
          <strong style="color:var(--ink)"><?= h(end($breadcrumb)['folder_name']) ?></strong>
        </p>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Document Name <span class="req">*</span></label>
        <input type="text" name="doc_name" id="up_name" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Category</label>
        <select name="category" id="up_category" class="form-control" onchange="toggleOtherCategoryName()">
          <?php foreach ($doc_categories as $k => [$label,$icon,$color]): ?>
            <option value="<?= h($k) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="other-category-name" id="otherCategoryNameWrap">
          <label class="form-label" for="up_other_category_name">Other Category Name <span class="req">*</span></label>
          <input type="text" name="other_category_name" id="up_other_category_name"
                 class="form-control" maxlength="120"
                 placeholder="e.g. Board Resolution, Certificate, Research Report">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Visibility</label>
        <select name="is_visible_to_investors" class="form-control">
          <option value="0">Private (not shared with investors)</option>
          <option value="1">Share with matched investors</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Notes (optional)</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Version notes, context..."></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">File <span class="req">*</span></label>

        <div class="upload-zone" id="upZone"
             onclick="document.getElementById('upFile').click()"
             ondragover="handleUpDragOver(event)"
             ondragleave="handleUpDragLeave(event)"
             ondrop="handleUpDrop(event)">
          <div id="upZoneContent">

            <strong>Click or drag to upload</strong>
            <span>Documents, images, audio, video and archives &middot; Max <?= $s3_enabled?'500 MB':'50 MB' ?></span>
          </div>
        </div>
        <input type="file" name="doc_file" id="upFile" style="display:none"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.rtf,.odt,.ods,.odp,.png,.jpg,.jpeg,.gif,.webp,.svg,.mp4,.mov,.avi,.mkv,.webm,.mp3,.wav,.m4a,.ogg,.zip,.rar,.7z,.json,.xml,.html,.htm"
               onchange="handleUpSelect(this)">
      </div>

    </div>
    <div class="vp-modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeUploadModal()">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Upload</button>
    </div>
  </form>
</div>
</div>

<!-- ══════════ FILE VIEWER MODAL ══════════ -->
<div class="vp-modal-overlay file-viewer-modal" id="fileViewerModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title" id="fileViewerTitle">View File</div>
    <div class="file-viewer-actions">
      <a href="#" id="fileViewerDownload" class="btn btn-outline btn-sm"><i class="fa fa-download"></i> Download</a>
      <button type="button" class="vp-modal-close" onclick="closeFileViewer()">&times;</button>
    </div>
  </div>
  <div class="file-viewer-body" id="fileViewerBody"></div>
</div>
</div>

<!-- ══════════ NEW FOLDER MODAL ══════════ -->
<div class="vp-modal-overlay" id="newFolderModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title">New Folder</div>
    <button type="button" class="vp-modal-close" onclick="document.getElementById('newFolderModal').classList.remove('open')">&times;</button>
  </div>
  <form method="POST" action="includes/portal-process.php">
    <input type="hidden" name="action" value="create_folder">
    <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
    <input type="hidden" name="parent_id" value="<?= (int)$current_folder_id ?>">
    <div class="vp-modal-body">
      <?php if ($current_folder_id > 0 && !empty($breadcrumb)): ?>
        <p style="font-size:12px;color:var(--muted);margin:-4px 0 14px">
          <i class="fa fa-folder"></i> Inside
          <strong style="color:var(--ink)"><?= h(end($breadcrumb)['folder_name']) ?></strong>
        </p>
      <?php endif; ?>
      <div class="form-group">
        <label class="form-label">Folder Name <span class="req">*</span></label>
        <input type="text" name="folder_name" class="form-control" required autofocus placeholder="e.g. Legal Contracts">
      </div>
    </div>
    <div class="vp-modal-footer">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('newFolderModal').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-folder-plus"></i> Create Folder</button>
    </div>
  </form>
</div>
</div>

<!-- ══════════ RENAME FOLDER MODAL ══════════ -->
<div class="vp-modal-overlay" id="renameFolderModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title">Rename Folder</div>
    <button type="button" class="vp-modal-close" onclick="document.getElementById('renameFolderModal').classList.remove('open')">&times;</button>
  </div>
  <form method="POST" action="includes/portal-process.php">
    <input type="hidden" name="action" value="rename_folder">
    <input type="hidden" name="venture_id" value="<?= $venture_id ?>">
    <input type="hidden" name="id" id="rename_folder_id">
    <input type="hidden" name="return_folder" value="<?= (int)$current_folder_id ?>">
    <div class="vp-modal-body">
      <div class="form-group">
        <label class="form-label">Folder Name <span class="req">*</span></label>
        <input type="text" name="folder_name" id="rename_folder_name" class="form-control" required>
      </div>
    </div>
    <div class="vp-modal-footer">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('renameFolderModal').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> Save</button>
    </div>
  </form>
</div>
</div>

<!-- ══════════ MOVE MODAL (doc or folder) ══════════ -->
<div class="vp-modal-overlay" id="moveModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title" id="moveModalTitle">Move</div>
    <button type="button" class="vp-modal-close" onclick="document.getElementById('moveModal').classList.remove('open')">&times;</button>
  </div>
  <div class="vp-modal-body">
    <div class="form-group">
      <label class="form-label">Destination folder</label>
      <select id="moveFolderSelect" class="form-control">
        <option value="0">Loading&hellip;</option>
      </select>
    </div>
  </div>
  <div class="vp-modal-footer">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('moveModal').classList.remove('open')">Cancel</button>
    <button type="button" class="btn btn-primary" id="moveConfirmBtn"><i class="fa fa-arrows-alt"></i> Move</button>
  </div>
</div>
</div>

<!-- ══════════ VERSION HISTORY MODAL ══════════ -->
<div class="vp-modal-overlay" id="versionModal">
<div class="vp-modal">
  <div class="vp-modal-header">
    <div class="vp-modal-title" id="versionModalTitle">Version History</div>
    <button type="button" class="vp-modal-close" onclick="document.getElementById('versionModal').classList.remove('open')">&times;</button>
  </div>
  <div class="vp-modal-body" id="versionModalBody">
    <p style="color:var(--muted);text-align:center;padding:20px">Loading...</p>
  </div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var ventureId       = <?= (int)$venture_id ?>;
    var currentFolderId = <?= (int)$current_folder_id ?>;
    var PROCESS         = 'includes/portal-process.php';

    window.setVaultView = function (view) {
        if (!['list', 'grid'].includes(view)) return;
        var browser = document.getElementById('vaultBrowser');
        if (!browser) return;
        browser.classList.toggle('drive-list-view', view === 'list');
        browser.classList.toggle('drive-grid-view', view === 'grid');
        document.querySelectorAll('[data-vault-view]').forEach(function (button) {
            var active = button.dataset.vaultView === view;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        try { localStorage.setItem('ventureDocumentsView', view); } catch (e) {}
    };
    var savedVaultView = 'list';
    try { savedVaultView = localStorage.getItem('ventureDocumentsView') || 'list'; } catch (e) {}
    window.setVaultView(savedVaultView);

    /* Dynamic card actions use data attributes instead of inline JavaScript.
       This prevents apostrophes/quotes in document or folder names from
       breaking onclick attributes with "Unexpected end of input". */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-view-file, .js-upload-version, .js-move-item, .js-show-versions, .js-rename-folder');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();

        if (button.classList.contains('js-view-file')) {
            window.openFileViewer(
                parseInt(button.dataset.docId || '0', 10),
                button.dataset.docName || 'Document',
                button.dataset.fileName || ''
            );
            return;
        }
        if (button.classList.contains('js-upload-version')) {
            window.openUploadModal(button.dataset.docGroup || '', button.dataset.category || '', button.dataset.docName || '');
            return;
        }
        if (button.classList.contains('js-move-item')) {
            window.openMoveModal(button.dataset.itemType || 'doc', parseInt(button.dataset.itemId || '0', 10), button.dataset.itemName || '');
            return;
        }
        if (button.classList.contains('js-show-versions')) {
            window.showVersions(button.dataset.docName || '', button.dataset.docGroup || '');
            return;
        }
        if (button.classList.contains('js-rename-folder')) {
            window.renameFolder(parseInt(button.dataset.folderId || '0', 10), button.dataset.folderName || '');
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.js-delete-folder-form');
        if (!form) return;
        var folderName = form.dataset.folderName || 'this folder';
        if (!window.confirm("Delete folder '" + folderName + "'? It must be empty.")) {
            event.preventDefault();
        }
    });

    window.toggleOtherCategoryName = function () {
        var category = document.getElementById('up_category');
        var wrap = document.getElementById('otherCategoryNameWrap');
        var input = document.getElementById('up_other_category_name');
        if (!category || !wrap || !input) return;
        var isOther = category.value === 'other';
        wrap.classList.toggle('show', isOther);
        input.required = isOther;
        if (!isOther) input.value = '';
    };

    window.openFileViewer = function (id, name, storedName) {
        var title = document.getElementById('fileViewerTitle');
        var body = document.getElementById('fileViewerBody');
        var download = document.getElementById('fileViewerDownload');
        if (!body) return;

        var sourceName = storedName || name || '';
        var ext = sourceName.indexOf('.') !== -1 ? sourceName.split('.').pop().toLowerCase() : '';
        var processUrl = 'includes/portal-process.php';
        var viewUrl = processUrl + '?action=view_doc&id=' + encodeURIComponent(id);
        var downloadUrl = processUrl + '?action=download_doc&id=' + encodeURIComponent(id);

        if (title) title.textContent = name || 'View File';
        if (download) download.href = downloadUrl;
        body.innerHTML = '';

        var images = ['jpg','jpeg','png','gif','webp','svg'];
        var videos = ['mp4','webm','mov','m4v'];
        var audio = ['mp3','wav','ogg','m4a','aac'];
        var textTypes = ['txt','json','xml','html','htm','md','log'];

        if (images.indexOf(ext) !== -1) {
            var img = document.createElement('img');
            img.className = 'file-viewer-image'; img.src = viewUrl; img.alt = name || 'File preview';
            body.appendChild(img);
        } else if (videos.indexOf(ext) !== -1) {
            var video = document.createElement('video');
            video.className = 'file-viewer-video'; video.controls = true; video.src = viewUrl;
            body.appendChild(video);
        } else if (audio.indexOf(ext) !== -1) {
            var a = document.createElement('audio');
            a.className = 'file-viewer-audio'; a.controls = true; a.src = viewUrl;
            body.appendChild(a);
        } else if (ext === 'pdf' || ['doc','docx','odt','rtf','xls','xlsx','csv','ods'].indexOf(ext) !== -1) {
            var frame = document.createElement('iframe');
            frame.className = 'file-viewer-frame';
            frame.src = viewUrl;
            frame.title = name || 'Document preview';
            body.appendChild(frame);
        } else if (textTypes.indexOf(ext) !== -1) {
            var pre = document.createElement('pre');
            pre.className = 'file-viewer-text'; pre.textContent = 'Loading preview...';
            body.appendChild(pre);
            fetch(viewUrl).then(function(r){ if(!r.ok) throw new Error('Preview failed'); return r.text(); })
                .then(function(value){ pre.textContent = value; })
                .catch(function(){ showViewerFallback(body, downloadUrl, ext); });
        } else {
            showViewerFallback(body, downloadUrl, ext);
        }

        var modal = document.getElementById('fileViewerModal');
        if (modal) {
            modal.style.opacity = '1';
            modal.style.visibility = 'visible';
            modal.style.pointerEvents = 'all';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    };

    function showViewerFallback(body, downloadUrl, ext) {
        body.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = 'file-viewer-fallback';
        var inner = document.createElement('div');
        inner.innerHTML = '<i class="fa fa-file"></i><h3>Preview is not available in this browser</h3>';
        var p = document.createElement('p');
        p.textContent = 'This ' + (ext ? ext.toUpperCase() : 'file') + ' format cannot be previewed by the installed viewer. You can download and open it with the appropriate application.';
        var a = document.createElement('a');
        a.className = 'btn btn-primary'; a.href = downloadUrl;
        a.innerHTML = '<i class="fa fa-download"></i> Download File';
        inner.appendChild(p); inner.appendChild(a); wrap.appendChild(inner); body.appendChild(wrap);
    }

    window.closeFileViewer = function () {
        var body = document.getElementById('fileViewerBody');
        if (body) body.innerHTML = '';
        var modal = document.getElementById('fileViewerModal');
        if (modal) {
            modal.style.opacity = '0';
            modal.style.visibility = 'hidden';
            modal.style.pointerEvents = 'none';
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
    };



    function openDocModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        document.body.classList.add('doc-modal-open');
    }

    function closeDocModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('open');
        document.body.classList.remove('doc-modal-open');
    }

    // ---------------------------------------------------------
    //  FOLDER NAVIGATION
    // ---------------------------------------------------------
    window.navFolder = function (id) {
        var params = new URLSearchParams(window.location.search);
        params.set('tab', 'vault');
        if (id > 0) { params.set('folder', id); } else { params.delete('folder'); }
        window.location.search = params.toString();
    };

    window.openNewFolderModal = function () { openDocModal('newFolderModal'); };

    window.renameFolder = function (id, currentName) {
        var idField = document.getElementById('rename_folder_id');
        var nameField = document.getElementById('rename_folder_name');
        if (!idField || !nameField) return;
        idField.value = id;
        nameField.value = currentName;
        openDocModal('renameFolderModal');
        window.setTimeout(function () { nameField.focus(); nameField.select(); }, 80);
    };

    // ---------------------------------------------------------
    //  UPLOAD MODAL (single file, click-driven)
    // ---------------------------------------------------------
    window.openUploadModal = function (group, cat, name) {
        group = group || ''; cat = cat || ''; name = name || '';
        var modal = document.getElementById('uploadModal');
        if (!modal) return;
        var form = modal.querySelector('form');
        if (form) form.reset();

        var gi = document.getElementById('up_doc_group');
        var ci = document.getElementById('up_category');
        var ni = document.getElementById('up_name');
        var fi = document.getElementById('up_folder_id');
        var t  = document.getElementById('uploadModalTitle');
        var z  = document.getElementById('upZoneContent');

        if (gi) gi.value = group;
        if (ci && cat) ci.value = cat;
        if (ni && name) ni.value = name;
        if (fi) fi.value = currentFolderId;
        if (t) t.textContent = group ? 'Upload New Version' : 'Upload Document';
        if (z) z.innerHTML = '<strong>Click or drag to upload</strong><span>PDF, DOCX, XLSX, PPTX, MP4, ZIP</span>';

        toggleOtherCategoryName();
        openDocModal('uploadModal');
    };

    window.closeUploadModal = function () { closeDocModal('uploadModal'); };

    window.handleUpSelect = function (input) {
        if (input.files && input.files[0]) {
            showUpFile(input.files[0]);
            var ni = document.getElementById('up_name');
            if (ni && !ni.value) ni.value = input.files[0].name.replace(/\.[^.]+$/, '');
        }
    };

    window.handleUpDragOver = function (e) { e.preventDefault(); var z = document.getElementById('upZone'); if (z) z.classList.add('drag-over'); };
    window.handleUpDragLeave = function () { var z = document.getElementById('upZone'); if (z) z.classList.remove('drag-over'); };
    window.handleUpDrop = function (e) {
        e.preventDefault();
        var z = document.getElementById('upZone'); if (z) z.classList.remove('drag-over');
        var file = e.dataTransfer.files[0]; if (!file) return;
        var dt = new DataTransfer(); dt.items.add(file);
        var inp = document.getElementById('upFile'); if (inp) inp.files = dt.files;
        showUpFile(file);
        var ni = document.getElementById('up_name');
        if (ni && !ni.value) ni.value = file.name.replace(/\.[^.]+$/, '');
    };

    function showUpFile(file) {
        var z = document.getElementById('upZoneContent'); if (!z) return;
        z.innerHTML = '<i class="fa fa-file-check" style="color:var(--success)"></i>' +
            '<strong>' + file.name + '</strong>' +
            '<span>' + (file.size / 1048576).toFixed(2) + ' MB</span>';
    }

    window.showVersions = async function (name, group) {
        var t = document.getElementById('versionModalTitle');
        var b = document.getElementById('versionModalBody');
        if (t) t.textContent = 'Versions - ' + name;
        if (b) b.innerHTML = '<p style="color:var(--muted);text-align:center;padding:20px">Loading...</p>';
        openDocModal('versionModal');
        try {
            var res = await fetch(PROCESS + '?action=version_history&doc_group=' + encodeURIComponent(group));
            if (b) b.innerHTML = await res.text();
        } catch (err) {
            if (b) b.innerHTML = '<p style="color:red;text-align:center;padding:20px">Failed to load versions.</p>';
        }
    };

    ['uploadModal','fileViewerModal','newFolderModal','renameFolderModal','moveModal','versionModal'].forEach(function (id) {
        var m = document.getElementById(id); if (!m) return;
        m.addEventListener('click', function (e) { if (e.target === m) closeDocModal(id); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeDocModal('uploadModal');  closeDocModal('newFolderModal');
            closeDocModal('renameFolderModal'); closeDocModal('moveModal');
            closeDocModal('versionModal');
        }
    });

    // ===========================================================
    //  MOVE (button-driven modal, works without drag-and-drop)
    // ===========================================================
    var moveTarget = null;

    window.openMoveModal = async function (type, id, label) {
        moveTarget = { type: type, id: id };
        document.getElementById('moveModalTitle').textContent = 'Move "' + label + '"';
        var sel = document.getElementById('moveFolderSelect');
        sel.innerHTML = '<option value="0">Loading\u2026</option>';
        openDocModal('moveModal');

        try {
            var res = await fetch(PROCESS + '?action=list_folders');
            var data = await res.json();
            sel.innerHTML = '';
            var root = document.createElement('option');
            root.value = '0'; root.textContent = '\u2014 Vault Root \u2014';
            sel.appendChild(root);
            if (data.success) {
                data.folders.forEach(function (f) {
                    if (type === 'folder' && f.id === id) return;
                    var opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = '\u2014\u00A0'.repeat(f.depth) + f.name;
                    sel.appendChild(opt);
                });
            }
        } catch (e) { sel.innerHTML = '<option value="0">\u2014 Vault Root \u2014</option>'; }
    };

    document.getElementById('moveConfirmBtn').addEventListener('click', async function () {
        if (!moveTarget) return;
        var ok = await moveItem(moveTarget.type, moveTarget.id, document.getElementById('moveFolderSelect').value);
        if (ok) window.location.reload();
    });

    async function moveItem(type, id, targetFolderId) {
        var action = type === 'folder' ? 'move_folder' : 'move_document';
        var fd = new FormData();
        fd.append('action', action);
        fd.append('venture_id', ventureId);
        fd.append('id', id);
        fd.append('target_folder_id', targetFolderId);
        try {
            var res = await fetch(PROCESS, { method: 'POST', body: fd });
            var data = await res.json();
            if (!data.success) { alert(data.message || 'Move failed.'); return false; }
            return true;
        } catch (err) { alert('Move failed.'); return false; }
    }

    // ===========================================================
    //  DRAG AND DROP: internal move (doc/folder cards onto
    //  folder cards and breadcrumb links)
    //  ============================================================
    //  Key fixes vs the previous version:
    //  1. Counter-based dragenter/dragleave (no child-flicker)
    //  2. stopPropagation() in drop handler (no page-zone conflict)
    //  3. data-item-type on both doc-cards and folder-cards
    // ===========================================================
    var dragPayload = null;

    document.querySelectorAll('[data-item-type]').forEach(function (el) {
        el.addEventListener('dragstart', function (e) {
            var type = el.dataset.itemType;
            var id = type === 'doc' ? el.dataset.docId : el.dataset.folderId;
            dragPayload = { type: type, id: id };
            window.__vpDragging = true;
            el.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/x-vault-drag', type + ':' + id); } catch (ex) {}
            try { e.dataTransfer.setData('text/plain', ''); } catch (ex) {}
            if (type === 'folder') e.stopPropagation();
        });
        el.addEventListener('dragend', function () {
            el.classList.remove('dragging');
            dragPayload = null;
            window.__vpDragging = false;
        });
    });

    document.querySelectorAll('.drop-target').forEach(function (el) {
        var enterCount = 0;

        el.addEventListener('dragenter', function (e) {
            e.preventDefault();
            enterCount++;
            el.classList.add('drag-over-target');
        });

        el.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = e.dataTransfer.types.includes('Files') ? 'copy' : 'move';
        });

        el.addEventListener('dragleave', function () {
            enterCount--;
            if (enterCount <= 0) { enterCount = 0; el.classList.remove('drag-over-target'); }
        });

        el.addEventListener('drop', async function (e) {
            e.preventDefault();
            e.stopPropagation();            // ← don't bubble to the page-level zone
            enterCount = 0;
            el.classList.remove('drag-over-target');

            var targetFolderId = el.dataset.folderId;

            /* External OS files/folders dropped onto a specific folder card */
            if (e.dataTransfer.types.includes('Files') && e.dataTransfer.items && e.dataTransfer.items.length) {
                await handleExternalDrop(e.dataTransfer.items, targetFolderId);
                return;
            }

            /* Internal drag-and-drop move */
            if (!dragPayload) return;
            if (dragPayload.type === 'folder' && String(dragPayload.id) === String(targetFolderId)) return;

            var ok = await moveItem(dragPayload.type, dragPayload.id, targetFolderId);
            dragPayload = null;
            if (ok) window.location.reload();
        });
    });

    // ===========================================================
    //  DRAG AND DROP: upload files / whole folders from the OS
    //  ============================================================
    //  A single page-level zone with counter-based enter/leave.
    // ===========================================================
    (function () {
        var zone = document.getElementById('vaultDropZone');
        if (!zone) return;
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

        zone.addEventListener('dragleave', function () {
            zoneEnterCount--;
            if (zoneEnterCount <= 0) { zoneEnterCount = 0; zone.classList.remove('drag-active'); }
        });

        zone.addEventListener('drop', async function (e) {
            if (!e.dataTransfer.types.includes('Files')) return;
            e.preventDefault();
            zoneEnterCount = 0;
            zone.classList.remove('drag-active');
            if (!e.dataTransfer.items || !e.dataTransfer.items.length) return;
            await handleExternalDrop(e.dataTransfer.items, currentFolderId);
        });
    })();

    async function handleExternalDrop(items, targetFolderId) {
        var entries = [];
        for (var i = 0; i < items.length; i++) {
            var entry = (typeof items[i].webkitGetAsEntry === 'function') ? items[i].webkitGetAsEntry() : null;
            if (entry) entries.push(entry);
        }

        var files = [];
        for (var j = 0; j < entries.length; j++) {
            await collectEntry(entries[j], '', files);
        }

        if (!files.length) return;
        await uploadFilesSequentially(files, targetFolderId);
    }

    function collectEntry(entry, pathPrefix, out) {
        return new Promise(function (resolve) {
            if (entry.isFile) {
                entry.file(function (file) {
                    out.push({ file: file, relPath: pathPrefix });
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
                                return collectEntry(child, dirPath, out);
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

    async function uploadFilesSequentially(fileList, targetFolderId) {
        var toast = showUploadProgress(fileList.length);
        var done = 0;
        for (var i = 0; i < fileList.length; i++) {
            await uploadOneFile(fileList[i].file, fileList[i].relPath, targetFolderId);
            done++;
            toast.update(done, fileList.length);
        }
        toast.finish();
        setTimeout(function () { window.location.reload(); }, 800);
    }

    function uploadOneFile(file, relPath, targetFolderId) {
        var fd = new FormData();
        fd.append('action', 'upload_doc_ajax');
        fd.append('venture_id', ventureId);
        fd.append('doc_file', file, file.name);
        fd.append('doc_name', file.name.replace(/\.[^.]+$/, ''));
        fd.append('category', 'other');
        fd.append('is_visible_to_investors', '0');
        fd.append('folder_id', targetFolderId || 0);
        fd.append('folder_path', relPath);

        return fetch(PROCESS, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
    }

    function showUploadProgress(total) {
        var div = document.createElement('div');
        div.className = 'upload-progress-toast';
        div.textContent = 'Uploading 0/' + total + ' files\u2026';
        document.body.appendChild(div);
        return {
            update: function (done, total) { div.textContent = 'Uploading ' + done + '/' + total + ' files\u2026'; },
            finish: function () { div.textContent = 'Upload complete.'; setTimeout(function () { div.remove(); }, 1200); }
        };
    }

    // "Upload Folder" toolbar button (webkitdirectory input)
    var upFolderInput = document.getElementById('upFolderInput');
    if (upFolderInput) {
        upFolderInput.addEventListener('change', function () {
            var input = this;
            var files = Array.from(input.files || []);
            if (!files.length) return;

            var list = files.map(function (f) {
                var parts = (f.webkitRelativePath || '').split('/');
                parts.pop();
                return { file: f, relPath: parts.join('/') };
            });

            uploadFilesSequentially(list, currentFolderId).finally(function () {
                input.value = '';
            });
        });
    }

});
</script>
