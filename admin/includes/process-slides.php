<?php
require_once '../../includes/config.php';
require_once '../includes/auth.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redirect_slides(): void {
    header('Location: ../slides.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_slides();
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {

    $id = (int)($_POST['id'] ?? 0);

    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');

    $button_text = trim($_POST['button_text'] ?? '');
    $button_link = trim($_POST['button_link'] ?? '');

    $media_type = trim($_POST['media_type'] ?? 'image');

    $page_name = trim($_POST['page_name'] ?? 'home');

    $video_url = trim($_POST['video_url'] ?? '');

    $text_align = trim($_POST['text_align'] ?? 'center');

    $overlay_opacity = (float)($_POST['overlay_opacity'] ?? 0.45);

    $sort_order = (int)($_POST['sort_order'] ?? 0);

    $status = isset($_POST['status']) ? 1 : 0;

    $image_path = trim($_POST['existing_image'] ?? '');
    $video_path = trim($_POST['existing_video'] ?? '');

    $err='';

    if(function_exists('upload_image')) {

        $new_img = upload_image('image','slides',$err);

        if($new_img) {

            if($image_path!=='' && function_exists('delete_image')) {
                delete_image($image_path);
            }

            $image_path=$new_img;
        }
    }

    if(isset($_FILES['video']) && !empty($_FILES['video']['name'])) {

        $dir='uploads/slides/videos/';

        if(!is_dir('../../'.$dir)) {
            @mkdir('../../'.$dir,0777,true);
        }

        $ext=strtolower(pathinfo($_FILES['video']['name'],PATHINFO_EXTENSION));

        $allowed=['mp4','webm','ogg'];

        if(in_array($ext,$allowed,true)) {

            $file='slide_'.time().'_'.mt_rand(1000,9999).'.'.$ext;

            if(move_uploaded_file($_FILES['video']['tmp_name'],'../../'.$dir.$file)) {

                if($video_path!=='' && file_exists('../../'.$video_path)) {
                    @unlink('../../'.$video_path);
                }

                $video_path=$dir.$file;
            }
        }
    }

    if($id>0) {

        $stmt=$conn->prepare("
            UPDATE slides
            SET title=?,
                subtitle=?,
                button_text=?,
                button_link=?,
                media_type=?,
                image_path=?,
                video_path=?,
                video_url=?,
                page_name=?,
                overlay_opacity=?,
                text_align=?,
                status=?,
                sort_order=?
            WHERE id=?
        ");

        $stmt->bind_param(
            'sssssssssdsiii',
            $title,
            $subtitle,
            $button_text,
            $button_link,
            $media_type,
            $image_path,
            $video_path,
            $video_url,
            $page_name,
            $overlay_opacity,
            $text_align,
            $status,
            $sort_order,
            $id
        );

        if($stmt->execute()) {
            flash('slides','Slide updated successfully.');
        } else {
            flash('slides','Failed to update slide: '.$stmt->error,'error');
        }

        $stmt->close();

        redirect_slides();
    }

    $stmt=$conn->prepare("
        INSERT INTO slides (
            title,
            subtitle,
            button_text,
            button_link,
            media_type,
            image_path,
            video_path,
            video_url,
            page_name,
            overlay_opacity,
            text_align,
            status,
            sort_order
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        'sssssssssdsii',
        $title,
        $subtitle,
        $button_text,
        $button_link,
        $media_type,
        $image_path,
        $video_path,
        $video_url,
        $page_name,
        $overlay_opacity,
        $text_align,
        $status,
        $sort_order
    );

    if($stmt->execute()) {
        flash('slides','Slide created successfully.');
    } else {
        flash('slides','Failed to create slide: '.$stmt->error,'error');
    }

    $stmt->close();

    redirect_slides();
}

if($action==='delete') {

    $id=(int)($_POST['id'] ?? 0);

    if($id<=0) {
        flash('slides','Invalid slide selected.','error');
        redirect_slides();
    }

    $stmt=$conn->prepare("
        SELECT image_path,video_path
        FROM slides
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param('i',$id);
    $stmt->execute();

    $slide=$stmt->get_result()->fetch_assoc();

    $stmt->close();

    if($slide) {

        if(!empty($slide['image_path']) && function_exists('delete_image')) {
            delete_image($slide['image_path']);
        }

        if(!empty($slide['video_path']) && file_exists('../../'.$slide['video_path'])) {
            @unlink('../../'.$slide['video_path']);
        }
    }

    $stmt=$conn->prepare("DELETE FROM slides WHERE id=? LIMIT 1");

    $stmt->bind_param('i',$id);

    if($stmt->execute()) {
        flash('slides','Slide deleted.');
    } else {
        flash('slides','Failed to delete slide: '.$stmt->error,'error');
    }

    $stmt->close();

    redirect_slides();
}

flash('slides','Invalid request action.','error');

redirect_slides();