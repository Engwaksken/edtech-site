<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/event-reminder-helpers.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function redir_ev(string $page = 'events', string $qs = ''): void
{
    $url = '../' . $page . '.php';
    if ($qs !== '') {
        $url .= '?' . ltrim($qs, '?');
    }

    header('Location: ' . $url);
    exit;
}

function ev_flash(string $key, string $message, string $type = 'success'): void
{
    if (function_exists('flash')) {
        flash($key, $message, $type);
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION[$key] = [
        'message' => $message,
        'type'    => $type,
    ];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';


if ($action === 'toggle_attended') {
    $id       = (int)($_POST['id'] ?? 0);
    $attended = ($_POST['attended'] ?? '0') === '1' ? 1 : 0;

    if ($id <= 0) {
        echo 'error';
        exit;
    }

    $stmt = $conn->prepare("UPDATE event_registrations SET attended=? WHERE id=?");
    if (!$stmt) {
        echo 'error';
        exit;
    }

    $stmt->bind_param('ii', $attended, $id);
    echo $stmt->execute() ? 'ok' : 'error';
    $stmt->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redir_ev();
}

if (!event_verify_csrf($_POST['csrf_token'] ?? null)) {
    ev_flash('events', 'Your session token expired. Refresh the page and try again.', 'error');
    redir_ev();
}


if ($action === 'save') {
    if (function_exists('admin_is_mentor') && admin_is_mentor()) {
        ev_flash('events', 'You do not have permission to manage events.', 'error');
        redir_ev();
    }

    $id=(int)($_POST['id']??0);
    $title=trim((string)($_POST['title']??''));
    $description=trim((string)($_POST['description']??''));
    $agenda=trim((string)($_POST['agenda']??''));
    $location=trim((string)($_POST['location']??''));
    $meeting_link=trim((string)($_POST['meeting_link']??''));
    $speakers=trim((string)($_POST['speakers']??''));
    $tags=trim((string)($_POST['tags']??''));
    $capacity=max(0,(int)($_POST['capacity']??0));
    $is_public=isset($_POST['is_public'])?1:0;
    $typesAllowed=['webinar','workshop','summit','demo_day','networking','mentorship'];
    $statusAllowed=['draft','upcoming','ongoing','completed','cancelled'];
    $event_type=in_array($_POST['event_type']??'',$typesAllowed,true)?$_POST['event_type']:'webinar';
    $status=in_array($_POST['status']??'',$statusAllowed,true)?$_POST['status']:'draft';
    $event_date=trim((string)($_POST['event_date']??''));
    $end_date=trim((string)($_POST['end_date']??''));
    $registration_deadline=trim((string)($_POST['registration_deadline']??''));
    $end_date=$end_date!==''?str_replace('T',' ',$end_date).':00':null;
    $registration_deadline=$registration_deadline!==''?str_replace('T',' ',$registration_deadline).':00':null;
    $event_date=$event_date!==''?str_replace('T',' ',$event_date).':00':'';
    if($title===''||$event_date===''){ev_flash('events','Event title and start date are required.','error');redir_ev();}
    if(strtotime($event_date)===false){ev_flash('events','Enter a valid event date.','error');redir_ev();}
    if($end_date!==null&&strtotime($end_date)<strtotime($event_date)){ev_flash('events','The end date cannot be before the start date.','error');redir_ev();}
    if($meeting_link!==''&&!filter_var($meeting_link,FILTER_VALIDATE_URL)){ev_flash('events','Enter a valid meeting link URL.','error');redir_ev();}

    $preset=(string)($_POST['reminder_preset']??'none');
    $reminder_enabled=$preset==='none'?0:1;
    $reminder_value=0; $reminder_unit='days';
    $presets=['same_day'=>[0,'days'],'1_day'=>[1,'days'],'2_days'=>[2,'days'],'3_days'=>[3,'days'],'7_days'=>[7,'days'],'14_days'=>[14,'days']];
    if(isset($presets[$preset])){[$reminder_value,$reminder_unit]=$presets[$preset];}
    elseif($preset==='custom'){$reminder_value=max(0,(int)($_POST['reminder_value']??1));$reminder_unit=in_array($_POST['reminder_unit']??'',['minutes','hours','days','weeks'],true)?$_POST['reminder_unit']:'days';}
    else{$reminder_enabled=0;$reminder_value=0;$reminder_unit='days';}
    $reminder_time=trim((string)($_POST['reminder_time']??'09:00'));$reminder_time=preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$reminder_time)?$reminder_time.':00':null;
    $reminder_method=in_array($_POST['reminder_method']??'',['email','notification','both'],true)?$_POST['reminder_method']:'email';
    $reminder_at=$reminder_enabled?event_calculate_reminder_at($event_date,$reminder_value,$reminder_unit,$reminder_time):null;
    if($reminder_enabled&&$reminder_at===null){ev_flash('events','The reminder schedule could not be calculated.','error');redir_ev();}
    $reminder_status=$reminder_enabled?'scheduled':'disabled';

    $cover_image=trim((string)($_POST['existing_cover']??''));$upload_err='';
    if(function_exists('upload_image')){$new_cover=upload_image('cover_image','events',$upload_err);if($new_cover){if($cover_image!==''&&function_exists('delete_image'))delete_image($cover_image);$cover_image=$new_cover;}}

    if($id>0){
        $stmt=$conn->prepare("UPDATE events SET title=?,event_type=?,status=?,event_date=?,end_date=?,location=?,meeting_link=?,capacity=?,description=?,agenda=?,speakers=?,tags=?,cover_image=?,registration_deadline=?,is_public=?,reminder_enabled=?,reminder_value=?,reminder_unit=?,reminder_time=?,reminder_method=?,reminder_at=?,reminder_status=?,reminder_sent_at=NULL,reminder_last_error=NULL,reminder_attempts=0 WHERE id=?");
        if(!$stmt){ev_flash('events','DB error: '.$conn->error,'error');redir_ev();}
        $stmt->bind_param('sssssssissssssiiisssssi',$title,$event_type,$status,$event_date,$end_date,$location,$meeting_link,$capacity,$description,$agenda,$speakers,$tags,$cover_image,$registration_deadline,$is_public,$reminder_enabled,$reminder_value,$reminder_unit,$reminder_time,$reminder_method,$reminder_at,$reminder_status,$id);
        $ok=$stmt->execute();$msg=$ok?'Event updated successfully.':'Update failed: '.$stmt->error;$stmt->close();ev_flash('events',$msg,$ok?'success':'error');redir_ev();
    }
    $stmt=$conn->prepare("INSERT INTO events(title,event_type,status,event_date,end_date,location,meeting_link,capacity,description,agenda,speakers,tags,cover_image,registration_deadline,is_public,reminder_enabled,reminder_value,reminder_unit,reminder_time,reminder_method,reminder_at,reminder_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if(!$stmt){ev_flash('events','DB error: '.$conn->error,'error');redir_ev();}
    $stmt->bind_param('sssssssissssssiiisssss',$title,$event_type,$status,$event_date,$end_date,$location,$meeting_link,$capacity,$description,$agenda,$speakers,$tags,$cover_image,$registration_deadline,$is_public,$reminder_enabled,$reminder_value,$reminder_unit,$reminder_time,$reminder_method,$reminder_at,$reminder_status);
    $ok=$stmt->execute();$msg=$ok?'Event created successfully.':'Create failed: '.$stmt->error;$stmt->close();ev_flash('events',$msg,$ok?'success':'error');redir_ev();
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        ev_flash('events', 'Invalid event.', 'error');
        redir_ev();
    }

    $stmt = $conn->prepare("SELECT cover_image FROM events WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($event && !empty($event['cover_image']) && function_exists('delete_image')) {
        delete_image($event['cover_image']);
    }

    $stmt = $conn->prepare("DELETE FROM event_registrations WHERE event_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM events WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);

    $ok = $stmt->execute();

    ev_flash(
        'events',
        $ok ? 'Event deleted successfully.' : 'Delete failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir_ev();
}


if ($action === 'send_reminders') {
    if (function_exists('admin_is_mentor') && admin_is_mentor()) { ev_flash('events','Permission denied.','error'); redir_ev(); }
    $id=(int)($_POST['id']??0); if($id<=0){ev_flash('events','Invalid event.','error');redir_ev();}
    $stmt=$conn->prepare("SELECT * FROM events WHERE id=? LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$ev=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$ev){ev_flash('events','Event not found.','error');redir_ev();}
    $stmt=$conn->prepare("SELECT id,name,email FROM event_registrations WHERE event_id=? AND email<>''");$stmt->bind_param('i',$id);$stmt->execute();$regs=$stmt->get_result();$sent=0;$failed=0;$lastError=null;
    while($reg=$regs->fetch_assoc()){
        $email=trim((string)$reg['email']); if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$failed++;$lastError='Invalid email: '.$email;event_log_reminder($conn,$id,(int)$reg['id'],$email,(string)($ev['reminder_method']??'email'),'skipped',$lastError);continue;}
        $subject='Reminder: '.$ev['title'];$dt=date('l, F j, Y \a\t g:i A',strtotime((string)$ev['event_date']));$body="Hello ".($reg['name']?:'Participant').",\n\nThis is a reminder for {$ev['title']}.\nDate: {$dt}\n".(!empty($ev['location'])?"Location: {$ev['location']}\n":'').(!empty($ev['meeting_link'])?"Join link: {$ev['meeting_link']}\n":'')."\nWe look forward to seeing you.";
        $ok=false;if(function_exists('send_email')){try{$ok=(bool)send_email($email,$subject,$body);}catch(Throwable $e){$lastError=$e->getMessage();}}else{$from=defined('MAIL_FROM')?MAIL_FROM:'noreply@example.com';$ok=@mail($email,$subject,$body,"From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");}
        if($ok){$sent++;event_log_reminder($conn,$id,(int)$reg['id'],$email,(string)($ev['reminder_method']??'email'),'sent');}else{$failed++;$lastError=$lastError?:'Mail transport returned false';event_log_reminder($conn,$id,(int)$reg['id'],$email,(string)($ev['reminder_method']??'email'),'failed',$lastError);}
    }$stmt->close();$status=$failed===0?'sent':'failed';$sentAt=$failed===0?date('Y-m-d H:i:s'):null;$up=$conn->prepare("UPDATE events SET reminder_status=?,reminder_sent_at=?,reminder_last_error=?,reminder_attempts=reminder_attempts+1 WHERE id=?");$up->bind_param('sssi',$status,$sentAt,$lastError,$id);$up->execute();$up->close();
    ev_flash('events',"Reminder processing complete: {$sent} sent, {$failed} failed.",$failed?'warning':'success');redir_ev();
}

if ($action === 'add_registration') {
    $event_id = 0;

    if (isset($_POST['event_id']) && (int)$_POST['event_id'] > 0) {
        $event_id = (int)$_POST['event_id'];
    } elseif (isset($_POST['event_id_sel']) && (int)$_POST['event_id_sel'] > 0) {
        $event_id = (int)$_POST['event_id_sel'];
    }

    $name         = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $organisation = trim($_POST['organisation'] ?? '');
    $role         = trim($_POST['role'] ?? '');
    $attended     = isset($_POST['attended']) ? 1 : 0;

    if ($event_id <= 0) {
        ev_flash('attendees', 'Event is required.', 'error');
        redir_ev('event-attendees');
    }

    if ($name === '') {
        ev_flash('attendees', 'Name is required.', 'error');
        redir_ev('event-attendees', 'event_id=' . $event_id);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ev_flash('attendees', 'Valid email is required.', 'error');
        redir_ev('event-attendees', 'event_id=' . $event_id);
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM event_registrations
        WHERE event_id=? AND email=?
        LIMIT 1
    ");
    $stmt->bind_param('is', $event_id, $email);
    $stmt->execute();
    $duplicate = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($duplicate) {
        ev_flash('attendees', 'This email is already registered for this event.', 'error');
        redir_ev('event-attendees', 'event_id=' . $event_id);
    }

    $stmt = $conn->prepare("SELECT capacity FROM events WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $ev = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ev) {
        ev_flash('attendees', 'Event not found.', 'error');
        redir_ev('event-attendees');
    }

    if ((int)$ev['capacity'] > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM event_registrations WHERE event_id=?");
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $countRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $reg_count = (int)($countRow['total'] ?? 0);

        if ($reg_count >= (int)$ev['capacity']) {
            ev_flash('attendees', 'This event is at capacity.', 'error');
            redir_ev('event-attendees', 'event_id=' . $event_id);
        }
    }

    $checkin_code = strtoupper(substr(md5(uniqid($email, true)), 0, 8));

    $stmt = $conn->prepare("
        INSERT INTO event_registrations (
            event_id,
            name,
            email,
            organisation,
            role,
            attended,
            checkin_code,
            registered_at
        ) VALUES (
            ?,?,?,?,?,?,?,NOW()
        )
    ");

    if (!$stmt) {
        ev_flash('attendees', 'DB error: ' . $conn->error, 'error');
        redir_ev('event-attendees', 'event_id=' . $event_id);
    }

    $stmt->bind_param(
        'issssis',
        $event_id,
        $name,
        $email,
        $organisation,
        $role,
        $attended,
        $checkin_code
    );

    $ok = $stmt->execute();

    ev_flash(
        'attendees',
        $ok ? 'Registration added successfully.' : 'Failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir_ev('event-attendees', 'event_id=' . $event_id);
}


if ($action === 'delete_registration') {
    $id       = (int)($_POST['id'] ?? 0);
    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($id <= 0) {
        ev_flash('attendees', 'Invalid registration.', 'error');
        redir_ev('event-attendees', 'event_id=' . $event_id);
    }

    $stmt = $conn->prepare("DELETE FROM event_registrations WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);

    $ok = $stmt->execute();

    ev_flash(
        'attendees',
        $ok ? 'Registration removed successfully.' : 'Failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir_ev('event-attendees', 'event_id=' . $event_id);
}


if ($action === 'mark_all_attended') {
    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($event_id <= 0) {
        ev_flash('attendees', 'Invalid event.', 'error');
        redir_ev('event-attendees');
    }

    $stmt = $conn->prepare("UPDATE event_registrations SET attended=1 WHERE event_id=?");
    $stmt->bind_param('i', $event_id);

    $ok = $stmt->execute();

    ev_flash(
        'attendees',
        $ok ? 'All registrants marked as attended.' : 'Failed: ' . $stmt->error,
        $ok ? 'success' : 'error'
    );

    $stmt->close();
    redir_ev('event-attendees', 'event_id=' . $event_id);
}

ev_flash('events', 'Invalid action.', 'error');
redir_ev();