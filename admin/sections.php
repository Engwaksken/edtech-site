<?php
require_once '../includes/config.php';
require_once 'includes/auth.php';

/* ── Save settings ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = $_POST['keys'] ?? [];
    $vals = $_POST['vals'] ?? [];

    foreach ($keys as $i => $key) {
        $key = trim((string)$key);
        if ($key === '') continue;

        $k = esc($conn, $key);
        $v = esc($conn, $vals[$i] ?? '');

        mysqli_query($conn,
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES('$k', '$v')
             ON DUPLICATE KEY UPDATE setting_value='$v'"
        );
    }

    $err = '';

    $image_uploads = [
        'hero_image_file'        => 'hero_image',
        'about_image_file'       => 'about_image',
        'eligibility_image_file' => 'eligibility_image',
        'about_card_1_bg_file'   => 'about_card_1_bg',
        'about_card_2_bg_file'   => 'about_card_2_bg',
        'about_card_3_bg_file'   => 'about_card_3_bg',
        'about_card_4_bg_file'   => 'about_card_4_bg',
    ];

    foreach ($image_uploads as $file_key => $setting_key) {
        $new_img = upload_image($file_key, 'settings', $err);
        if ($new_img) {
            $p = esc($conn, $new_img);
            mysqli_query($conn,
                "INSERT INTO site_settings (setting_key, setting_value)
                 VALUES('$setting_key', '$p')
                 ON DUPLICATE KEY UPDATE setting_value='$p'"
            );
        }
    }

    $bg_image_uploads = [
        'general_bg_image_file'     => 'general_bg_image',
        'hero_bg_image_file'        => 'hero_bg_image',
        'about_bg_image_file'       => 'about_bg_image',
        'eligibility_bg_image_file' => 'eligibility_bg_image',
        'partners_bg_image_file'    => 'partners_bg_image',
        'process_bg_image_file'     => 'process_bg_image',
        'contact_bg_image_file'     => 'contact_bg_image',
        'cohorts_sec_bg_image_file' => 'cohorts_sec_bg_image',
    ];

    foreach ($bg_image_uploads as $file_key => $setting_key) {
        $new_img = upload_image($file_key, 'settings/backgrounds', $err);
        if ($new_img) {
            $p = esc($conn, $new_img);
            mysqli_query($conn,
                "INSERT INTO site_settings (setting_key, setting_value)
                 VALUES('$setting_key', '$p')
                 ON DUPLICATE KEY UPDATE setting_value='$p'"
            );
        }
    }

    $video_uploads = [
        'hero_bg_video_file'        => 'hero_bg_video',
        'about_bg_video_file'       => 'about_bg_video',
        'eligibility_bg_video_file' => 'eligibility_bg_video',
        'partners_bg_video_file'    => 'partners_bg_video',
        'process_bg_video_file'     => 'process_bg_video',
        'contact_bg_video_file'     => 'contact_bg_video',
        'cohorts_sec_bg_video_file' => 'cohorts_sec_bg_video',
    ];

    foreach ($video_uploads as $file_key => $setting_key) {
        if (!empty($_FILES[$file_key]['name'])) {
            $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));

            if (in_array($ext, ['mp4', 'webm', 'ogg'], true)) {
                $dest_dir = UPLOAD_PATH . '/settings/backgrounds/';
                if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

                $filename = uniqid('vid_', true) . '.' . $ext;

                if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $dest_dir . $filename)) {
                    $rel_path = 'uploads/settings/backgrounds/' . $filename;
                    $p = esc($conn, $rel_path);

                    mysqli_query($conn,
                        "INSERT INTO site_settings (setting_key, setting_value)
                         VALUES('$setting_key', '$p')
                         ON DUPLICATE KEY UPDATE setting_value='$p'"
                    );
                }
            }
        }
    }

    flash('sections', 'Page sections updated successfully.');
    header('Location: sections.php' . (isset($_POST['tab']) ? '?tab=' . urlencode($_POST['tab']) : ''));
    exit;
}

/* ── Load settings ───────────────────────────────────────────── */
$all_settings = [];
$r = mysqli_query($conn, "SELECT setting_key, setting_value FROM site_settings");
while ($row = mysqli_fetch_assoc($r)) {
    $all_settings[$row['setting_key']] = $row['setting_value'];
}

function setting(array $all, string $key, string $default = ''): string
{
    return $all[$key] ?? $default;
}

$active_tab = $_GET['tab'] ?? 'general';
$site_name  = setting($all_settings, 'site_name', 'EdTech Fellowship');

