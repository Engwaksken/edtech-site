<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

function blog_col_exists(mysqli $conn, string $col): bool {
    $col = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM blogs LIKE '{$col}'");
    return $res && $res->num_rows > 0;
}

$hasBuilder = blog_col_exists($conn, 'content_builder');
$blogs = $conn->query("SELECT * FROM blogs ORDER BY sort_order ASC, created_at DESC");
$site_name = function_exists('get_setting') ? get_setting($conn, 'site_name', 'EdTech Fellowship') : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<title>Blogs - <?= h($site_name) ?> Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
<header class="admin-topbar">
  <div class="topbar-left">
    <div class="topbar-breadcrumb"><a href="index.php">Dashboard</a> > <strong>Blogs</strong></div>
  </div>
</header>

<div class="admin-content">
<?php show_flash('blogs'); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Blogs &amp; Articles</h1>
    <p class="page-subtitle">Create professional articles with PDF export support.</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="openModal()">
    <i class="fa fa-plus"></i> New Article
  </button>
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead>
<tr>
  <th>Image</th>
  <th>Title</th>
  <th>Category</th>
  <th>Status</th>
  <th>Published</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>
<?php $has = false; if ($blogs): while ($b = $blogs->fetch_assoc()): $has = true; ?>
<tr>
<td>
  <?php if (!empty($b['featured_image']) && file_exists('../' . $b['featured_image'])): ?>
    <img src="<?= SITE_URL . '/' . h($b['featured_image']) ?>" class="tbl-img" alt="">
  <?php else: ?>
    <div class="tbl-img legacy-style-0cfdd60c26">
      <i class="fa fa-newspaper"></i>
    </div>
  <?php endif; ?>
</td>
<td>
  <strong><?= h($b['title']) ?></strong><br>
  <small class="legacy-style-5872de20d5">
    <?= h(mb_strimwidth($b['excerpt'] ?? '', 0, 80, '...')) ?>
  </small>
</td>
<td><?= h($b['category'] ?: '') ?></td>
<td>
  <?php $badge = $b['status'] === 'published' ? 'badge-success' : ($b['status'] === 'draft' ? 'badge-warning' : 'badge-gray'); ?>
  <span class="badge <?= h($badge) ?>"><?= h(ucfirst($b['status'])) ?></span>
</td>
<td><?= $b['published_at'] ? date('M j, Y', strtotime($b['published_at'])) : '' ?></td>
<td>
  <div class="tbl-actions">
    <a href="../blog.php?slug=<?= h($b['slug']) ?>" target="_blank" class="btn btn-sm btn-teal" title="View"><i class="fa fa-eye"></i></a>
    <a href="../blog-pdf.php?slug=<?= h($b['slug']) ?>" target="_blank" class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border-color:#fca5a5" title="PDF View"><i class="fa fa-file-pdf"></i></a>
    <button type="button" class="btn btn-sm btn-secondary" title="Edit"
      onclick='editBlog(<?= json_encode($b, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
      <i class="fa fa-edit"></i>
    </button>
    <form method="POST" action="includes/process-blogs.php" onsubmit="return confirm('Delete this article?')" class="legacy-style-cccfa4560d">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <button class="btn btn-sm btn-danger" title="Delete"><i class="fa fa-trash"></i></button>
    </form>
  </div>
</td>
</tr>
<?php endwhile; endif; ?>
<?php if (!$has): ?>
<tr><td colspan="6">
  <div class="empty-state">
    <i class="fa fa-newspaper"></i>
    <h3>No articles yet</h3>
    <p>Create your first professional article.</p>
    <button type="button" class="btn btn-primary" onclick="openModal()">New Article</button>
  </div>
</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
</div>

<!-- ---------------------------------------------------
     ARTICLE BUILDER MODAL
--------------------------------------------------- -->
<div class="modal-overlay" id="blogModal">
<div class="modal modal-xl">
<div class="modal-header">
  <h2 class="modal-title" id="modalTitle">New Article</h2>
  <button type="button" class="modal-close" onclick="closeModal()">x</button>
</div>

<form id="blogForm" method="POST" action="includes/process-blogs.php" enctype="multipart/form-data" onsubmit="return prepareNativeSubmit(event)">
<input type="hidden" name="action" value="save">
<input type="hidden" name="save_status" id="f_save_status" value="">
<input type="hidden" name="id" id="f_id">
<input type="hidden" name="existing_image" id="f_existing_image">
<input type="hidden" name="content_builder" id="content_builder">
<textarea name="content" id="f_content" class="legacy-style-6b99de8b69"></textarea>

