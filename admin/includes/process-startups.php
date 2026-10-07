<?php
require_once '../../includes/config.php';
require_once 'auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_startups(int $cohortId = 0): void
{
    $url = '../startups.php';

    if ($cohortId > 0) {
        $url .= '?cohort_id=' . $cohortId;
    }

    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_startups();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $id            = (int)($_POST['id'] ?? 0);
    $cohort_id     = (int)($_POST['cohort_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $tagline       = trim($_POST['tagline'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $website       = trim($_POST['website'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $sector        = trim($_POST['sector'] ?? '');
    $stage         = trim($_POST['stage'] ?? '');
    $location      = trim($_POST['location'] ?? 'Uganda');
    $founded_year  = trim($_POST['founded_year'] ?? '');
    $impact_metric = trim($_POST['impact_metric'] ?? '');
    $status        = trim($_POST['status'] ?? 'active');
    $sort_order    = (int)($_POST['sort_order'] ?? 0);

    $allowedStatuses = ['active', 'draft', 'inactive'];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'active';
    }

    if ($name === '' || $cohort_id <= 0) {
        flash('startups', 'Startup name and cohort are required.', 'error');
        redirect_startups($cohort_id);
    }

    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        flash('startups', 'Enter a valid website URL.', 'error');
        redirect_startups($cohort_id);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('startups', 'Enter a valid email address.', 'error');
        redirect_startups($cohort_id);
    }

    $foundedYearValue = null;

    if ($founded_year !== '') {
        $foundedYearValue = (int)$founded_year;

        if ($foundedYearValue < 1900 || $foundedYearValue > 2100) {
            flash('startups', 'Enter a valid founded year.', 'error');
            redirect_startups($cohort_id);
        }
    }

    $logo_path = trim($_POST['existing_logo'] ?? '');
    $feat_path = trim($_POST['existing_featured'] ?? '');

    $err = '';

    if (function_exists('upload_image')) {
        $new_logo = upload_image('logo', 'startups', $err);

        if ($new_logo) {
            if ($logo_path !== '' && function_exists('delete_image')) {
                delete_image($logo_path);
            }

            $logo_path = $new_logo;
        }

        $new_feat = upload_image('featured_image', 'startups', $err);

        if ($new_feat) {
            if ($feat_path !== '' && function_exists('delete_image')) {
                delete_image($feat_path);
            }

            $feat_path = $new_feat;
        }
    }

    $slugBase = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $slugBase = trim($slugBase, '-');

    if ($slugBase === '') {
        $slugBase = 'startup';
    }

    $slug = function_exists('unique_slug')
        ? unique_slug($conn, 'startups', 'slug', $slugBase, $id > 0 ? $id : null)
        : $slugBase;

    if ($id > 0) {
        $stmt = $conn->prepare("
            UPDATE startups
            SET cohort_id = ?,
                name = ?,
                slug = ?,
                tagline = ?,
                description = ?,
                logo = ?,
                featured_image = ?,
                website = ?,
                email = ?,
                phone = ?,
                sector = ?,
                stage = ?,
                location = ?,
                founded_year = ?,
                impact_metric = ?,
                status = ?,
                sort_order = ?
            WHERE id = ?
        ");

        if (!$stmt) {
            flash('startups', 'Database error: ' . $conn->error, 'error');
            redirect_startups($cohort_id);
        }

        $stmt->bind_param(
            "issssssssssssissii",
            $cohort_id,
            $name,
            $slug,
            $tagline,
            $description,
            $logo_path,
            $feat_path,
            $website,
            $email,
            $phone,
            $sector,
            $stage,
            $location,
            $foundedYearValue,
            $impact_metric,
            $status,
            $sort_order,
            $id
        );

        if ($stmt->execute()) {
            flash('startups', 'Startup updated successfully.');
        } else {
            flash('startups', 'Failed to update startup: ' . $stmt->error, 'error');
        }

        $stmt->close();
        redirect_startups($cohort_id);
    }

    $stmt = $conn->prepare("
        INSERT INTO startups (
            cohort_id,
            name,
            slug,
            tagline,
            description,
            logo,
            featured_image,
            website,
            email,
            phone,
            sector,
            stage,
            location,
            founded_year,
            impact_metric,
            status,
            sort_order
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        flash('startups', 'Database error: ' . $conn->error, 'error');
        redirect_startups($cohort_id);
    }

    $stmt->bind_param(
        "issssssssssssissi",
        $cohort_id,
        $name,
        $slug,
        $tagline,
        $description,
        $logo_path,
        $feat_path,
        $website,
        $email,
        $phone,
        $sector,
        $stage,
        $location,
        $foundedYearValue,
        $impact_metric,
        $status,
        $sort_order
    );

    if ($stmt->execute()) {
        flash('startups', 'Startup created successfully.');
    } else {
        flash('startups', 'Failed to create startup: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_startups($cohort_id);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('startups', 'Invalid startup selected.', 'error');
        redirect_startups();
    }

    $stmt = $conn->prepare("SELECT logo, featured_image FROM startups WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $startup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($startup) {
        if (!empty($startup['logo']) && function_exists('delete_image')) {
            delete_image($startup['logo']);
        }

        if (!empty($startup['featured_image']) && function_exists('delete_image')) {
            delete_image($startup['featured_image']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM startups WHERE id = ? LIMIT 1");

    if (!$stmt) {
        flash('startups', 'Database error: ' . $conn->error, 'error');
        redirect_startups();
    }

    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        flash('startups', 'Startup deleted successfully.');
    } else {
        flash('startups', 'Failed to delete startup: ' . $stmt->error, 'error');
    }

    $stmt->close();
    redirect_startups();
}

flash('startups', 'Invalid request action.', 'error');
redirect_startups();