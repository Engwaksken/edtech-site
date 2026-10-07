<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

function ps_col_exists(mysqli $conn, string $table, string $column): bool {
    $table  = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);

    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $q && mysqli_num_rows($q) > 0;
}

function ps_ensure_columns(mysqli $conn): void {
    if (!ps_col_exists($conn, 'program_stages', 'background_type')) {
        mysqli_query($conn, "ALTER TABLE program_stages ADD background_type VARCHAR(20) DEFAULT 'color' AFTER icon");
    }

    if (!ps_col_exists($conn, 'program_stages', 'background_image')) {
        mysqli_query($conn, "ALTER TABLE program_stages ADD background_image VARCHAR(255) NULL AFTER background_type");
    }

    if (!ps_col_exists($conn, 'program_stages', 'background_video')) {
        mysqli_query($conn, "ALTER TABLE program_stages ADD background_video VARCHAR(255) NULL AFTER background_image");
    }

    if (!ps_col_exists($conn, 'program_stages', 'background_color')) {
        mysqli_query($conn, "ALTER TABLE program_stages ADD background_color VARCHAR(30) DEFAULT '#f8fafc' AFTER background_video");
    }
}

function save_setting(mysqli $conn, string $key, string $value): void {
    $k = esc($conn, $key);
    $v = esc($conn, $value);

    mysqli_query($conn, "
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES ('$k', '$v')
        ON DUPLICATE KEY UPDATE setting_value = '$v'
    ");
}

function ps_upload_file(string $field, string $folder, array $allowed, string &$err): string {
    if (empty($_FILES[$field]['name'])) return '';

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed. Please try again.';
        return '';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        $err = 'Invalid file type. Allowed: ' . implode(', ', $allowed);
        return '';
    }

    $uploadDir = '../' . trim($folder, '/');

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $name = uniqid('stage_', true) . '.' . $ext;
    $dest = $uploadDir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $err = 'Could not save uploaded file.';
        return '';
    }

    return trim($folder, '/') . '/' . $name;
}