function render_bg_panel(string $section, array $all, bool $has_video = true): void
{
    $type        = setting($all, $section . '_bg_type', 'color');
    $color       = setting($all, $section . '_bg_color', '#ffffff');
    $overlay_on  = setting($all, $section . '_bg_overlay', '0');
    $overlay_col = setting($all, $section . '_bg_overlay_color', '#000000');
    $overlay_op  = setting($all, $section . '_bg_overlay_opacity', '30');
    $bg_image    = setting($all, $section . '_bg_image', '');
    $bg_video    = setting($all, $section . '_bg_video', '');
    $panel_id    = 'bp_' . $section;
    ?>

    <input type="hidden" name="keys[]" value="<?= $section ?>_bg_image">
    <input type="hidden" name="vals[]" value="<?= h($bg_image) ?>" id="<?= $panel_id ?>_img_val">

    <?php if ($has_video): ?>
        <input type="hidden" name="keys[]" value="<?= $section ?>_bg_video">
        <input type="hidden" name="vals[]" value="<?= h($bg_video) ?>" id="<?= $panel_id ?>_vid_val">
    <?php endif; ?>

    <div class="bg-panel">
        <div class="bg-panel-header" id="<?= $panel_id ?>_header" onclick="toggleBpPanel('<?= $panel_id ?>')">
            <div class="bp-icon"><i class="fa fa-paint-brush"></i></div>
            <h4>Section Background</h4>

            <?php if ($type !== 'color' || $bg_image || $bg_video): ?>
                <span class="legacy-style-9b0c6e1b4f">
                    <?= strtoupper(h($type)) ?>
                </span>
            <?php endif; ?>

            <i class="fa fa-chevron-down bp-toggle"></i>
        </div>

        <div class="bg-panel-body" id="<?= $panel_id ?>_body">

            <div class="bg-type-pills">
                <button type="button" class="bg-type-pill <?= $type === 'color' ? 'active' : '' ?>"
                        onclick="setBgType('<?= $panel_id ?>','color')">
                    <i class="fa fa-palette"></i> Solid Color
                </button>

                <button type="button" class="bg-type-pill <?= $type === 'image' ? 'active' : '' ?>"
                        onclick="setBgType('<?= $panel_id ?>','image')">
                    <i class="fa fa-image"></i> Image
                </button>

                <?php if ($has_video): ?>
                    <button type="button" class="bg-type-pill <?= $type === 'video' ? 'active' : '' ?>"
                            onclick="setBgType('<?= $panel_id ?>','video')">
                        <i class="fa fa-film"></i> Video
                    </button>
                <?php endif; ?>
            </div>

            <input type="hidden" name="keys[]" value="<?= $section ?>_bg_type">
            <input type="hidden" name="vals[]" value="<?= h($type) ?>" id="<?= $panel_id ?>_type_val">

            <div class="bg-sub <?= $type === 'color' ? 'active' : '' ?>" id="<?= $panel_id ?>_sub_color">
                <input type="hidden" name="keys[]" value="<?= $section ?>_bg_color">

                <div class="color-row">
                    <div class="color-swatch-wrap">
                        <input type="color" value="<?= h($color) ?>" id="<?= $panel_id ?>_colorpicker"
                               oninput="syncColor('<?= $panel_id ?>')">

                        <input type="text" class="form-control color-hex-input"
                               id="<?= $panel_id ?>_colorhex" name="vals[]"
                               value="<?= h($color) ?>"
                               placeholder="#ffffff"
                               oninput="syncColorHex('<?= $panel_id ?>')">
                    </div>
                </div>

                <div class="bg-preview-strip" id="<?= $panel_id ?>_color_preview"
                     style="background:<?= h($color) ?>">
                    <span>Preview</span>
                </div>
            </div>

            <div class="bg-sub <?= $type === 'image' ? 'active' : '' ?>" id="<?= $panel_id ?>_sub_image">
                <div class="form-group">
                    <label>Upload Background Image</label>

                    <?php if ($bg_image): ?>
                        <div class="bg-preview-strip legacy-style-761d3addb2">
                            <img src="<?= SITE_URL . '/' . h($bg_image) ?>" alt="current bg">
                            <span>Current</span>
                        </div>

                        <button type="button" class="remove-bg-btn"
                                onclick="removeBg('<?= $panel_id ?>','image')">
                            <i class="fa fa-times"></i> Remove image
                        </button>
                    <?php endif; ?>

                    <input type="file" name="<?= $section ?>_bg_image_file" class="form-control"
                           accept="image/*" style="margin-top:8px"
                           onchange="previewBgImage('<?= $panel_id ?>',this)">

                    <span class="form-hint">Recommended: 1920×1080px or wider. JPG/PNG/WebP.</span>
                </div>

                <div class="bg-preview-strip" id="<?= $panel_id ?>_img_preview"
                     style="<?= $bg_image ? 'background:url(' . SITE_URL . '/' . h($bg_image) . ') center/cover' : '' ?>">
                    <span><?= $bg_image ? 'Current Image' : 'No image yet' ?></span>
                </div>
            </div>

            <?php if ($has_video): ?>
                <div class="bg-sub <?= $type === 'video' ? 'active' : '' ?>" id="<?= $panel_id ?>_sub_video">
                    <div class="form-group">
                        <label>Upload Background Video</label>

                        <?php if ($bg_video): ?>
                            <div class="bg-preview-strip legacy-style-761d3addb2">
                                <video autoplay muted loop playsinline>
                                    <source src="<?= SITE_URL . '/' . h($bg_video) ?>">
                                </video>
                                <span>Current Video</span>
                            </div>

                            <button type="button" class="remove-bg-btn"
                                    onclick="removeBg('<?= $panel_id ?>','video')">
                                <i class="fa fa-times"></i> Remove video
                            </button>
                        <?php endif; ?>

                        <input type="file" name="<?= $section ?>_bg_video_file" class="form-control"
                               accept="video/mp4,video/webm,video/ogg" style="margin-top:8px">

                        <span class="form-hint">MP4 / WebM / OGG. Keep under 20 MB for best performance.</span>
                    </div>

                    <?php if (!$bg_video): ?>
                        <div class="bg-preview-strip" id="<?= $panel_id ?>_vid_preview">
                            <span>No video yet</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="<?= $panel_id ?>_overlay_row"
                 style="margin-top:16px;<?= $type === 'color' ? 'display:none' : '' ?>">

                <input type="hidden" name="keys[]" value="<?= $section ?>_bg_overlay">
                <input type="hidden"
                       name="vals[]"
                       value="<?= $overlay_on === '1' ? '1' : '0' ?>"
                       id="<?= $panel_id ?>_overlay_val">

                <div class="legacy-style-8d446200bc">
                    <label class="legacy-style-fd2ab992c8">
                        <input type="checkbox"
                               id="<?= $panel_id ?>_overlay_cb"
                               <?= $overlay_on === '1' ? 'checked' : '' ?>
                               onchange="toggleOverlayOpts('<?= $panel_id ?>')">
                        Enable colour overlay (darkens/tints the background)
                    </label>
                </div>

                <div id="<?= $panel_id ?>_overlay_opts"
                     style="display:<?= $overlay_on === '1' ? 'flex' : 'none' ?>;gap:14px;align-items:center;flex-wrap:wrap">

                    <input type="hidden" name="keys[]" value="<?= $section ?>_bg_overlay_color">

                    <div class="color-swatch-wrap">
                        <input type="color"
                               value="<?= h($overlay_col) ?>"
                               id="<?= $panel_id ?>_ov_colorpicker"
                               oninput="syncOverlayColor('<?= $panel_id ?>')">

                        <input type="text"
                               class="form-control color-hex-input"
                               id="<?= $panel_id ?>_ov_colorhex"
                               name="vals[]"
                               value="<?= h($overlay_col) ?>"
                               placeholder="#000000"
                               oninput="syncOverlayColorHex('<?= $panel_id ?>')">
                    </div>

                    <input type="hidden" name="keys[]" value="<?= $section ?>_bg_overlay_opacity">

                    <div class="legacy-style-f6772bbeea">
                        <label class="legacy-style-14290e4dac">Opacity</label>

                        <input type="range"
                               min="0"
                               max="100"
                               step="1"
                               value="<?= h($overlay_op) ?>"
                               id="<?= $panel_id ?>_ov_range"
                               oninput="syncOverlayOpacity('<?= $panel_id ?>')"
                               style="flex:1">

                        <input type="number"
                               name="vals[]"
                               class="form-control"
                               id="<?= $panel_id ?>_ov_num"
                               min="0"
                               max="100"
                               value="<?= h($overlay_op) ?>"
                               style="width:60px"
                               oninput="syncOverlayOpacityNum('<?= $panel_id ?>')">

                        <span class="legacy-style-5e0faad207">%</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/../includes/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Sections - <?= h($site_name) ?> Admin</title>
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
                <a href="index.php">Dashboard</a> &gt; <strong>Page Sections</strong>
            </div>
        </div>

        <div class="topbar-right">
            <a href="<?= SITE_URL ?>" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fa fa-eye"></i> View Site
            </a>

            <div class="admin-avatar">
                <div class="avatar-circle"><?= strtoupper(substr($ADMIN['full_name'], 0, 1)) ?></div>
            </div>
        </div>
    </header>

    <div class="admin-content">
        <?php show_flash('sections'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Page Sections</h1>
                <p class="page-subtitle">Edit all homepage and site-wide content</p>
            </div>
        </div>

        <div class="tabs">
            <?php
            $tabs = [
                'general'     => ['icon' => 'fa-cog', 'label' => 'General'],
                'hero'        => ['icon' => 'fa-home', 'label' => 'Hero Section'],
                'about'       => ['icon' => 'fa-info-circle', 'label' => 'About'],
                'eligibility' => ['icon' => 'fa-check-circle', 'label' => 'Eligibility'],
                'partners'    => ['icon' => 'fa-handshake', 'label' => 'Partners'],
                'process'     => ['icon' => 'fa-route', 'label' => 'Process'],
                'contact'     => ['icon' => 'fa-envelope', 'label' => 'Contact'],
                'cohorts_sec' => ['icon' => 'fa-layer-group', 'label' => 'Cohorts Section'],
            ];

            foreach ($tabs as $k => $t):
            ?>
                <button class="tab-btn <?= $active_tab === $k ? 'active' : '' ?>" onclick="switchTab('<?= $k ?>', this)">
                    <i class="fa <?= $t['icon'] ?>"></i> <?= h($t['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div id="tab-general" class="tab-content <?= $active_tab === 'general' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="general">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">General Site Settings</h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <?php
                            $general_fields = [
                                'site_name'     => ['label' => 'Site Name', 'type' => 'text', 'placeholder' => 'Mastercard Foundation EdTech Fellowship'],
                                'site_tagline'  => ['label' => 'Site Tagline', 'type' => 'text'],
                                'site_email'    => ['label' => 'Contact Email', 'type' => 'email'],
                                'site_location' => ['label' => 'Location', 'type' => 'text'],
                                'site_phone'    => ['label' => 'Phone Number', 'type' => 'text'],
                            ];

                            foreach ($general_fields as $key => $field):
                            ?>
                                <input type="hidden" name="keys[]" value="<?= $key ?>">

                                <div class="form-group">
                                    <label><?= h($field['label']) ?></label>
                                    <input type="<?= $field['type'] ?>"
                                           name="vals[]"
                                           class="form-control"
                                           value="<?= h(setting($all_settings, $key)) ?>"
                                           placeholder="<?= h($field['placeholder'] ?? '') ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php render_bg_panel('general', $all_settings, false); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save General Settings
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-hero" class="tab-content <?= $active_tab === 'hero' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="hero">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-home legacy-style-ac3e88a561"></i> Hero Section
                        </h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <?php
                            $hero_fields = [
                                'hero_title'              => ['label' => 'Hero Title', 'type' => 'text', 'full' => true],
                                'hero_subtitle'           => ['label' => 'Subtitle / Lead Text', 'type' => 'text', 'full' => true],
                                'hero_description'        => ['label' => 'Description Paragraph', 'type' => 'textarea', 'full' => true],
                                'hero_application_status' => ['label' => 'Application Status', 'type' => 'select', 'options' => ['open' => 'Open', 'closed' => 'Closed', 'coming_soon' => 'Coming Soon']],
                                'hero_application_link'   => ['label' => 'Application Form Link', 'type' => 'url'],
                            ];

                            foreach ($hero_fields as $key => $f):
                                $full = !empty($f['full']) ? 'full' : '';
                            ?>
                                <input type="hidden" name="keys[]" value="<?= $key ?>">

                                <div class="form-group <?= $full ?>">
                                    <label><?= h($f['label']) ?></label>

                                    <?php if ($f['type'] === 'textarea'): ?>
                                        <textarea name="vals[]" class="form-control" rows="3"><?= h(setting($all_settings, $key)) ?></textarea>
                                    <?php elseif ($f['type'] === 'select'): ?>
                                        <select name="vals[]" class="form-control">
                                            <?php foreach ($f['options'] as $ov => $ol): ?>
                                                <option value="<?= h($ov) ?>" <?= setting($all_settings, $key) === $ov ? 'selected' : '' ?>>
                                                    <?= h($ol) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?= $f['type'] ?>" name="vals[]" class="form-control" value="<?= h(setting($all_settings, $key)) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <input type="hidden" name="keys[]" value="hero_image">
                            <input type="hidden" name="vals[]" value="<?= h(setting($all_settings, 'hero_image')) ?>">

                            <div class="form-group">
                                <label>Hero Foreground / Overlay Image</label>

                                <?php $hi = setting($all_settings, 'hero_image'); ?>
                                <?php if ($hi): ?>
                                    <img src="<?= SITE_URL . '/' . h($hi) ?>" class="img-preview" style="margin-bottom:8px;display:block" alt="">
                                <?php endif; ?>

                                <input type="file" name="hero_image_file" class="form-control" accept="image/*">
                                <span class="form-hint">This is the decorative/content image in the hero, not the background.</span>
                            </div>
                        </div>

                        <?php render_bg_panel('hero', $all_settings); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Hero Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-about" class="tab-content <?= $active_tab === 'about' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="about">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-info-circle legacy-style-ac3e88a561"></i> About Section
                        </h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <?php
                            $about_fields = [
                                'about_tag'                    => ['label' => 'Section Tag (small label)', 'type' => 'text'],
                                'about_title'                  => ['label' => 'Section Title', 'type' => 'text'],
                                'about_description'            => ['label' => 'Intro Description', 'type' => 'textarea', 'full' => true],
                                'about_body'                   => ['label' => 'Main Body Text', 'type' => 'textarea', 'full' => true],
                                'program_overview_title'       => ['label' => 'Program Overview Title', 'type' => 'text', 'full' => true],
                                'program_overview_description' => ['label' => 'Program Overview Description', 'type' => 'textarea', 'full' => true],
                                'impact_title'                 => ['label' => 'Impact Sub-Title', 'type' => 'text', 'full' => true],
                                'impact_description'           => ['label' => 'Impact Description', 'type' => 'textarea', 'full' => true],
                            ];

                            foreach ($about_fields as $key => $f):
                                $full = !empty($f['full']) ? 'full' : '';
                            ?>
                                <input type="hidden" name="keys[]" value="<?= $key ?>">

                                <div class="form-group <?= $full ?>">
                                    <label><?= h($f['label']) ?></label>

                                    <?php if ($f['type'] === 'textarea'): ?>
                                        <textarea name="vals[]" class="form-control" rows="4"><?= h(setting($all_settings, $key)) ?></textarea>
                                    <?php else: ?>
                                        <input type="text" name="vals[]" class="form-control" value="<?= h(setting($all_settings, $key)) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <input type="hidden" name="keys[]" value="about_image">
                            <input type="hidden" name="vals[]" value="<?= h(setting($all_settings, 'about_image')) ?>">

                            <div class="form-group">
                                <label>About Section Image</label>

                                <?php $ai = setting($all_settings, 'about_image'); ?>
                                <?php if ($ai): ?>
                                    <img src="<?= SITE_URL . '/' . h($ai) ?>" class="img-preview" style="margin-bottom:8px;display:block" alt="">
                                <?php endif; ?>

                                <input type="file" name="about_image_file" class="form-control" accept="image/*">
                                <span class="form-hint">Main image displayed in the About Program two-column section.</span>
                            </div>

                            <div class="form-group full">
                                <div class="section-mini-heading">
                                    <i class="fa fa-th-large"></i>
                                    <div>
                                        <strong>About Bento Grid Card Backgrounds</strong>
                                        <small>Upload a separate background image for each feature card.</small>
                                    </div>
                                </div>
                            </div>

                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <?php $card_bg = setting($all_settings, 'about_card_' . $i . '_bg'); ?>

                                <input type="hidden" name="keys[]" value="about_card_<?= $i ?>_bg">
                                <input type="hidden" name="vals[]" value="<?= h($card_bg) ?>">

                                <div class="form-group about-card-bg-field">
                                    <label>About Grid Card <?= $i ?> Background Image</label>

                                    <div class="about-card-bg-preview <?= $card_bg ? 'has-image' : '' ?>"
                                         style="<?= $card_bg ? 'background-image:url(' . SITE_URL . '/' . h($card_bg) . ')' : '' ?>">
                                        <span>
                                            <i class="fa fa-image"></i>
                                            <?= $card_bg ? 'Current Card ' . $i . ' Image' : 'No Card ' . $i . ' Image' ?>
                                        </span>
                                    </div>

                                    <input type="file"
                                           name="about_card_<?= $i ?>_bg_file"
                                           class="form-control"
                                           accept="image/*"
                                           onchange="previewAboutCardBg(<?= $i ?>, this)">

                                    <span class="form-hint">Recommended: 900×500px or wider. JPG, PNG, or WebP.</span>
                                </div>
                            <?php endfor; ?>

                            <div class="form-group">
                                <label>Footer "About Hive Colab" Text</label>
                                <input type="hidden" name="keys[]" value="footer_about">
                                <textarea name="vals[]" class="form-control" rows="3"><?= h(setting($all_settings, 'footer_about')) ?></textarea>
                            </div>
                        </div>

                        <?php render_bg_panel('about', $all_settings); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save About Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-eligibility" class="tab-content <?= $active_tab === 'eligibility' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="eligibility">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Eligibility Section</h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <?php
                            $elig_fields = [
                                'eligibility_tag'         => ['label' => 'Section Tag', 'type' => 'text'],
                                'eligibility_description' => ['label' => 'Intro Description', 'type' => 'textarea', 'full' => true],
                                'eligibility_quote'       => ['label' => 'Highlighted Quote Text', 'type' => 'textarea', 'full' => true],
                            ];

                            foreach ($elig_fields as $key => $f):
                                $full = !empty($f['full']) ? 'full' : '';
                            ?>
                                <input type="hidden" name="keys[]" value="<?= $key ?>">

                                <div class="form-group <?= $full ?>">
                                    <label><?= h($f['label']) ?></label>

                                    <?php if ($f['type'] === 'textarea'): ?>
                                        <textarea name="vals[]" class="form-control" rows="3"><?= h(setting($all_settings, $key)) ?></textarea>
                                    <?php else: ?>
                                        <input type="text" name="vals[]" class="form-control" value="<?= h(setting($all_settings, $key)) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <input type="hidden" name="keys[]" value="eligibility_image">
                            <input type="hidden" name="vals[]" value="<?= h(setting($all_settings, 'eligibility_image')) ?>">

                            <div class="form-group">
                                <label>Eligibility Section Image</label>

                                <?php $ei = setting($all_settings, 'eligibility_image'); ?>
                                <?php if ($ei): ?>
                                    <img src="<?= SITE_URL . '/' . h($ei) ?>" class="img-preview" style="margin-bottom:8px;display:block" alt="">
                                <?php endif; ?>

                                <input type="file" name="eligibility_image_file" class="form-control" accept="image/*">
                            </div>
                        </div>

                        <?php render_bg_panel('eligibility', $all_settings); ?>

                        <div class="alert alert-info legacy-style-1b0f4999d2">
                            <i class="fa fa-info-circle"></i>
                            Individual eligibility criteria items are managed under
                            <a href="eligibility.php" class="legacy-style-eed0f8fb89">Eligibility Criteria</a>.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Eligibility Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-partners" class="tab-content <?= $active_tab === 'partners' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="partners">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Partners Section</h3>
                    </div>

                    <div class="card-body">
                        <input type="hidden" name="keys[]" value="partners_description">

                        <div class="form-group">
                            <label>Partners Section Description</label>
                            <textarea name="vals[]" class="form-control" rows="5"><?= h(setting($all_settings, 'partners_description')) ?></textarea>
                        </div>

                        <?php render_bg_panel('partners', $all_settings); ?>

                        <div class="alert alert-info legacy-style-1b0f4999d2">
                            <i class="fa fa-info-circle"></i>
                            Partner logos and links are managed under
                            <a href="partners.php" class="legacy-style-eed0f8fb89">Partners</a>.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Partners Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-process" class="tab-content <?= $active_tab === 'process' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="process">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-route legacy-style-ac3e88a561"></i>
                            Application Process Section
                        </h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <input type="hidden" name="keys[]" value="process_tag">

                            <div class="form-group">
                                <label>Section Tag</label>
                                <input type="text" name="vals[]" class="form-control"
                                       value="<?= h(setting($all_settings, 'process_tag', 'How to Apply')) ?>">
                            </div>

                            <input type="hidden" name="keys[]" value="process_description">

                            <div class="form-group">
                                <label>Section Description</label>
                                <input type="text" name="vals[]" class="form-control"
                                       value="<?= h(setting($all_settings, 'process_description', 'The application process is designed to be clear and selective.')) ?>">
                            </div>

                            <?php for ($i = 1; $i <= 3; $i++): ?>
                                <div class="form-group full">
                                    <div class="section-mini-heading">
                                        <i class="fa fa-list-ol"></i>
                                        <div>
                                            <strong>Process Step <?= $i ?></strong>
                                            <small>Edit the title and description displayed on the homepage timeline.</small>
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="keys[]" value="process_step_<?= $i ?>_title">

                                <div class="form-group">
                                    <label>Step <?= $i ?> Title</label>
                                    <input type="text" name="vals[]" class="form-control"
                                           value="<?= h(setting(
                                               $all_settings,
                                               'process_step_' . $i . '_title',
                                               $i === 1 ? 'Online Application' : ($i === 2 ? 'Shortlisting & Interviews' : 'Selection & Onboarding')
                                           )) ?>">
                                </div>

                                <input type="hidden" name="keys[]" value="process_step_<?= $i ?>_description">

                                <div class="form-group">
                                    <label>Step <?= $i ?> Description</label>
                                    <textarea name="vals[]" class="form-control" rows="3"><?= h(setting(
                                        $all_settings,
                                        'process_step_' . $i . '_description',
                                        $i === 1
                                            ? 'Complete the online form with details about your venture, traction, inclusion approach, and learning impact.'
                                            : ($i === 2 ? 'Selected ventures will be invited for interviews and readiness review.' : 'Successful ventures join the cohort and begin the fellowship journey.')
                                    )) ?></textarea>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <?php render_bg_panel('process', $all_settings); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Process Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-contact" class="tab-content <?= $active_tab === 'contact' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="contact">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Contact Section</h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <input type="hidden" name="keys[]" value="contact_description">

                            <div class="form-group full">
                                <label>Contact Section Description</label>
                                <textarea name="vals[]" class="form-control" rows="3"><?= h(setting($all_settings, 'contact_description')) ?></textarea>
                            </div>

                            <input type="hidden" name="keys[]" value="site_email">

                            <div class="form-group">
                                <label>Contact Email</label>
                                <input type="email" name="vals[]" class="form-control" value="<?= h(setting($all_settings, 'site_email')) ?>">
                            </div>

                            <input type="hidden" name="keys[]" value="site_location">

                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="vals[]" class="form-control" value="<?= h(setting($all_settings, 'site_location')) ?>">
                            </div>

                            <input type="hidden" name="keys[]" value="site_phone">

                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="vals[]" class="form-control" value="<?= h(setting($all_settings, 'site_phone')) ?>">
                            </div>
                        </div>

                        <?php render_bg_panel('contact', $all_settings); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Contact Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-cohorts_sec" class="tab-content <?= $active_tab === 'cohorts_sec' ? 'active' : '' ?>">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="tab" value="cohorts_sec">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Cohorts Section (Homepage)</h3>
                    </div>

                    <div class="card-body">
                        <div class="form-grid form-grid-2">
                            <input type="hidden" name="keys[]" value="show_cohorts_section">

                            <div class="form-group">
                                <label>Show Cohorts Section on Homepage?</label>
                                <select name="vals[]" class="form-control">
                                    <option value="1" <?= setting($all_settings, 'show_cohorts_section') === '1' ? 'selected' : '' ?>>Yes – Show it</option>
                                    <option value="0" <?= setting($all_settings, 'show_cohorts_section') === '0' ? 'selected' : '' ?>>No – Hide it</option>
                                </select>
                            </div>

                            <input type="hidden" name="keys[]" value="cohorts_section_title">

                            <div class="form-group">
                                <label>Section Title</label>
                                <input type="text" name="vals[]" class="form-control" value="<?= h(setting($all_settings, 'cohorts_section_title')) ?>">
                            </div>

                            <input type="hidden" name="keys[]" value="cohorts_section_description">

                            <div class="form-group full">
                                <label>Section Description</label>
                                <textarea name="vals[]" class="form-control" rows="3"><?= h(setting($all_settings, 'cohorts_section_description')) ?></textarea>
                            </div>
                        </div>

                        <?php render_bg_panel('cohorts_sec', $all_settings); ?>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save"></i> Save Cohorts Section
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
const sidebar = document.getElementById('adminSidebar');
const main = document.getElementById('adminMain');

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    sidebar?.classList.toggle('collapsed');
    main?.classList.toggle('collapsed');
});