<div class="modal-body">
<div class="form-grid form-grid-2">

  <!-- TITLE -->
  <div class="form-group full">
    <label>Title <span class="req">*</span></label>
    <input type="text" name="title" id="f_title" class="form-control" required placeholder="Enter article title...">
  </div>

  <!-- EXCERPT -->
  <div class="form-group full">
    <label>Excerpt / Summary</label>
    <textarea name="excerpt" id="f_excerpt" class="form-control" rows="2" placeholder="Brief description shown in listings..."></textarea>
  </div>

  <!-- AUTHOR + CATEGORY -->
  <div class="form-group">
    <label>Author</label>
    <input type="text" name="author" id="f_author" class="form-control" value="<?= h($ADMIN['full_name'] ?? '') ?>">
  </div>
  <div class="form-group">
    <label>Category</label>
    <input type="text" name="category" id="f_category" class="form-control" placeholder="e.g. EdTech, Research...">
  </div>

  <!-- STATUS + PUBLISHED DATE -->
  <div class="form-group">
    <label>Status</label>
    <select name="status" id="f_status" class="form-control">
      <option value="draft">Draft</option>
      <option value="published">Published</option>
      <option value="archived">Archived</option>
    </select>
    <small class="legacy-style-5872de20d5">Set automatically by the Save buttons below, or choose "Archived" manually.</small>
  </div>
  <div class="form-group">
    <label>Published Date</label>
    <input type="datetime-local" name="published_at" id="f_published_at" class="form-control">
  </div>

  <!-- SORT + IMAGE -->
  <div class="form-group">
    <label>Sort Order</label>
    <input type="number" name="sort_order" id="f_sort_order" class="form-control" value="0">
  </div>
  <div class="form-group">
    <label>Featured Image</label>
    <input type="file" id="f_featured_image" name="featured_image" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" onchange="previewImg(this,'prev_image')">
    <img id="prev_image" class="img-preview legacy-style-66253c00ea" alt="">
  </div>

  <!-- -- ARTICLE BUILDER --------------------------- -->
  <div class="form-group full">
    <label class="legacy-style-761d3addb2">Article Content Builder <span class="req">*</span></label>

    <div class="builder-wrap">

      <!-- TOOL PANEL -->
      <div class="builder-tools">
        <div class="tool-group">
          <div class="builder-tools-label">Typography</div>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('heading')">
            <i class="fa fa-heading"></i> Heading
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('paragraph')">
            <i class="fa fa-align-left"></i> Paragraph
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('list')">
            <i class="fa fa-list-ul"></i> List
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('quote')">
            <i class="fa fa-quote-left"></i> Quote
          </button>
        </div>
        <div class="tool-group">
          <div class="builder-tools-label">Media &amp; Data</div>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('image')">
            <i class="fa fa-image"></i> Image
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('table')">
            <i class="fa fa-table"></i> Table
          </button>
        </div>
        <div class="tool-group">
          <div class="builder-tools-label">Layout</div>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('callout')">
            <i class="fa fa-info-circle"></i> Callout
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('columns')">
            <i class="fa fa-columns"></i> Two Columns
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('divider')">
            <i class="fa fa-minus"></i> Divider
          </button>
          <button type="button" class="btn btn-secondary builder-tool" onclick="addSection('button')">
            <i class="fa fa-link"></i> Button Link
          </button>
        </div>
      </div>

      <!-- CANVAS -->
      <div class="builder-canvas" id="builderCanvas">
        <div class="builder-canvas-empty" id="canvasEmpty">
          <i class="fa fa-layer-group"></i>
          <strong>Start building your article</strong>
          <span>Click any block type on the left to add it here</span>
        </div>
      </div>
    </div>
  </div>

</div>
</div><!-- /modal-body -->

<div class="modal-footer">
  <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
  <button type="button" class="btn btn-outline-primary" onclick="submitArticle('')"><i class="fa fa-save"></i> Save</button>
  <button type="button" class="btn btn-secondary" onclick="submitArticle('draft')"><i class="fa fa-file-alt"></i> Save as Draft</button>
  <button type="button" class="btn btn-primary" onclick="submitArticle('published')"><i class="fa fa-check-circle"></i> Publish</button>
</div>
</form>
</div>
</div>

<!-- ---------------------------------------------------
     JAVASCRIPT
--------------------------------------------------- -->
<script>
const modal  = document.getElementById('blogModal');
const canvas = document.getElementById('builderCanvas');
const canvasEmpty = document.getElementById('canvasEmpty');
const BLOG_SITE_URL = <?= json_encode(rtrim(SITE_URL, '/'), JSON_UNESCAPED_SLASHES) ?>;

function blogImageUrl(path){
  path = String(path || '').trim();

  if (!path) return '';

  if (/^https?:\/\//i.test(path) || path.startsWith('data:') || path.startsWith('blob:')){
    return path;
  }

  return BLOG_SITE_URL + '/' + path.replace(/^\/+/, '');
}

/* -- MODAL --------------------------------------- */
function openModal(){
  resetBlogForm();
  modal.classList.add('open');
}
function closeModal(){
  modal.classList.remove('open');
}
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