ps_ensure_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_banner') {
        $err = '';

        save_setting($conn, 'program_stages_banner_title', $_POST['program_stages_banner_title'] ?? '');
        save_setting($conn, 'program_stages_banner_subtitle', $_POST['program_stages_banner_subtitle'] ?? '');
        save_setting($conn, 'program_stages_banner_overlay', $_POST['program_stages_banner_overlay'] ?? '45');

        if (!empty($_FILES['program_stages_banner_image_file']['name'])) {
            $new_img = upload_image('program_stages_banner_image_file', 'settings/backgrounds', $err);

            if ($new_img) {
                save_setting($conn, 'program_stages_banner_image', $new_img);
            } else {
                flash('stages', $err ?: 'Banner image upload failed.', 'error');
                header('Location: program-stages.php');
                exit;
            }
        }

        flash('stages', 'Program stages banner updated successfully.');
        header('Location: program-stages.php');
        exit;
    }

    if ($action === 'remove_banner') {
        save_setting($conn, 'program_stages_banner_image', '');
        flash('stages', 'Banner image removed.');
        header('Location: program-stages.php');
        exit;
    }

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $num    = esc($conn, $_POST['stage_number'] ?? '');
        $icon   = esc($conn, $_POST['icon'] ?? 'fa-circle');
        $title  = esc($conn, $_POST['title'] ?? '');
        $desc   = esc($conn, $_POST['description'] ?? '');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $status = (int)($_POST['status'] ?? 1);

        $background_type  = $_POST['background_type'] ?? 'color';
        $background_color = esc($conn, $_POST['background_color'] ?? '#f8fafc');

        if (!in_array($background_type, ['image', 'video', 'color'], true)) {
            $background_type = 'color';
        }

        $background_type = esc($conn, $background_type);

        $old_image = '';
        $old_video = '';

        if ($id) {
            $old = mysqli_query($conn, "SELECT background_image, background_video FROM program_stages WHERE id=$id LIMIT 1");
            if ($old && mysqli_num_rows($old)) {
                $oldRow = mysqli_fetch_assoc($old);
                $old_image = $oldRow['background_image'] ?? '';
                $old_video = $oldRow['background_video'] ?? '';
            }
        }

        $err = '';
        $background_image = $old_image;
        $background_video = $old_video;

        if (!empty($_FILES['background_image_file']['name'])) {
            $uploaded = ps_upload_file(
                'background_image_file',
                'uploads/program-stages/images',
                ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                $err
            );

            if (!$uploaded) {
                flash('stages', $err ?: 'Image upload failed.', 'error');
                header('Location: program-stages.php' . ($id ? '?edit=' . $id : ''));
                exit;
            }

            $background_image = $uploaded;
        }

        if (!empty($_FILES['background_video_file']['name'])) {
            $uploaded = ps_upload_file(
                'background_video_file',
                'uploads/program-stages/videos',
                ['mp4', 'webm', 'ogg', 'mov'],
                $err
            );

            if (!$uploaded) {
                flash('stages', $err ?: 'Video upload failed.', 'error');
                header('Location: program-stages.php' . ($id ? '?edit=' . $id : ''));
                exit;
            }

            $background_video = $uploaded;
        }

        if ($background_type === 'image') {
            $background_video = '';
        } elseif ($background_type === 'video') {
            $background_image = '';
        }

        $background_image = esc($conn, $background_image);
        $background_video = esc($conn, $background_video);

        $feats_raw  = $_POST['features_text'] ?? '';
        $feats      = array_filter(array_map('trim', explode("\n", $feats_raw)));
        $feats_json = esc($conn, json_encode(array_values($feats)));

        if (!$title) {
            flash('stages', 'Title required.', 'error');
        } else {
            if ($id) {
                mysqli_query($conn, "
                    UPDATE program_stages 
                    SET stage_number='$num',
                        icon='$icon',
                        background_type='$background_type',
                        background_image='$background_image',
                        background_video='$background_video',
                        background_color='$background_color',
                        title='$title',
                        description='$desc',
                        features='$feats_json',
                        sort_order=$sort,
                        status=$status 
                    WHERE id=$id
                ");

                flash('stages', 'Program stage updated.');
            } else {
                mysqli_query($conn, "
                    INSERT INTO program_stages
                    (
                        stage_number,
                        icon,
                        background_type,
                        background_image,
                        background_video,
                        background_color,
                        title,
                        description,
                        features,
                        sort_order,
                        status
                    )
                    VALUES
                    (
                        '$num',
                        '$icon',
                        '$background_type',
                        '$background_image',
                        '$background_video',
                        '$background_color',
                        '$title',
                        '$desc',
                        '$feats_json',
                        $sort,
                        $status
                    )
                ");

                flash('stages', 'Program stage added.');
            }
        }

        header('Location: program-stages.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM program_stages WHERE id=$id");
        flash('stages', 'Program stage deleted.');
        header('Location: program-stages.php');
        exit;
    }
}

$edit = null;

if (isset($_GET['edit'])) {
    $r = mysqli_query($conn, "SELECT * FROM program_stages WHERE id=" . (int)$_GET['edit'] . " LIMIT 1");
    $edit = $r ? mysqli_fetch_assoc($r) : null;
}

$list = mysqli_query($conn, "SELECT * FROM program_stages ORDER BY sort_order, id");

$site_name = get_setting($conn, 'site_name');

$banner_title    = get_setting($conn, 'program_stages_banner_title') ?: 'Program Stages';
$banner_subtitle = get_setting($conn, 'program_stages_banner_subtitle') ?: 'Manage the program structure, stages, features, icons and display order.';
$banner_image    = get_setting($conn, 'program_stages_banner_image') ?: '';
$banner_overlay  = get_setting($conn, 'program_stages_banner_overlay') ?: '45';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Program Stages — <?= h($site_name) ?> Admin</title>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">

    
</head>

<body>
<?php include 'includes/sidebar.php'; ?>

<div class="admin-main" id="adminMain">
    <header class="admin-topbar">
        <div class="topbar-left">
            <div class="topbar-breadcrumb">
                <a href="index.php">Dashboard</a> › <strong>Program Stages</strong>
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
        <?php show_flash('stages'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Program Stages</h1>
                <p class="page-subtitle">Manage program stages with image, video or color backgrounds.</p>
            </div>

            <button class="btn btn-primary" onclick="openModal()" type="button">
                <i class="fa fa-plus"></i> Add Stage
            </button>
        </div>

        <div class="section-banner-admin">
            <?php if ($banner_image !== ''): ?>
                <div class="section-banner-admin__bg" style="background-image:url('<?= SITE_URL . '/' . h($banner_image) ?>')"></div>
            <?php endif; ?>

            <div class="section-banner-admin__overlay" style="--banner-overlay:<?= h((string)((int)$banner_overlay / 100)) ?>"></div>

            <div class="section-banner-admin__content">
                <span class="section-banner-admin__label">
                    <i class="fa fa-layer-group"></i> Section Banner
                </span>
                <h2><?= h($banner_title) ?></h2>
                <p><?= h($banner_subtitle) ?></p>
            </div>
        </div>

        <div class="card legacy-style-8b9688e6e0">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fa fa-image legacy-style-7d9b548184"></i>
                    Program Stages Banner Image
                </h3>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_banner">

                <div class="card-body">
                    <div class="banner-settings-grid">
                        <div class="form-group">
                            <label>Banner Title</label>
                            <input type="text" name="program_stages_banner_title" class="form-control" value="<?= h($banner_title) ?>">
                        </div>

                        <div class="form-group">
                            <label>Overlay Opacity (%)</label>
                            <div class="range-row">
                                <input type="range" min="0" max="90" step="1" value="<?= h($banner_overlay) ?>" id="bannerOverlayRange" oninput="syncBannerOverlay()">
                                <input type="number" min="0" max="90" name="program_stages_banner_overlay" class="form-control" value="<?= h($banner_overlay) ?>" id="bannerOverlayInput" oninput="syncBannerOverlayInput()">
                            </div>
                        </div>

                        <div class="form-group full">
                            <label>Banner Subtitle</label>
                            <textarea name="program_stages_banner_subtitle" class="form-control" rows="3"><?= h($banner_subtitle) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Upload Background Banner Image</label>

                            <?php if ($banner_image !== ''): ?>
                                <img src="<?= SITE_URL . '/' . h($banner_image) ?>" class="banner-preview-img" alt="Current banner">
                            <?php endif; ?>

                            <input type="file" name="program_stages_banner_image_file" class="form-control" accept="image/*">
                            <span class="form-hint">Recommended size: 1920×650px or wider.</span>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <?php if ($banner_image !== ''): ?>
                        <button type="submit" formaction="program-stages.php" formmethod="POST" name="action" value="remove_banner" class="btn btn-danger" onclick="return confirm('Remove banner image?')">
                            <i class="fa fa-times"></i> Remove Image
                        </button>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> Save Banner
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Program Stage List</h3>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Background</th>
                        <th>Stage #</th>
                        <th>Icon</th>
                        <th>Title</th>
                        <th>Features</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php $has = false; ?>
                    <?php while ($s = mysqli_fetch_assoc($list)): ?>
                        <?php
                        $has = true;
                        $feats = json_decode($s['features'] ?? '[]', true);
                        if (!is_array($feats)) $feats = [];

                        $bgType  = $s['background_type'] ?? 'color';
                        $bgImage = $s['background_image'] ?? '';
                        $bgVideo = $s['background_video'] ?? '';
                        $bgColor = $s['background_color'] ?? '#f8fafc';
                        ?>

                        <tr>
                            <td>
                                <div class="stage-thumb">
                                    <?php if ($bgType === 'image' && $bgImage): ?>
                                        <img src="<?= SITE_URL . '/' . h($bgImage) ?>" alt="">
                                    <?php elseif ($bgType === 'video' && $bgVideo): ?>
                                        <video muted>
                                            <source src="<?= SITE_URL . '/' . h($bgVideo) ?>">
                                        </video>
                                    <?php else: ?>
                                        <div class="stage-thumb-color" style="background:<?= h($bgColor) ?>"></div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td><strong><?= h($s['stage_number']) ?></strong></td>

                            <td>
                                <i class="fa <?= h($s['icon']) ?>" style="color:var(--primary)"></i>
                            </td>

                            <td>
                                <strong><?= h($s['title']) ?></strong><br>
                                <small class="legacy-style-5872de20d5">
                                    <?= h(truncate($s['description'], 60)) ?>
                                </small>
                            </td>

                            <td><small><?= count($feats) ?> features</small></td>

                            <td><?= (int)$s['sort_order'] ?></td>

                            <td>
                                <span class="badge <?= $s['status'] ? 'badge-success' : 'badge-gray' ?>">
                                    <?= $s['status'] ? 'Active' : 'Hidden' ?>
                                </span>
                            </td>

                            <td>
                                <div class="tbl-actions">
                                    <button onclick="editStage(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)" class="btn btn-sm btn-secondary" type="button">
                                        <i class="fa fa-edit"></i>
                                    </button>

                                    <form method="POST" onsubmit="return confirm('Delete this stage?')" class="legacy-style-cccfa4560d">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fa fa-map-signs"></i>
                                    <h3>No stages yet</h3>
                                    <button class="btn btn-primary" onclick="openModal()" type="button">
                                        <i class="fa fa-plus"></i> Add Stage
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

<div class="modal-overlay" id="stageModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Add Program Stage</h2>
            <button class="modal-close" onclick="closeModal()" type="button">×</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id">

            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Stage Number</label>
                        <input type="text" name="stage_number" id="f_num" class="form-control" placeholder="01">
                    </div>

                    <div class="form-group">
                        <label>Icon FA Class</label>
                        <input type="text" name="icon" id="f_icon" class="form-control" placeholder="fa-lightbulb">
                    </div>

                    <div class="form-group full">
                        <label>Background Type</label>
                        <select name="background_type" id="f_background_type" class="form-control" onchange="toggleStageMediaFields()">
                            <option value="color">Color Background</option>
                            <option value="image">Image Background</option>
                            <option value="video">Video Background</option>
                        </select>
                    </div>

                    <div class="form-group full media-field" id="media_color">
                        <label>Background Color</label>
                        <div class="color-input-row">
                            <input type="color" id="f_background_color_picker" value="#f8fafc" oninput="syncStageColorPicker()">
                            <input type="text" name="background_color" id="f_background_color" class="form-control" value="#f8fafc" oninput="syncStageColorText()">
                        </div>
                    </div>

                    <div class="form-group full media-field" id="media_image">
                        <label>Upload Stage Background Image</label>
                        <img src="" class="stage-preview-img legacy-style-c8be1ccba6" id="imagePreview" alt="">
                        <input type="file" name="background_image_file" class="form-control" accept="image/*">
                        <span class="form-hint">Allowed: JPG, PNG, WebP, GIF.</span>
                    </div>

                    <div class="form-group full media-field" id="media_video">
                        <label>Upload Stage Background Video</label>
                        <video class="stage-preview-video legacy-style-c8be1ccba6" id="videoPreview" controls></video>
                        <input type="file" name="background_video_file" class="form-control" accept="video/mp4,video/webm,video/ogg,video/quicktime">
                        <span class="form-hint">Allowed: MP4, WebM, OGG, MOV.</span>
                    </div>

                    <div class="form-group full">
                        <label>Title <span class="req">*</span></label>
                        <input type="text" name="title" id="f_title" class="form-control" required>
                    </div>

                    <div class="form-group full">
                        <label>Description</label>
                        <textarea name="description" id="f_desc" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group full">
                        <label>Features - one per line</label>
                        <textarea name="features_text" id="f_features" class="form-control" rows="6" placeholder="Feature one&#10;Feature two&#10;Feature three"></textarea>
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
                    <i class="fa fa-save"></i> Save Stage
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

const modal = document.getElementById('stageModal');

function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Program Stage';
    document.getElementById('f_id').value = '';
    document.getElementById('f_num').value = '';
    document.getElementById('f_icon').value = 'fa-circle';
    document.getElementById('f_background_type').value = 'color';
    document.getElementById('f_background_color').value = '#f8fafc';
    document.getElementById('f_background_color_picker').value = '#f8fafc';
    document.getElementById('f_title').value = '';
    document.getElementById('f_desc').value = '';
    document.getElementById('f_features').value = '';
    document.getElementById('f_sort').value = 0;
    document.getElementById('f_status').value = 1;

    document.getElementById('imagePreview').style.display = 'none';
    document.getElementById('videoPreview').style.display = 'none';

    toggleStageMediaFields();
    modal.classList.add('open');
}

function closeModal() {
    modal.classList.remove('open');
}

modal.addEventListener('click', e => {
    if (e.target === modal) closeModal();
});

function editStage(d) {
    document.getElementById('modalTitle').textContent = 'Edit Stage';
    document.getElementById('f_id').value = d.id || '';
    document.getElementById('f_num').value = d.stage_number || '';
    document.getElementById('f_icon').value = d.icon || 'fa-circle';
    document.getElementById('f_background_type').value = d.background_type || 'color';
    document.getElementById('f_background_color').value = d.background_color || '#f8fafc';
    document.getElementById('f_background_color_picker').value = d.background_color || '#f8fafc';
    document.getElementById('f_title').value = d.title || '';
    document.getElementById('f_desc').value = d.description || '';
    document.getElementById('f_sort').value = d.sort_order || 0;
    document.getElementById('f_status').value = d.status || 0;

    const imagePreview = document.getElementById('imagePreview');
    const videoPreview = document.getElementById('videoPreview');

    if (d.background_image) {
        imagePreview.src = '<?= SITE_URL ?>/' + d.background_image;
        imagePreview.style.display = 'block';
    } else {
        imagePreview.style.display = 'none';
    }

    if (d.background_video) {
        videoPreview.src = '<?= SITE_URL ?>/' + d.background_video;
        videoPreview.style.display = 'block';
    } else {
        videoPreview.style.display = 'none';
    }

    try {
        const feats = JSON.parse(d.features || '[]');
        document.getElementById('f_features').value = Array.isArray(feats) ? feats.join('\n') : '';
    } catch (e) {
        document.getElementById('f_features').value = '';
    }

    toggleStageMediaFields();
    modal.classList.add('open');
}

function toggleStageMediaFields() {
    const type = document.getElementById('f_background_type').value;

    document.querySelectorAll('.media-field').forEach(field => {
        field.classList.remove('active');
    });

    if (type === 'image') {
        document.getElementById('media_image').classList.add('active');
    } else if (type === 'video') {
        document.getElementById('media_video').classList.add('active');
    } else {
        document.getElementById('media_color').classList.add('active');
    }
}

function syncStageColorPicker() {
    document.getElementById('f_background_color').value = document.getElementById('f_background_color_picker').value;
}

function syncStageColorText() {
    const text = document.getElementById('f_background_color').value;
    if (/^#[0-9A-F]{6}$/i.test(text)) {
        document.getElementById('f_background_color_picker').value = text;
    }
}

function syncBannerOverlay() {
    const range = document.getElementById('bannerOverlayRange');
    const input = document.getElementById('bannerOverlayInput');
    input.value = range.value;
}

function syncBannerOverlayInput() {
    const range = document.getElementById('bannerOverlayRange');
    const input = document.getElementById('bannerOverlayInput');
    range.value = input.value;
}

<?php if ($edit): ?>
editStage(<?= json_encode($edit) ?>);
<?php endif; ?>
</script>
</body>
</html>
