<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_cohorts(): void
{
    header('Location: ../cohorts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_cohorts();
}

$action = $_POST['action'] ?? '';

// --------------------------------------------------------------
//  SAVE  (insert or update)
// --------------------------------------------------------------
if ($action === 'save') {

    // -- Core fields ------------------------------------------
    $id                 = (int)($_POST['id'] ?? 0);
    $name               = trim($_POST['name'] ?? '');
    $tagline            = trim($_POST['tagline'] ?? '');
    $description        = trim($_POST['description'] ?? '');
    $start_date         = trim($_POST['start_date'] ?? '');
    $end_date           = trim($_POST['end_date'] ?? '');
    $application_status = trim($_POST['application_status'] ?? 'closed');
    $application_link   = trim($_POST['application_link'] ?? '');
    $status             = trim($_POST['status'] ?? 'active');
    $sort_order         = (int)($_POST['sort_order'] ?? 0);

    $allowed_statuses = ['active', 'draft', 'inactive'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'active';
    }

    $allowed_app_statuses = ['closed', 'open', 'coming_soon'];
    if (!in_array($application_status, $allowed_app_statuses, true)) {
        $application_status = 'closed';
    }

    if ($name === '') {
        flash('cohorts', 'Cohort name is required.', 'error');
        redirect_cohorts();
    }

    if ($application_link !== '' && !filter_var($application_link, FILTER_VALIDATE_URL)) {
        flash('cohorts', 'Enter a valid application link URL.', 'error');
        redirect_cohorts();
    }

    // -- Content image uploads --------------------------------
    $image  = trim($_POST['existing_image']  ?? '');
    $banner = trim($_POST['existing_banner'] ?? '');

    $upload_err = '';

    if (function_exists('upload_image')) {
        $new_img = upload_image('image', 'cohorts', $upload_err);
        if ($new_img) {
            if ($image !== '' && function_exists('delete_image')) {
                delete_image($image);
            }
            $image = $new_img;
        }

        $new_banner = upload_image('banner_image', 'cohorts', $upload_err);
        if ($new_banner) {
            if ($banner !== '' && function_exists('delete_image')) {
                delete_image($banner);
            }
            $banner = $new_banner;
        }
    }

    // -- Background type --------------------------------------
    $allowed_bg_types = ['none', 'color', 'image', 'video'];
    $bg_type = trim($_POST['bg_type'] ?? 'none');
    if (!in_array($bg_type, $allowed_bg_types, true)) {
        $bg_type = 'none';
    }

    // -- Background colour ------------------------------------
    $bg_color_raw = trim($_POST['bg_color'] ?? '#ffffff');
    $bg_color     = preg_match('/^#[0-9A-Fa-f]{6}$/', $bg_color_raw)
                    ? $bg_color_raw
                    : '#ffffff';

    // -- Overlay settings -------------------------------------
    $bg_overlay         = ($_POST['bg_overlay'] ?? '0') === '1' ? 1 : 0;
    $bg_overlay_color_r = trim($_POST['bg_overlay_color'] ?? '#000000');
    $bg_overlay_color   = preg_match('/^#[0-9A-Fa-f]{6}$/', $bg_overlay_color_r)
                          ? $bg_overlay_color_r
                          : '#000000';
    $bg_overlay_opacity = max(0, min(100, (int)($_POST['bg_overlay_opacity'] ?? 40)));

    // -- Background image upload ------------------------------
    // Start from whatever was already stored (carried via hidden field)
    $bg_image = trim($_POST['existing_bg_image'] ?? '');

    if (!empty($_FILES['bg_image_file']['name'])) {
        if (function_exists('upload_image')) {
            $new_bg_img = upload_image('bg_image_file', 'cohorts/backgrounds', $upload_err);
            if ($new_bg_img) {
                // Delete old bg image if replaced
                if ($bg_image !== '' && function_exists('delete_image')) {
                    delete_image($bg_image);
                }
                $bg_image = $new_bg_img;
            } elseif ($upload_err !== '') {
                flash('cohorts', 'Background image upload failed: ' . $upload_err, 'error');
                redirect_cohorts();
            }
        } else {
            // Fallback manual upload when upload_image() not available
            $ext = strtolower(pathinfo($_FILES['bg_image_file']['name'], PATHINFO_EXTENSION));
            $allowed_img_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed_img_exts, true)) {
                $dest_dir = rtrim(UPLOAD_PATH, '/') . '/cohorts/backgrounds/';
                if (!is_dir($dest_dir)) {
                    mkdir($dest_dir, 0755, true);
                }
                $filename = uniqid('cbg_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['bg_image_file']['tmp_name'], $dest_dir . $filename)) {
                    if ($bg_image !== '' && function_exists('delete_image')) {
                        delete_image($bg_image);
                    }
                    $bg_image = 'uploads/cohorts/backgrounds/' . $filename;
                }
            }
        }
    }

    // -- Background video upload ------------------------------
    $bg_video = trim($_POST['existing_bg_video'] ?? '');

    if (!empty($_FILES['bg_video_file']['name'])) {
        $ext = strtolower(pathinfo($_FILES['bg_video_file']['name'], PATHINFO_EXTENSION));
        $allowed_video_exts = ['mp4', 'webm', 'ogg'];

        if (!in_array($ext, $allowed_video_exts, true)) {
            flash('cohorts', 'Background video must be MP4, WebM, or OGG.', 'error');
            redirect_cohorts();
        }

        // Size guard - 50 MB hard limit
        $max_video_bytes = 50 * 1024 * 1024;
        if ($_FILES['bg_video_file']['size'] > $max_video_bytes) {
            flash('cohorts', 'Background video must be under 50 MB.', 'error');
            redirect_cohorts();
        }

        $dest_dir = rtrim(UPLOAD_PATH, '/') . '/cohorts/backgrounds/';
        if (!is_dir($dest_dir)) {
            mkdir($dest_dir, 0755, true);
        }

        $filename = uniqid('cbgvid_', true) . '.' . $ext;

        if (move_uploaded_file($_FILES['bg_video_file']['tmp_name'], $dest_dir . $filename)) {
            // Delete old video file from disk
            if ($bg_video !== '') {
                $old_path = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/') . '/' . ltrim($bg_video, '/');
                if (file_exists($old_path)) {
                    @unlink($old_path);
                }
            }
            $bg_video = 'uploads/cohorts/backgrounds/' . $filename;
        } else {
            flash('cohorts', 'Background video upload failed. Check folder permissions.', 'error');
            redirect_cohorts();
        }
    }

    // -- If bg_type changed away from image/video, optionally
    //    clear orphaned files to keep disk tidy ---------------
    if ($bg_type !== 'image' && $bg_image !== '' && isset($_POST['existing_bg_image'])
        && $_POST['existing_bg_image'] === '' /* user explicitly removed it */) {
        if (function_exists('delete_image')) {
            delete_image($bg_image);
        }
        $bg_image = '';
    }

    if ($bg_type !== 'video' && $bg_video !== '' && isset($_POST['existing_bg_video'])
        && $_POST['existing_bg_video'] === '') {
        $old_path = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/') . '/' . ltrim($bg_video, '/');
        if (file_exists($old_path)) {
            @unlink($old_path);
        }
        $bg_video = '';
    }

    // -- Slug -------------------------------------------------
    $slug_base = function_exists('slugify')
        ? slugify($name)
        : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

    if ($slug_base === '') {
        $slug_base = 'cohort';
    }

    $slug = function_exists('unique_slug')
        ? unique_slug($conn, 'cohorts', 'slug', $slug_base, $id > 0 ? $id : null)
        : $slug_base;

    // -- Nullable dates ----------------------------------------
    $start_date_val = $start_date !== '' ? $start_date : null;
    $end_date_val   = $end_date   !== '' ? $end_date   : null;

    // ----------------------------------------------------------
    //  UPDATE
    // ----------------------------------------------------------
    if ($id > 0) {

        $stmt = $conn->prepare("
            UPDATE cohorts
            SET name                = ?,
                slug                = ?,
                tagline             = ?,
                description         = ?,
                start_date          = ?,
                end_date            = ?,
                application_status  = ?,
                application_link    = ?,
                image               = ?,
                banner_image        = ?,
                status              = ?,
                sort_order          = ?,
                bg_type             = ?,
                bg_color            = ?,
                bg_image            = ?,
                bg_video            = ?,
                bg_overlay          = ?,
                bg_overlay_color    = ?,
                bg_overlay_opacity  = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('cohorts', 'Database error: ' . $conn->error, 'error');
            redirect_cohorts();
        }

        $stmt->bind_param(
            'sssssssssssissssiisi',
            $name,
            $slug,
            $tagline,
            $description,
            $start_date_val,
            $end_date_val,
            $application_status,
            $application_link,
            $image,
            $banner,
            $status,
            $sort_order,
            $bg_type,
            $bg_color,
            $bg_image,
            $bg_video,
            $bg_overlay,
            $bg_overlay_color,
            $bg_overlay_opacity,
            $id
        );

        if ($stmt->execute()) {
            flash('cohorts', 'Cohort updated successfully.');
        } else {
            flash('cohorts', 'Failed to update cohort: ' . $stmt->error, 'error');
        }

        $stmt->close();
        redirect_cohorts();
    }

    // ----------------------------------------------------------
    //  INSERT
    // ----------------------------------------------------------
    $stmt = $conn->prepare("
        INSERT INTO cohorts (
            name,
            slug,
            tagline,
            description,
            start_date,
            end_date,
            application_status,
            application_link,
            image,
            banner_image,
            status,
            sort_order,
            bg_type,
            bg_color,
            bg_image,
            bg_video,
            bg_overlay,
            bg_overlay_color,
            bg_overlay_opacity
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('cohorts', 'Database error: ' . $conn->error, 'error');
        redirect_cohorts();
    }

    $stmt->bind_param(
        'sssssssssssissssiis',
        $name,
        $slug,
        $tagline,
        $description,
        $start_date_val,
        $end_date_val,
        $application_status,
        $application_link,
        $image,
        $banner,
        $status,
        $sort_order,
        $bg_type,
        $bg_color,
        $bg_image,
        $bg_video,
        $bg_overlay,
        $bg_overlay_color,
        $bg_overlay_opacity
    );

    if ($stmt->execute()) {
        flash('cohorts', 'Cohort created successfully.');
    } else {
        flash('cohorts', 'Failed to create cohort: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_cohorts();
}

// --------------------------------------------------------------
//  DELETE
// --------------------------------------------------------------
if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('cohorts', 'Invalid cohort selected.', 'error');
        redirect_cohorts();
    }

    // Fetch file paths before deletion so we can clean up disk
    $stmt = $conn->prepare("
        SELECT image, banner_image, bg_image, bg_video
        FROM cohorts
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        flash('cohorts', 'Database error: ' . $conn->error, 'error');
        redirect_cohorts();
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cohort = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($cohort) {
        // Content images
        foreach (['image', 'banner_image'] as $col) {
            if (!empty($cohort[$col]) && function_exists('delete_image')) {
                delete_image($cohort[$col]);
            }
        }

        // Background image
        if (!empty($cohort['bg_image']) && function_exists('delete_image')) {
            delete_image($cohort['bg_image']);
        }

        // Background video (manual unlink - no helper for videos)
        if (!empty($cohort['bg_video'])) {
            $vid_path = rtrim(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2), '/')
                      . '/' . ltrim($cohort['bg_video'], '/');
            if (file_exists($vid_path)) {
                @unlink($vid_path);
            }
        }
    }

    $stmt = $conn->prepare("DELETE FROM cohorts WHERE id = ? LIMIT 1");

    if (!$stmt) {
        flash('cohorts', 'Database error: ' . $conn->error, 'error');
        redirect_cohorts();
    }

    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        flash('cohorts', 'Cohort deleted successfully.');
    } else {
        flash('cohorts', 'Failed to delete cohort: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_cohorts();
}

// -- Unrecognised action ---------------------------------------
flash('cohorts', 'Invalid request action.', 'error');
redirect_cohorts();