function switchTab(id, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    document.getElementById('tab-' + id)?.classList.add('active');
    btn?.classList.add('active');

    history.replaceState(null, '', '?tab=' + encodeURIComponent(id));
}

function toggleBpPanel(pid) {
    document.getElementById(pid + '_header')?.classList.toggle('open');
    document.getElementById(pid + '_body')?.classList.toggle('open');
}

function setBgType(pid, type) {
    const typeVal = document.getElementById(pid + '_type_val');
    if (typeVal) typeVal.value = type;

    document.querySelectorAll('#' + pid + '_body .bg-type-pill').forEach(pill => {
        const onclick = pill.getAttribute('onclick') || '';
        pill.classList.toggle('active', onclick.includes("'" + type + "'"));
    });

    ['color', 'image', 'video'].forEach(t => {
        const el = document.getElementById(pid + '_sub_' + t);
        if (el) el.classList.toggle('active', t === type);
    });

    const overlayRow = document.getElementById(pid + '_overlay_row');
    if (overlayRow) overlayRow.style.display = type === 'color' ? 'none' : '';
}

function syncColor(pid) {
    const picker = document.getElementById(pid + '_colorpicker');
    const hex = document.getElementById(pid + '_colorhex');
    const preview = document.getElementById(pid + '_color_preview');

    if (!picker || !hex) return;

    hex.value = picker.value;
    if (preview) preview.style.background = picker.value;
}

