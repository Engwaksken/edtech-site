<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $stmt = $conn->prepare("SELECT * FROM faqs WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $edit = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

$faqQuery = $conn->query("
    SELECT *
    FROM faqs
    ORDER BY sort_order ASC, id ASC
");

$site_name = function_exists('get_setting')
    ? get_setting($conn, 'site_name', 'EdTech Fellowship')
    : 'EdTech Fellowship';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<title>FAQs - <?= h($site_name) ?> Admin</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>

<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">

<header class="admin-topbar">
    <div class="topbar-left">
        <div class="topbar-breadcrumb">
            <a href="index.php">Dashboard</a> › <strong>FAQs</strong>
        </div>
    </div>

    <div class="topbar-right">
        <div class="admin-avatar">
            <div class="avatar-circle">
                <?= h(strtoupper(substr($ADMIN['full_name'] ?? 'A', 0, 1))) ?>
            </div>
        </div>
    </div>
</header>

<div class="admin-content">

<?php show_flash('faqs'); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">FAQs</h1>
        <p class="page-subtitle">
            Manage frequently asked questions and answers.
        </p>
    </div>

    <button type="button"
            class="btn btn-primary"
            onclick="openModal()">
        <i class="fa fa-plus"></i> Add FAQ
    </button>
</div>

<div class="card">
<div class="table-wrap">

<table>
<thead>
<tr>
    <th>#</th>
    <th>Question</th>
    <th>Answer</th>
    <th>Status</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php $has = false; ?>

<?php if ($faqQuery): ?>
<?php while ($faq = $faqQuery->fetch_assoc()): $has = true; ?>

<tr>
    <td><?= (int)$faq['sort_order'] ?></td>

    <td>
        <strong>
            <?= h(function_exists('truncate')
                ? truncate($faq['question'], 80)
                : mb_strimwidth($faq['question'], 0, 80, '…')) ?>
        </strong>
    </td>

    <td>
        <?= h(function_exists('truncate')
            ? truncate($faq['answer'], 100)
            : mb_strimwidth($faq['answer'], 0, 100, '…')) ?>
    </td>

    <td>
        <span class="badge <?= (int)$faq['status'] === 1 ? 'badge-success' : 'badge-gray' ?>">
            <?= (int)$faq['status'] === 1 ? 'Active' : 'Hidden' ?>
        </span>
    </td>

    <td>
        <div class="tbl-actions">

            <button type="button"
                    onclick="editFaq(<?= h(json_encode($faq, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)"
                    class="btn btn-sm btn-secondary">
                <i class="fa fa-edit"></i>
            </button>

            <form method="POST"
                  action="includes/process-faqs.php"
                 
                  onsubmit="return confirm('Delete this FAQ?')" class="legacy-style-cccfa4560d">

                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$faq['id'] ?>">

                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="fa fa-trash"></i>
                </button>
            </form>

        </div>
    </td>
</tr>

<?php endwhile; ?>
<?php endif; ?>

<?php if (!$has): ?>
<tr>
<td colspan="5">
    <div class="empty-state">
        <i class="fa fa-question-circle"></i>

        <h3>No FAQs yet</h3>

        <button type="button"
                class="btn btn-primary"
                onclick="openModal()">
            <i class="fa fa-plus"></i> Add FAQ
        </button>
    </div>
</td>
</tr>
<?php endif; ?>

</tbody>
</table>

</div>
</div>

</div>
</div>

<div class="modal-overlay" id="faqModal">
<div class="modal">

<div class="modal-header">
    <h2 class="modal-title" id="modalTitle">Add FAQ</h2>

    <button type="button"
            class="modal-close"
            onclick="closeModal()">×</button>
</div>

<form method="POST" action="includes/process-faqs.php">

<input type="hidden" name="action" value="save">
<input type="hidden" name="id" id="f_id">

<div class="modal-body">

<div class="form-grid">

<div class="form-group">
    <label>Question <span class="req">*</span></label>

    <textarea name="question"
              id="f_question"
              class="form-control"
              rows="3"
              required></textarea>
</div>

<div class="form-group">
    <label>Answer</label>

    <textarea name="answer"
              id="f_answer"
              class="form-control"
              rows="6"></textarea>
</div>

<div class="form-grid-2 legacy-style-911b26ad8a"
    >

    <div class="form-group">
        <label>Sort Order</label>

        <input type="number"
               name="sort_order"
               id="f_sort_order"
               class="form-control"
               value="0">
    </div>

    <div class="form-group">
        <label>Status</label>

        <select name="status"
                id="f_status"
                class="form-control">

            <option value="1">Active</option>
            <option value="0">Hidden</option>
        </select>
    </div>

</div>
</div>
</div>

<div class="modal-footer">
    <button type="button"
            class="btn btn-secondary"
            onclick="closeModal()">
        Cancel
    </button>

    <button type="submit"
            class="btn btn-primary">
        <i class="fa fa-save"></i> Save FAQ
    </button>
</div>

</form>
</div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sidebar?.classList.toggle('collapsed');
    main?.classList.toggle('collapsed');
});

const modal = document.getElementById('faqModal');

function resetFaqForm() {
    document.getElementById('modalTitle').textContent = 'Add FAQ';

    document.querySelector('#faqModal form').reset();

    document.getElementById('f_id').value = '';
    document.getElementById('f_status').value = '1';
    document.getElementById('f_sort_order').value = '0';
}

function openModal() {
    resetFaqForm();
    modal.classList.add('open');
}

function closeModal() {
    modal.classList.remove('open');
}

modal.addEventListener('click', e => {
    if (e.target === modal) {
        closeModal();
    }
});

function editFaq(data) {
    resetFaqForm();

    document.getElementById('modalTitle').textContent = 'Edit FAQ';

    document.getElementById('f_id').value = data.id || '';
    document.getElementById('f_question').value = data.question || '';
    document.getElementById('f_answer').value = data.answer || '';
    document.getElementById('f_sort_order').value = data.sort_order || 0;
    document.getElementById('f_status').value = String(data.status ?? 1);

    modal.classList.add('open');
}

<?php if ($edit): ?>
editFaq(<?= json_encode($edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
<?php endif; ?>
</script>

</body>
</html>