/* -- UTILITY -------------------------------------- */
function uid(){
  return 's_' + Date.now() + '_' + Math.floor(Math.random() * 99999);
}
function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]);
}
function escapeAttr(str){
  return escapeHtml(str).replace(/`/g, '&#096;');
}
function updateEmptyState(){
  const hasSections = canvas.querySelectorAll('.builder-section').length > 0;
  canvasEmpty.style.display = hasSections ? 'none' : 'flex';
}

/* -- SECTION TYPES -------------------------------- */
const SECTION_META = {
  heading  : { label:'Heading',     icon:'fa-heading',      badge:'badge-heading'   },
  paragraph: { label:'Paragraph',   icon:'fa-align-left',   badge:'badge-paragraph' },
  image    : { label:'Image',       icon:'fa-image',        badge:'badge-image'     },
  quote    : { label:'Quote',       icon:'fa-quote-left',   badge:'badge-quote'     },
  table    : { label:'Table',       icon:'fa-table',        badge:'badge-table'     },
  list     : { label:'List',        icon:'fa-list-ul',      badge:'badge-list'      },
  callout  : { label:'Callout',     icon:'fa-info-circle',  badge:'badge-callout'   },
  divider  : { label:'Divider',     icon:'fa-minus',        badge:'badge-divider'   },
  columns  : { label:'Two Columns', icon:'fa-columns',      badge:'badge-columns'   },
  button   : { label:'Button Link', icon:'fa-link',         badge:'badge-button'    },
};


function styleControlsHtml(data){
  data = data || {};
  const fs  = data.fontSize   || 'normal';
  const fw  = data.fontWeight || 'normal';
  const fst = data.fontStyle  || 'normal';
  return `
    <div class="text-style-row">
      <div class="form-group ts-item">
        <label>Font Size</label>
        <select class="form-control b-fontsize">
          <option value="small"  ${fs==='small' ?'selected':''}>Small</option>
          <option value="normal" ${fs==='normal'?'selected':''}>Normal</option>
          <option value="large"  ${fs==='large' ?'selected':''}>Large</option>
          <option value="xlarge" ${fs==='xlarge'?'selected':''}>X-Large</option>
          <option value="huge"   ${fs==='huge'  ?'selected':''}>Huge</option>
        </select>
      </div>
      <div class="form-group ts-item">
        <label>Font Weight</label>
        <select class="form-control b-fontweight">
          <option value="light"    ${fw==='light'   ?'selected':''}>Light</option>
          <option value="normal"   ${fw==='normal'  ?'selected':''}>Normal</option>
          <option value="medium"   ${fw==='medium'  ?'selected':''}>Medium</option>
          <option value="semibold" ${fw==='semibold'?'selected':''}>Semibold</option>
          <option value="bold"     ${fw==='bold'    ?'selected':''}>Bold</option>
        </select>
      </div>
      <div class="form-group ts-item">
        <label>Font Style</label>
        <select class="form-control b-fontstyle">
          <option value="normal" ${fst==='normal'?'selected':''}>Normal</option>
          <option value="italic" ${fst==='italic'?'selected':''}>Italic</option>
        </select>
      </div>
    </div>`;
}

/* -- ADD SECTION ---------------------------------- */
function addSection(type, data = {}){
  const id   = data.id || uid();
  const meta = SECTION_META[type] || {label: type, icon:'fa-puzzle-piece', badge:''};
  const div  = document.createElement('div');
  div.className   = 'builder-section';
  div.draggable   = true;
  div.dataset.type = type;
  div.dataset.id   = id;

  let body = '';

  /* HEADING */
  if (type === 'heading'){
    body = `
      <div class="form-grid form-grid-2 legacy-style-f3d7ce7732">
        <div class="form-group">
          <label>Heading Text</label>
          <input type="text" class="form-control b-text" value="${escapeAttr(data.text||'')}" placeholder="Enter heading...">
        </div>
        <div class="form-group">
          <label>Size</label>
          <select class="form-control b-level">
            <option value="h1" ${data.level==='h1'?'selected':''}>H1 - Title</option>
            <option value="h2" ${(!data.level||data.level==='h2')?'selected':''}>H2 - Section</option>
            <option value="h3" ${data.level==='h3'?'selected':''}>H3 - Sub-section</option>
            <option value="h4" ${data.level==='h4'?'selected':''}>H4 - Minor</option>
          </select>
        </div>
      </div>
      ${styleControlsHtml(data)}`;
  }

  /* PARAGRAPH */
  if (type === 'paragraph'){
    body = `
      <div class="form-group">
        <label>Content</label>
        <textarea class="form-control b-text" rows="4" placeholder="Write content here... (line breaks create new paragraphs)">${escapeHtml(data.text||'')}</textarea>
      </div>
      ${styleControlsHtml(data)}`;
  }

  /* IMAGE */
  if (type === 'image'){
    const hasSrc = !!(data.src||'');
    body = `
      <div class="form-grid form-grid-2 legacy-style-f3d7ce7732">
        <div class="form-group">
          <label>Upload Image</label>
          <input type="file" class="form-control b-file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" onchange="uploadBuilderImage(this)">
          <input type="hidden" class="b-existing-image" value="${escapeAttr(data.src||'')}">
          <img
            class="builder-preview-img legacy-style-d921d1ce9f"
            src="${hasSrc ? escapeAttr(blogImageUrl(data.src)) : ''}"
            alt="${escapeAttr(data.caption || 'Article image')}"
            style="${hasSrc ? 'display:block;width:100%;max-width:100%;height:auto;margin-top:10px;border-radius:8px;object-fit:contain;' : 'display:none;width:100%;max-width:100%;height:auto;margin-top:10px;border-radius:8px;object-fit:contain;'}"
            onerror="this.style.display='none'; const s=this.parentElement.querySelector('.b-upload-status'); if(s){s.textContent='Image saved, but preview could not be loaded';s.style.color='#b91c1c';}"
          >
          <small class="b-upload-status" style="display:block;margin-top:6px;color:${hasSrc ? '#15803d' : '#64748b'};">${hasSrc ? 'Image saved' : ''}</small>
        </div>
        <div class="form-group">
          <label>Or Image URL</label>
          <input type="text" class="form-control b-url" value="${escapeAttr(data.url||'')}" placeholder="https://...">
          <label class="legacy-style-8a77e5a311">Caption</label>
          <input type="text" class="form-control b-caption" value="${escapeAttr(data.caption||'')}" placeholder="Optional caption">
          <label class="legacy-style-8a77e5a311">Alignment</label>
          <select class="form-control b-align">
            <option value="center" ${(!data.align||data.align==='center')?'selected':''}>Center</option>
            <option value="left"   ${data.align==='left'  ?'selected':''}>Left</option>
            <option value="right"  ${data.align==='right' ?'selected':''}>Right</option>
            <option value="full"   ${data.align==='full'  ?'selected':''}>Full Width</option>
          </select>
        </div>
      </div>`;
  }

  /* QUOTE */
  if (type === 'quote'){
    body = `
      <div class="form-group">
        <label>Quote Text</label>
        <textarea class="form-control b-text" rows="3" placeholder="Enter quote...">${escapeHtml(data.text||'')}</textarea>
      </div>
      <div class="form-grid form-grid-2 legacy-style-f3d7ce7732">
        <div class="form-group">
          <label>Attribution (Author)</label>
          <input type="text" class="form-control b-author" value="${escapeAttr(data.author||'')}" placeholder="Author name">
        </div>
        <div class="form-group">
          <label>Source / Title</label>
          <input type="text" class="form-control b-source" value="${escapeAttr(data.source||'')}" placeholder="Book, publication...">
        </div>
      </div>
      ${styleControlsHtml(data)}`;
  }

  /* TABLE */
  if (type === 'table'){
    const headers = data.headers || ['Column 1','Column 2','Column 3'];
    const rows    = data.rows    || [['','',''],['','','']];
    const hCells  = headers.map(h => `<th><input type="text" class="tbl-cell-input" value="${escapeAttr(h)}" placeholder="Header"></th>`).join('');
    const bRows   = rows.map(r => `<tr>${r.map(c => `<td><input type="text" class="tbl-cell-input" value="${escapeAttr(c)}" placeholder="Cell"></td>`).join('')}</tr>`).join('');
    body = `
      <div class="form-group legacy-style-fdf33f2304">
        <label>Table Title / Caption</label>
        <input type="text" class="form-control b-caption" value="${escapeAttr(data.caption||'')}" placeholder="Optional table caption">
      </div>
      <div class="table-editor-controls">
        <button type="button" class="btn btn-sm btn-secondary" onclick="tableAddRow(this)"><i class="fa fa-plus"></i> Row</button>
        <button type="button" class="btn btn-sm btn-secondary" onclick="tableAddCol(this)"><i class="fa fa-plus"></i> Column</button>
        <button type="button" class="btn btn-sm btn-danger"    onclick="tableDelRow(this)"><i class="fa fa-minus"></i> Row</button>
        <button type="button" class="btn btn-sm btn-danger"    onclick="tableDelCol(this)"><i class="fa fa-minus"></i> Column</button>
      </div>
      <div class="table-scroll">
        <table class="b-table-editor">
          <thead><tr>${hCells}</tr></thead>
          <tbody>${bRows}</tbody>
        </table>
      </div>`;
  }

  /* LIST */
  if (type === 'list'){
    const items = data.items || ['',''];
    const itemRows = items.map((item, i) => `
      <div class="list-item-row">
        <span class="list-drag-handle"><i class="fa fa-grip-lines"></i></span>
        <input type="text" class="form-control b-list-item" value="${escapeAttr(item)}" placeholder="Item ${i+1}">
        <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.list-item-row').remove()"><i class="fa fa-times"></i></button>
      </div>`).join('');
    body = `
      <div class="form-group legacy-style-fdf33f2304">
        <label>List Style</label>
        <select class="form-control b-style legacy-style-a828909e66">
          <option value="unordered" ${(!data.style||data.style==='unordered')?'selected':''}>Bullet List</option>
          <option value="ordered"   ${data.style==='ordered'?'selected':''}>Numbered List</option>
        </select>
      </div>
      <div class="list-items-wrap" id="listItems_${id}">${itemRows}</div>
      <button type="button" class="btn btn-sm btn-secondary legacy-style-8a77e5a311" onclick="addListItem(this)">
        <i class="fa fa-plus"></i> Add Item
      </button>
      ${styleControlsHtml(data)}`;
  }

  /* CALLOUT */
  if (type === 'callout'){
    const v = data.variant || 'info';
    body = `
      <div class="form-grid form-grid-2 legacy-style-c08afbed90">
        <div class="form-group">
          <label>Callout Type</label>
          <select class="form-control b-variant" onchange="updateCalloutPreview(this)">
            <option value="info"    ${v==='info'   ?'selected':''}>Info</option>
            <option value="tip"     ${v==='tip'    ?'selected':''}>Tip</option>
            <option value="warning" ${v==='warning'?'selected':''}>Warning</option>
            <option value="danger"  ${v==='danger' ?'selected':''}>Important</option>
          </select>
        </div>
        <div class="form-group">
          <label>Title (optional)</label>
          <input type="text" class="form-control b-title" value="${escapeAttr(data.title||'')}" placeholder="Callout heading">
        </div>
      </div>
      <div class="form-group">
        <label>Content</label>
        <textarea class="form-control b-text" rows="3" placeholder="Callout content...">${escapeHtml(data.text||'')}</textarea>
      </div>
      ${styleControlsHtml(data)}`;
  }

  /* DIVIDER */
  if (type === 'divider'){
    body = `
      <div class="form-group legacy-style-27af8985f8">
        <label>Line Style</label>
        <select class="form-control b-style">
          <option value="solid"       ${(!data.style||data.style==='solid')      ?'selected':''}>Solid</option>
          <option value="dashed"      ${data.style==='dashed'     ?'selected':''}>Dashed</option>
          <option value="decorative"  ${data.style==='decorative' ?'selected':''}>Decorative</option>
        </select>
      </div>`;
  }

  /* TWO COLUMNS */
  if (type === 'columns'){
    body = `
      <div class="columns-editor">
        <div class="form-group">
          <label>Left Column</label>
          <textarea class="form-control b-left" rows="5" placeholder="Left column text...">${escapeHtml(data.left||'')}</textarea>
        </div>
        <div class="form-group">
          <label>Right Column</label>
          <textarea class="form-control b-right" rows="5" placeholder="Right column text...">${escapeHtml(data.right||'')}</textarea>
        </div>
      </div>
      ${styleControlsHtml(data)}`;
  }

  /* BUTTON LINK */
  if (type === 'button'){
    body = `
      <div class="form-grid form-grid-2 legacy-style-f3d7ce7732">
        <div class="form-group">
          <label>Button Text</label>
          <input type="text" class="form-control b-text" value="${escapeAttr(data.text||'Read More')}" placeholder="Button label">
        </div>
        <div class="form-group">
          <label>Button URL</label>
          <input type="text" class="form-control b-url" value="${escapeAttr(data.url||'')}" placeholder="https://...">
        </div>
        <div class="form-group">
          <label>Style</label>
          <select class="form-control b-style">
            <option value="primary"   ${(!data.style||data.style==='primary')  ?'selected':''}>Primary</option>
            <option value="secondary" ${data.style==='secondary'?'selected':''}>Secondary</option>
            <option value="outline"   ${data.style==='outline'  ?'selected':''}>Outline</option>
          </select>
        </div>
      </div>
      ${styleControlsHtml(data)}`;
  }

  div.innerHTML = `
    <div class="builder-head">
      <strong>
        <i class="fa fa-grip-vertical legacy-style-43f054aafb"></i>
        <span class="section-type-badge ${meta.badge}"><i class="fa ${meta.icon}"></i> ${meta.label}</span>
      </strong>
      <div class="builder-actions">
        <button type="button" class="btn btn-sm btn-secondary" onclick="moveSection(this,-1)" title="Move up"><i class="fa fa-arrow-up"></i></button>
        <button type="button" class="btn btn-sm btn-secondary" onclick="moveSection(this,1)"  title="Move down"><i class="fa fa-arrow-down"></i></button>
        <button type="button" class="btn btn-sm btn-danger"    onclick="this.closest('.builder-section').remove();updateEmptyState()" title="Delete"><i class="fa fa-trash"></i></button>
      </div>
    </div>
    ${body}`;

  canvas.appendChild(div);
  updateEmptyState();
  initDrag();
}

/* -- MOVE SECTION --------------------------------- */
function moveSection(btn, dir){
  const sec = btn.closest('.builder-section');
  if (dir < 0 && sec.previousElementSibling) canvas.insertBefore(sec, sec.previousElementSibling);
  if (dir > 0 && sec.nextElementSibling)      canvas.insertBefore(sec.nextElementSibling, sec);
}

/* -- TABLE HELPERS -------------------------------- */
function tableAddRow(btn){
  const tbody = btn.closest('.builder-section').querySelector('tbody');
  const colCount = tbody.closest('table').querySelectorAll('thead th').length;
  const tr = document.createElement('tr');
  tr.innerHTML = Array(colCount).fill('<td><input type="text" class="tbl-cell-input" placeholder="Cell"></td>').join('');
  tbody.appendChild(tr);
}
function tableAddCol(btn){
  const tbl = btn.closest('.builder-section').querySelector('.b-table-editor');
  tbl.querySelectorAll('thead tr').forEach(row => {
    const th = document.createElement('th');
    th.innerHTML = '<input type="text" class="tbl-cell-input" placeholder="Header">';
    row.appendChild(th);
  });
  tbl.querySelectorAll('tbody tr').forEach(row => {
    const td = document.createElement('td');
    td.innerHTML = '<input type="text" class="tbl-cell-input" placeholder="Cell">';
    row.appendChild(td);
  });
}
function tableDelRow(btn){
  const tbody = btn.closest('.builder-section').querySelector('tbody');
  if (tbody.rows.length > 1) tbody.deleteRow(tbody.rows.length - 1);
}
function tableDelCol(btn){
  const tbl = btn.closest('.builder-section').querySelector('.b-table-editor');
  const colCount = tbl.querySelectorAll('thead th').length;
  if (colCount <= 1) return;
  tbl.querySelectorAll('thead tr, tbody tr').forEach(row => {
    if (row.cells.length > 1) row.deleteCell(row.cells.length - 1);
  });
}

/* -- LIST HELPERS --------------------------------- */
function addListItem(btn){
  const wrap = btn.previousElementSibling;
  const count = wrap.querySelectorAll('.list-item-row').length + 1;
  const row = document.createElement('div');
  row.className = 'list-item-row';
  row.innerHTML = `
    <span class="list-drag-handle"><i class="fa fa-grip-lines"></i></span>
    <input type="text" class="form-control b-list-item" placeholder="Item ${count}">
    <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.list-item-row').remove()"><i class="fa fa-times"></i></button>`;
  wrap.appendChild(row);
}

/* -- BUILDER IMAGE UPLOAD ------------------------- */
async function uploadBuilderImage(input){
  const section = input.closest('.builder-section');
  const img = section?.querySelector('.builder-preview-img');
  const hidden = section?.querySelector('.b-existing-image');
  const status = section?.querySelector('.b-upload-status');

  if (!section || !img || !hidden || !status) return;
  if (!input.files || !input.files[0]) return;

  const file = input.files[0];
  const allowed = ['image/jpeg','image/png','image/webp','image/gif'];
  const maxSize = 10 * 1024 * 1024;

  if (!allowed.includes(file.type)){
    alert('Please select a JPG, PNG, WEBP or GIF image.');
    input.value = '';
    return;
  }

  if (file.size > maxSize){
    alert('Image must not exceed 10MB.');
    input.value = '';
    return;
  }

  // Show an instant local preview while the real upload is happening.
  const objectUrl = URL.createObjectURL(file);
  img.src = objectUrl;
  img.style.display = 'block';
  img.style.width = '100%';
  img.style.maxWidth = '100%';
  img.style.height = 'auto';
  img.style.marginTop = '10px';
  img.style.objectFit = 'contain';

  section.dataset.uploadingImage = '1';
  status.textContent = 'Uploading image...';
  status.style.color = '#b45309';

  const formData = new FormData();
  formData.append('action', 'upload_builder_image');
  formData.append('builder_image', file);
  formData.append('section_id', section.dataset.id || '');

  try {
    const response = await fetch('includes/process-blogs.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const raw = await response.text();

    let result;
    try {
      result = JSON.parse(raw);
    } catch (e) {
      throw new Error(
        raw && raw.trim()
          ? 'Server returned an invalid upload response: ' + raw.substring(0, 180)
          : 'Server returned an empty upload response.'
      );
    }

    if (!response.ok || !result.success){
      throw new Error(result.message || 'Image upload failed.');
    }

    // THIS is the important part:
    // persist the server-side file path inside the builder block.
    hidden.value = result.path || '';

    if (!hidden.value){
      throw new Error('The server uploaded the image but did not return its saved path.');
    }

    // Use the permanent server image instead of only the temporary browser preview.
    img.src = blogImageUrl(hidden.value);
    img.style.display = 'block';
    img.style.width = '100%';
    img.style.maxWidth = '100%';
    img.style.height = 'auto';
    img.style.marginTop = '10px';
    img.style.objectFit = 'contain';

    status.textContent = 'Image uploaded and saved';
    status.title = hidden.value;
    status.style.color = '#15803d';

    // The file no longer needs to be part of the final article POST.
    // The hidden .b-existing-image path is what gets stored in content_builder.
    input.value = '';

  } catch (error) {
    hidden.value = '';
    status.textContent = error.message || 'Image upload failed.';
    status.style.color = '#b91c1c';
    alert(error.message || 'Image upload failed.');
  } finally {
    delete section.dataset.uploadingImage;
    URL.revokeObjectURL(objectUrl);
  }
}

/* -- COLLECT SECTIONS ----------------------------- */
function collectStyle(sec){
  return {
    fontSize:   sec.querySelector('.b-fontsize')?.value   || 'normal',
    fontWeight: sec.querySelector('.b-fontweight')?.value || 'normal',
    fontStyle:  sec.querySelector('.b-fontstyle')?.value  || 'normal',
  };
}

function collectSections(){
  const sections = [];
  canvas.querySelectorAll('.builder-section').forEach(sec => {
    const type = sec.dataset.type;
    const id   = sec.dataset.id;
    const item = {id, type};

    if (type === 'heading'){
      item.text  = sec.querySelector('.b-text')?.value  || '';
      item.level = sec.querySelector('.b-level')?.value || 'h2';
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'paragraph'){
      item.text = sec.querySelector('.b-text')?.value || '';
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'image'){
      item.src     = sec.querySelector('.b-existing-image')?.value || '';
      item.url     = sec.querySelector('.b-url')?.value     || '';
      item.caption = sec.querySelector('.b-caption')?.value || '';
      item.align   = sec.querySelector('.b-align')?.value   || 'center';
    }
    if (type === 'quote'){
      item.text   = sec.querySelector('.b-text')?.value   || '';
      item.author = sec.querySelector('.b-author')?.value || '';
      item.source = sec.querySelector('.b-source')?.value || '';
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'table'){
      const tbl    = sec.querySelector('.b-table-editor');
      item.caption = sec.querySelector('.b-caption')?.value || '';
      item.headers = [...tbl.querySelectorAll('thead th input')].map(i => i.value);
      item.rows    = [...tbl.querySelectorAll('tbody tr')].map(row =>
                       [...row.querySelectorAll('td input')].map(i => i.value));
    }
    if (type === 'list'){
      item.style = sec.querySelector('.b-style')?.value || 'unordered';
      item.items = [...sec.querySelectorAll('.b-list-item')].map(i => i.value).filter(v => v.trim());
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'callout'){
      item.variant = sec.querySelector('.b-variant')?.value || 'info';
      item.title   = sec.querySelector('.b-title')?.value   || '';
      item.text    = sec.querySelector('.b-text')?.value    || '';
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'divider'){
      item.style = sec.querySelector('.b-style')?.value || 'solid';
    }
    if (type === 'columns'){
      item.left  = sec.querySelector('.b-left')?.value  || '';
      item.right = sec.querySelector('.b-right')?.value || '';
      Object.assign(item, collectStyle(sec));
    }
    if (type === 'button'){
      item.text  = sec.querySelector('.b-text')?.value  || 'Read More';
      item.url   = sec.querySelector('.b-url')?.value   || '';
      item.style = sec.querySelector('.b-style')?.value || 'primary';
      Object.assign(item, collectStyle(sec));
    }

    sections.push(item);
  });
  return sections;
}

function prepareBuilderSubmit(){
  const uploadingSection = canvas.querySelector('.builder-section[data-uploading-image="1"]');

  if (uploadingSection){
    alert('Please wait for the image upload to finish before saving the article.');
    return false;
  }

  const sections = collectSections();

  const hasContent = sections.some(s =>
    (s.text   && s.text.trim())   ||
    (s.src    && s.src.trim())    ||
    (s.url    && s.url.trim())    ||
    (s.left   && s.left.trim())   ||
    (s.right  && s.right.trim())  ||
    (s.items  && s.items.length)  ||
    (s.rows   && s.rows.length)   ||
    s.type === 'divider'
  );

  if (!hasContent){
    alert('Please add at least one article section with content or upload an image.');
    return false;
  }

  document.getElementById('content_builder').value = JSON.stringify(sections);
  document.getElementById('f_content').value        = JSON.stringify(sections);
  return true;
}


let blogSubmitting = false;

function submitArticle(forceStatus){
  const form = document.getElementById('blogForm');
  const statusField = document.getElementById('f_status');

  document.getElementById('f_save_status').value = forceStatus || '';

  if (forceStatus){
    statusField.value = forceStatus;
  }

  if (statusField.value === 'published'){
    const pubField = document.getElementById('f_published_at');
    if (!pubField.value){
      const now = new Date();
      const pad = n => String(n).padStart(2, '0');
      pubField.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }
  }

  if (!document.getElementById('f_title').value.trim()){
    alert('Title is required.');
    return;
  }

  // IMPORTANT:
  // requestSubmit() performs a real browser form submission and preserves
  // multipart/form-data file inputs, including dynamically created image blocks.
  form.requestSubmit();
}

function prepareNativeSubmit(event){
  if (blogSubmitting){
    return false;
  }

  if (!prepareBuilderSubmit()){
    event.preventDefault();
    return false;
  }

  const imageFiles = [...document.querySelectorAll('#builderCanvas .builder-section[data-type="image"] .b-file')]
    .filter(input => input.files && input.files.length > 0);

  // Keep the exact dynamic field name synchronized with the section ID.
  imageFiles.forEach(input => {
    const section = input.closest('.builder-section');
    if (!section) return;

    const sectionId = section.dataset.id || '';
    input.name = 'builder_images[' + sectionId + ']';
  });

  blogSubmitting = true;

  // Prevent accidental double-click saves without disabling file inputs.
  document.querySelectorAll('#blogModal .modal-footer button').forEach(btn => {
    if (!btn.matches('[onclick*="closeModal"]')){
      btn.disabled = true;
    }
  });

  return true;
}

/* -- RESET ---------------------------------------- */
function resetBlogForm(){
  blogSubmitting = false;
  document.querySelectorAll('#blogModal .modal-footer button').forEach(btn => btn.disabled = false);
  document.getElementById('blogForm').reset();
  document.getElementById('modalTitle').textContent = 'New Article';
  document.getElementById('f_id').value             = '';
  document.getElementById('f_existing_image').value = '';
  document.getElementById('f_status').value         = 'draft';
  document.getElementById('f_sort_order').value     = 0;
  document.getElementById('content_builder').value  = '';
  canvas.innerHTML = '';
  canvas.appendChild(canvasEmpty);
  updateEmptyState();
  const img = document.getElementById('prev_image');
  img.src = ''; img.style.display = 'none';
}

/* -- EDIT ----------------------------------------- */
function editBlog(d){
  resetBlogForm();
  document.getElementById('modalTitle').textContent   = 'Edit Article';
  document.getElementById('f_id').value               = d.id        || '';
  document.getElementById('f_title').value            = d.title     || '';
  document.getElementById('f_excerpt').value          = d.excerpt   || '';
  document.getElementById('f_author').value           = d.author    || '';
  document.getElementById('f_category').value         = d.category  || '';
  document.getElementById('f_status').value           = d.status    || 'draft';
  document.getElementById('f_sort_order').value       = d.sort_order|| 0;
  document.getElementById('f_published_at').value     = d.published_at ? String(d.published_at).replace(' ','T').substring(0,16) : '';
  document.getElementById('f_existing_image').value   = d.featured_image || '';

  if (d.featured_image){
    const img = document.getElementById('prev_image');
    img.src = '<?= SITE_URL ?>/' + d.featured_image;
    img.style.display = 'block';
  }

  canvas.innerHTML = '';
  let sections = [];
  try { sections = d.content_builder ? JSON.parse(d.content_builder) : []; }
  catch(e){ sections = []; }
  if (!sections.length && d.content) sections = [{type:'paragraph', text:d.content}];
  sections.forEach(s => addSection(s.type || 'paragraph', s));
  updateEmptyState();
  modal.classList.add('open');
}

/* -- FEATURED IMAGE PREVIEW ----------------------- */
function previewImg(input, previewId){
  const prev = document.getElementById(previewId);
  if (input.files && input.files[0]){
    const reader = new FileReader();
    reader.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  }
}

/* -- DRAG & DROP ---------------------------------- */
function initDrag(){
  let dragged = null;
  canvas.querySelectorAll('.builder-section').forEach(sec => {
    sec.ondragstart = () => { dragged = sec; sec.classList.add('dragging'); };
    sec.ondragend   = () => { sec.classList.remove('dragging'); dragged = null; };
    sec.ondragover  = e => {
      e.preventDefault();
      const after = getDragAfterElement(canvas, e.clientY);
      if (after == null) canvas.appendChild(dragged);
      else canvas.insertBefore(dragged, after);
    };
  });
}
function getDragAfterElement(container, y){
  const els = [...container.querySelectorAll('.builder-section:not(.dragging)')];
  return els.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) return {offset, element:child};
    return closest;
  }, {offset: Number.NEGATIVE_INFINITY}).element;
}
</script>

</body>
</html>