function syncColorHex(pid) {
    const hex = document.getElementById(pid + '_colorhex');
    const picker = document.getElementById(pid + '_colorpicker');
    const preview = document.getElementById(pid + '_color_preview');

    if (!hex || !picker) return;

    if (/^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
        picker.value = hex.value;
        if (preview) preview.style.background = hex.value;
    }
}

function syncOverlayColor(pid) {
    const picker = document.getElementById(pid + '_ov_colorpicker');
    const hex = document.getElementById(pid + '_ov_colorhex');

    if (!picker || !hex) return;

    hex.value = picker.value;
}

function syncOverlayColorHex(pid) {
    const hex = document.getElementById(pid + '_ov_colorhex');
    const picker = document.getElementById(pid + '_ov_colorpicker');

    if (!hex || !picker) return;

    if (/^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
        picker.value = hex.value;
    }
}

function syncOverlayOpacity(pid) {
    const range = document.getElementById(pid + '_ov_range');
    const num = document.getElementById(pid + '_ov_num');

    if (!range || !num) return;

    num.value = range.value;
}

function syncOverlayOpacityNum(pid) {
    const range = document.getElementById(pid + '_ov_range');
    const num = document.getElementById(pid + '_ov_num');

    if (!range || !num) return;

    let value = parseInt(num.value || '0', 10);
    if (value < 0) value = 0;
    if (value > 100) value = 100;

    num.value = value;
    range.value = value;
}

