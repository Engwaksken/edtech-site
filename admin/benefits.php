<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';


function save_site_setting(mysqli $conn, string $key, string $value): bool
{
    $k = esc($conn, $key);
    $v = esc($conn, $value);

    return (bool) mysqli_query($conn, "
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES ('$k', '$v')
        ON DUPLICATE KEY UPDATE setting_value = '$v'
    ");
}

function setting_value(mysqli $conn, string $key, string $default = ''): string
{
    if (function_exists('get_setting')) {
        return (string) get_setting($conn, $key, $default);
    }

    $k = esc($conn, $key);
    $q = mysqli_query($conn, "SELECT setting_value FROM site_settings WHERE setting_key='$k' LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        return (string) $row['setting_value'];
    }

    return $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

   
    if ($action === 'save_section_bg') {
        $err = '';

        $bg_type = $_POST['benefits_bg_type'] ?? 'color';
        if (!in_array($bg_type, ['color', 'image', 'video'], true)) {
            $bg_type = 'color';
        }

        $bg_color = trim($_POST['benefits_bg_color'] ?? '#ffffff');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bg_color)) {
            $bg_color = '#ffffff';
        }

        $overlay_enabled = isset($_POST['benefits_bg_overlay']) ? '1' : '0';

        $overlay_color = trim($_POST['benefits_bg_overlay_color'] ?? '#000000');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $overlay_color)) {
            $overlay_color = '#000000';
        }

        $overlay_opacity = (int)($_POST['benefits_bg_overlay_opacity'] ?? 35);
        $overlay_opacity = max(0, min(100, $overlay_opacity));

        $benefits_title = trim($_POST['benefits_section_title'] ?? '');
        $benefits_description = trim($_POST['benefits_section_description'] ?? '');

        save_site_setting($conn, 'benefits_bg_type', $bg_type);
        save_site_setting($conn, 'benefits_bg_color', $bg_color);
        save_site_setting($conn, 'benefits_bg_overlay', $overlay_enabled);
        save_site_setting($conn, 'benefits_bg_overlay_color', $overlay_color);
        save_site_setting($conn, 'benefits_bg_overlay_opacity', (string)$overlay_opacity);
        save_site_setting($conn, 'benefits_section_title', $benefits_title);
        save_site_setting($conn, 'benefits_section_description', $benefits_description);

        if (!empty($_FILES['benefits_bg_image_file']['name'])) {
            $new_img = upload_image('benefits_bg_image_file', 'settings/backgrounds', $err);
            if ($new_img) {
                save_site_setting($conn, 'benefits_bg_image', $new_img);
                save_site_setting($conn, 'benefits_bg_type', 'image');
            } else {
                flash('benefits', $err ?: 'Background image upload failed.', 'error');
                header('Location: benefits.php');
                exit;
            }
        }

        if (!empty($_FILES['benefits_bg_video_file']['name'])) {
            $ext = strtolower(pathinfo($_FILES['benefits_bg_video_file']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, ['mp4', 'webm', 'ogg'], true)) {
                flash('benefits', 'Invalid video format. Use MP4, WebM, or OGG.', 'error');
                header('Location: benefits.php');
                exit;
            }

            $dest_dir = UPLOAD_PATH . '/settings/backgrounds/';
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0755, true);
            }

            $filename = uniqid('benefits_bg_', true) . '.' . $ext;
            $target = $dest_dir . $filename;

            if (move_uploaded_file($_FILES['benefits_bg_video_file']['tmp_name'], $target)) {
                save_site_setting($conn, 'benefits_bg_video', 'uploads/settings/backgrounds/' . $filename);
                save_site_setting($conn, 'benefits_bg_type', 'video');
            } else {
                flash('benefits', 'Background video upload failed.', 'error');
                header('Location: benefits.php');
                exit;
            }
        }

        flash('benefits', 'Benefits section background updated successfully.');
        header('Location: benefits.php');
        exit;
    }

    /**
     * Remove section image/video
     */
    if ($action === 'remove_bg_image') {
        save_site_setting($conn, 'benefits_bg_image', '');
        if (setting_value($conn, 'benefits_bg_type', 'color') === 'image') {
            save_site_setting($conn, 'benefits_bg_type', 'color');
        }
        flash('benefits', 'Background image removed.');
        header('Location: benefits.php');
        exit;
    }

    if ($action === 'remove_bg_video') {
        save_site_setting($conn, 'benefits_bg_video', '');
        if (setting_value($conn, 'benefits_bg_type', 'color') === 'video') {
            save_site_setting($conn, 'benefits_bg_type', 'color');
        }
        flash('benefits', 'Background video removed.');
        header('Location: benefits.php');
        exit;
    }

    /**
     * Save benefit card
     */
    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $icon   = esc($conn, $_POST['icon'] ?? 'fa-star');
        $title  = esc($conn, $_POST['title'] ?? '');
        $desc   = esc($conn, $_POST['description'] ?? '');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $status = (int)($_POST['status'] ?? 1);

        if (!$title) {
            flash('benefits', 'Title required.', 'error');
        } else {
            if ($id) {
                mysqli_query($conn, "
                    UPDATE benefits
                    SET icon='$icon',
                        title='$title',
                        description='$desc',
                        sort_order=$sort,
                        status=$status
                    WHERE id=$id
                ");
                flash('benefits', 'Benefit updated successfully.');
            } else {
                mysqli_query($conn, "
                    INSERT INTO benefits(icon, title, description, sort_order, status)
                    VALUES('$icon', '$title', '$desc', $sort, $status)
                ");
                flash('benefits', 'Benefit added successfully.');
            }
        }

        header('Location: benefits.php');
        exit;
    }

    /**
     * Delete benefit card
     */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM benefits WHERE id=$id");
        flash('benefits', 'Benefit deleted.');
        header('Location: benefits.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $r = mysqli_query($conn, "SELECT * FROM benefits WHERE id=" . (int)$_GET['edit'] . " LIMIT 1");
    $edit = $r ? mysqli_fetch_assoc($r) : null;
}

$list = mysqli_query($conn, "SELECT * FROM benefits ORDER BY sort_order, id");

$site_name = setting_value($conn, 'site_name', 'EdTech Fellowship');

$benefits_section_title       = setting_value($conn, 'benefits_section_title', 'What Fellows Receive');
$benefits_section_description = setting_value($conn, 'benefits_section_description', '');
$benefits_bg_type             = setting_value($conn, 'benefits_bg_type', 'color');
$benefits_bg_color            = setting_value($conn, 'benefits_bg_color', '#ffffff');
$benefits_bg_image            = setting_value($conn, 'benefits_bg_image', '');
$benefits_bg_video            = setting_value($conn, 'benefits_bg_video', '');
$benefits_bg_overlay          = setting_value($conn, 'benefits_bg_overlay', '0');
$benefits_bg_overlay_color    = setting_value($conn, 'benefits_bg_overlay_color', '#000000');
$benefits_bg_overlay_opacity  = setting_value($conn, 'benefits_bg_overlay_opacity', '35');

$preview_style = 'background:' . h($benefits_bg_color) . ';';
if ($benefits_bg_type === 'image' && $benefits_bg_image !== '') {
    $preview_style = "background-image:url('" . SITE_URL . '/' . h($benefits_bg_image) . "');background-size:cover;background-position:center;";
}

$fa_icons = [
    'fa-star',
    'fa-hand-holding-usd',
    'fa-book-open',
    'fa-user-tie',
    'fa-handshake',
    'fa-microscope',
    'fa-bullhorn',
    'fa-rocket',
    'fa-chart-line',
    'fa-graduation-cap',
    'fa-lightbulb',
    'fa-trophy',
    'fa-globe',
    'fa-heart',
    'fa-shield-alt',
    'fa-coins'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Benefits - <?= h($site_name) ?> Admin</title>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">

    <style>
        .section-bg-manager {
            margin-bottom: 24px;
        }

        .bg-settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .bg-settings-grid .full {
            grid-column: 1 / -1;
        }

        .bg-type-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .bg-type-option {
            position: relative;
            cursor: pointer;
        }

        .bg-type-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .bg-type-option span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1.5px solid var(--border);
            border-radius: 999px;
            padding: 9px 16px;
            background: #fff;
            color: var(--text-muted);
            font-weight: 700;
            font-size: .85rem;
            transition: var(--transition);
        }

        .bg-type-option input:checked + span {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .bg-current-preview {
            min-height: 210px;
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-end;
            padding: 26px;
            color: #fff;
            box-shadow: var(--shadow);
            margin-bottom: 18px;
        }

        .bg-current-preview video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .bg-current-preview__overlay {
            position: absolute;
            inset: 0;
            background: <?= h($benefits_bg_overlay_color) ?>;
            opacity: <?= $benefits_bg_overlay === '1' ? ((int)$benefits_bg_overlay_opacity / 100) : 0 ?>;
        }

        .bg-current-preview__content {
            position: relative;
            z-index: 2;
            max-width: 680px;
        }

        .bg-current-preview__badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(252,127,16,.95);
            padding: 7px 14px;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 12px;
        }

        .bg-current-preview__content h2 {
            color: #fff;
            margin: 0 0 8px;
            font-size: clamp(1.6rem, 3vw, 2.6rem);
            line-height: 1.08;
        }

        .bg-current-preview__content p {
            margin: 0;
            color: rgba(255,255,255,.9);
        }

        .color-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .color-row input[type="color"] {
            width: 48px;
            height: 42px;
            border: 0;
            padding: 2px;
            background: transparent;
            cursor: pointer;
        }

        .range-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .range-row input[type="range"] {
            flex: 1;
        }

        .range-row input[type="number"] {
            max-width: 85px;
        }

        .mini-preview {
            width: 160px;
            height: 94px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--light);
            margin-bottom: 10px;
            display: block;
        }

        .video-preview-box {
            width: 220px;
            height: 120px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: #111827;
            margin-bottom: 10px;
        }

        .video-preview-box video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .icon-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
            gap: 8px;
            margin-top: 10px;
        }

        .icon-picker-btn {
            height: 38px;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .icon-picker-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        @media (max-width: 768px) {
            .bg-settings-grid {
                grid-template-columns: 1fr;
            }

            .bg-settings-grid .full {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
    <header class="admin-topbar">
        <div class="topbar-left">
            <div class="topbar-breadcrumb">
                <a href="index.php">Dashboard</a> › <strong>Benefits</strong>
            </div>
        </div>

        <div class="topbar-right">
            <a href="<?= SITE_URL ?>" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fa fa-eye"></i> View Site
            </a>

            <div class="admin-avatar">
                <div class="avatar-circle">
                    <?= strtoupper(substr($ADMIN['full_name'], 0, 1)) ?>
                </div>
            </div>
        </div>
    </header>

    <div class="admin-content">
        <?php show_flash('benefits'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Benefits</h1>
                <p class="page-subtitle">Manage the “What Fellows Receive” cards and section background</p>
            </div>

            <button class="btn btn-primary" onclick="openModal()" type="button">
                <i class="fa fa-plus"></i> Add Benefit
            </button>
        </div>

        <div class="card section-bg-manager">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fa fa-image legacy-style-7d9b548184"></i>
                    Benefits Section Background
                </h3>
            </div>

            <div class="card-body">
                <div class="bg-current-preview" style="<?= $preview_style ?>">
                    <?php if ($benefits_bg_type === 'video' && $benefits_bg_video !== ''): ?>
                        <video autoplay muted loop playsinline>
                            <source src="<?= SITE_URL . '/' . h($benefits_bg_video) ?>">
                        </video>
                    <?php endif; ?>

                    <div class="bg-current-preview__overlay"></div>

                    <div class="bg-current-preview__content">
                        <span class="bg-current-preview__badge">
                            <i class="fa fa-gift"></i>
                            <?= h(strtoupper($benefits_bg_type)) ?> Background
                        </span>
                        <h2><?= h($benefits_section_title ?: 'What Fellows Receive') ?></h2>
                        <?php if ($benefits_section_description !== ''): ?>
                            <p><?= h($benefits_section_description) ?></p>
                        <?php else: ?>
                            <p>Preview how the benefits section background will appear on the frontend.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_section_bg">

                    <div class="bg-settings-grid">
                        <div class="form-group full">
                            <label>Background Type</label>
                            <div class="bg-type-options">
                                <label class="bg-type-option">
                                    <input type="radio" name="benefits_bg_type" value="color" <?= $benefits_bg_type === 'color' ? 'checked' : '' ?>>
                                    <span><i class="fa fa-palette"></i> Solid Color</span>
                                </label>

                                <label class="bg-type-option">
                                    <input type="radio" name="benefits_bg_type" value="image" <?= $benefits_bg_type === 'image' ? 'checked' : '' ?>>
                                    <span><i class="fa fa-image"></i> Image</span>
                                </label>

                                <label class="bg-type-option">
                                    <input type="radio" name="benefits_bg_type" value="video" <?= $benefits_bg_type === 'video' ? 'checked' : '' ?>>
                                    <span><i class="fa fa-film"></i> Video</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Section Title</label>
                            <input type="text" name="benefits_section_title" class="form-control" value="<?= h($benefits_section_title) ?>">
                        </div>

                        <div class="form-group">
                            <label>Solid Background Color</label>
                            <div class="color-row">
                                <input type="color" value="<?= h($benefits_bg_color) ?>" id="bgColorPicker" oninput="syncBgColor()">
                                <input type="text" name="benefits_bg_color" id="bgColorText" class="form-control" value="<?= h($benefits_bg_color) ?>" oninput="syncBgColorText()">
                            </div>
                        </div>

                        <div class="form-group full">
                            <label>Section Description</label>
                            <textarea name="benefits_section_description" class="form-control" rows="3"><?= h($benefits_section_description) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Upload Background Image</label>

                            <?php if ($benefits_bg_image !== ''): ?>
                                <img src="<?= SITE_URL . '/' . h($benefits_bg_image) ?>" class="mini-preview" alt="Current benefits background">
                            <?php endif; ?>

                            <input type="file" name="benefits_bg_image_file" class="form-control" accept="image/*">
                            <span class="form-hint">Recommended: 1920×900px or wider.</span>
                        </div>

                        <div class="form-group">
                            <label>Upload Background Video</label>

                            <?php if ($benefits_bg_video !== ''): ?>
                                <div class="video-preview-box">
                                    <video autoplay muted loop playsinline>
                                        <source src="<?= SITE_URL . '/' . h($benefits_bg_video) ?>">
                                    </video>
                                </div>
                            <?php endif; ?>

                            <input type="file" name="benefits_bg_video_file" class="form-control" accept="video/mp4,video/webm,video/ogg">
                            <span class="form-hint">MP4, WebM or OGG. Keep it lightweight for faster page loading.</span>
                        </div>

                        <div class="form-group">
                            <label>Overlay</label>
                            <label class="legacy-style-cda9eb406d">
                                <input type="checkbox" name="benefits_bg_overlay" value="1" <?= $benefits_bg_overlay === '1' ? 'checked' : '' ?>>
                                Enable overlay for image/video
                            </label>
                        </div>

                        <div class="form-group">
                            <label>Overlay Color</label>
                            <div class="color-row">
                                <input type="color" value="<?= h($benefits_bg_overlay_color) ?>" id="overlayColorPicker" oninput="syncOverlayColor()">
                                <input type="text" name="benefits_bg_overlay_color" id="overlayColorText" class="form-control" value="<?= h($benefits_bg_overlay_color) ?>" oninput="syncOverlayColorText()">
                            </div>
                        </div>

                        <div class="form-group full">
                            <label>Overlay Opacity (%)</label>
                            <div class="range-row">
                                <input type="range" min="0" max="100" step="1" value="<?= h($benefits_bg_overlay_opacity) ?>" id="overlayRange" oninput="syncOverlayOpacity()">
                                <input type="number" min="0" max="100" name="benefits_bg_overlay_opacity" class="form-control" value="<?= h($benefits_bg_overlay_opacity) ?>" id="overlayNumber" oninput="syncOverlayOpacityNumber()">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer legacy-style-d2755826fa">
                        <?php if ($benefits_bg_image !== ''): ?>
                            <button type="submit" name="action" value="remove_bg_image" class="btn btn-danger" onclick="return confirm('Remove background image?')">
                                <i class="fa fa-times"></i> Remove Image
                            </button>
                        <?php endif; ?>

                        <?php if ($benefits_bg_video !== ''): ?>
                            <button type="submit" name="action" value="remove_bg_video" class="btn btn-danger" onclick="return confirm('Remove background video?')">
                                <i class="fa fa-times"></i> Remove Video
                            </button>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Section Background
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Benefits List</h3>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Icon</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php $has = false; ?>
                    <?php while ($b = mysqli_fetch_assoc($list)): ?>
                        <?php $has = true; ?>

                        <tr>
                            <td>
                                <i class="fa <?= h($b['icon']) ?>" style="font-size:1.2rem;color:var(--primary)"></i>
                            </td>

                            <td>
                                <strong><?= h($b['title']) ?></strong>
                            </td>

                            <td>
                                <?= h(truncate($b['description'], 80)) ?>
                            </td>

                            <td>
                                <?= (int)$b['sort_order'] ?>
                            </td>

                            <td>
                                <span class="badge <?= $b['status'] ? 'badge-success' : 'badge-gray' ?>">
                                    <?= $b['status'] ? 'Active' : 'Hidden' ?>
                                </span>
                            </td>

                            <td>
                                <div class="tbl-actions">
                                    <button onclick="editBenefit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)" class="btn btn-sm btn-secondary" type="button">
                                        <i class="fa fa-edit"></i>
                                    </button>

                                    <form method="POST" onsubmit="return confirm('Delete this benefit?')" class="legacy-style-cccfa4560d">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button class="btn btn-sm btn-danger">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    <?php if (!$has): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa fa-gift"></i>
                                    <h3>No benefits yet</h3>
                                    <button class="btn btn-primary" onclick="openModal()" type="button">
                                        <i class="fa fa-plus"></i> Add Benefit
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

<div class="modal-overlay" id="bModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Add Benefit</h2>
            <button class="modal-close" onclick="closeModal()" type="button">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id">

            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group full">
                        <label>Title <span class="req">*</span></label>
                        <input type="text" name="title" id="f_title" class="form-control" required>
                    </div>

                    <div class="form-group full">
                        <label>Description</label>
                        <textarea name="description" id="f_desc" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Font Awesome Icon Class</label>
                        <input type="text" name="icon" id="f_icon" class="form-control" placeholder="fa-star">
                        <span class="form-hint">Example: fa-star, fa-handshake, fa-book-open</span>

                        <div class="icon-picker-grid">
                            <?php foreach ($fa_icons as $icon): ?>
                                <button type="button" class="icon-picker-btn" onclick="selectIcon('<?= h($icon) ?>')" title="<?= h($icon) ?>">
                                    <i class="fa <?= h($icon) ?>"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" id="f_sort" class="form-control" value="0">
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="f_status" class="form-control">
                            <option value="1">Active</option>
                            <option value="0">Hidden</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Benefit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    main.classList.toggle('collapsed');
});

const modal = document.getElementById('bModal');

function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Benefit';
    document.getElementById('f_id').value = '';
    document.getElementById('f_title').value = '';
    document.getElementById('f_desc').value = '';
    document.getElementById('f_icon').value = 'fa-star';
    document.getElementById('f_sort').value = 0;
    document.getElementById('f_status').value = 1;
    modal.classList.add('open');
}

function closeModal() {
    modal.classList.remove('open');
}

modal.addEventListener('click', e => {
    if (e.target === modal) closeModal();
});

function editBenefit(d) {
    document.getElementById('modalTitle').textContent = 'Edit Benefit';
    document.getElementById('f_id').value = d.id || '';
    document.getElementById('f_title').value = d.title || '';
    document.getElementById('f_desc').value = d.description || '';
    document.getElementById('f_icon').value = d.icon || 'fa-star';
    document.getElementById('f_sort').value = d.sort_order || 0;
    document.getElementById('f_status').value = d.status || 0;
    modal.classList.add('open');
}

function selectIcon(icon) {
    document.getElementById('f_icon').value = icon;
}

function syncBgColor() {
    const picker = document.getElementById('bgColorPicker');
    const text = document.getElementById('bgColorText');
    text.value = picker.value;
}

function syncBgColorText() {
    const picker = document.getElementById('bgColorPicker');
    const text = document.getElementById('bgColorText');
    if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
        picker.value = text.value;
    }
}

function syncOverlayColor() {
    const picker = document.getElementById('overlayColorPicker');
    const text = document.getElementById('overlayColorText');
    text.value = picker.value;
}

function syncOverlayColorText() {
    const picker = document.getElementById('overlayColorPicker');
    const text = document.getElementById('overlayColorText');
    if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) {
        picker.value = text.value;
    }
}

function syncOverlayOpacity() {
    const range = document.getElementById('overlayRange');
    const number = document.getElementById('overlayNumber');
    number.value = range.value;
}

function syncOverlayOpacityNumber() {
    const range = document.getElementById('overlayRange');
    const number = document.getElementById('overlayNumber');
    range.value = number.value;
}

<?php if ($edit): ?>
editBenefit(<?= json_encode($edit) ?>);
<?php endif; ?>
</script>
</body>
</html>