function toggleOverlayOpts(pid) {
    const cb = document.getElementById(pid + '_overlay_cb');
    const opts = document.getElementById(pid + '_overlay_opts');
    const val = document.getElementById(pid + '_overlay_val');

    if (!cb) return;

    if (val) val.value = cb.checked ? '1' : '0';
    if (opts) opts.style.display = cb.checked ? 'flex' : 'none';
}

function previewBgImage(pid, input) {
    if (!input.files || !input.files[0]) return;

    const reader = new FileReader();

    reader.onload = function(e) {
        const preview = document.getElementById(pid + '_img_preview');

        if (preview) {
            preview.style.background = 'url(' + e.target.result + ') center/cover';

            const span = preview.querySelector('span');
            if (span) span.textContent = 'New Image';
        }
    };

    reader.readAsDataURL(input.files[0]);
}

function removeBg(pid, type) {
    if (!confirm('Remove current ' + (type === 'image' ? 'background image' : 'background video') + '?')) return;

    if (type === 'image') {
        const imgVal = document.getElementById(pid + '_img_val');
        if (imgVal) imgVal.value = '';

        const preview = document.getElementById(pid + '_img_preview');
        if (preview) {
            preview.style.background = '';
            const span = preview.querySelector('span');
            if (span) span.textContent = 'No image yet';
        }
    } else {
        const vidVal = document.getElementById(pid + '_vid_val');
        if (vidVal) vidVal.value = '';

        const preview = document.getElementById(pid + '_vid_preview');
        if (preview) {
            const span = preview.querySelector('span');
            if (span) span.textContent = 'No video yet';
        }
    }
}

function previewAboutCardBg(cardNo, input) {
    if (!input.files || !input.files[0]) return;

    const reader = new FileReader();

    reader.onload = function(e) {
        const field = input.closest('.about-card-bg-field');
        const preview = field ? field.querySelector('.about-card-bg-preview') : null;

        if (preview) {
            preview.classList.add('has-image');
            preview.style.backgroundImage = 'url(' + e.target.result + ')';

            const span = preview.querySelector('span');
            if (span) {
                span.innerHTML = '<i class="fa fa-image"></i> New Card ' + cardNo + ' Image';
            }
        }
    };

    reader.readAsDataURL(input.files[0]);
}
</script>

</body>
</html>
