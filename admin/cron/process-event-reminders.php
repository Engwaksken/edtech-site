<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mail-function.php';
require_once __DIR__ . '/../includes/event-reminder-helpers.php';
$conn->set_charset('utf8mb4');
$sql="SELECT * FROM events WHERE reminder_enabled=1 AND reminder_status IN ('scheduled','failed') AND reminder_at IS NOT NULL AND reminder_at<=NOW() AND event_date>NOW() AND status IN ('upcoming','ongoing') AND reminder_attempts<5 ORDER BY reminder_at ASC LIMIT 25";
$res=$conn->query($sql);
while($event=$res->fetch_assoc()){
    $eventId=(int)$event['id'];
    $lock=$conn->prepare("UPDATE events SET reminder_status='processing',reminder_attempts=reminder_attempts+1 WHERE id=? AND reminder_status IN ('scheduled','failed')");
    $lock->bind_param('i',$eventId); $lock->execute(); $claimed=$lock->affected_rows===1; $lock->close();
    if(!$claimed) continue;
    $stmt=$conn->prepare("SELECT id,name,email FROM event_registrations WHERE event_id=? AND email<>''"); $stmt->bind_param('i',$eventId); $stmt->execute(); $regs=$stmt->get_result();
    $sent=0;$failed=0;$lastError=null;
    while($reg=$regs->fetch_assoc()){
        $email=trim((string)$reg['email']); if(!filter_var($email,FILTER_VALIDATE_EMAIL)){ $failed++; $lastError='Invalid email: '.$email; event_log_reminder($conn,$eventId,(int)$reg['id'],$email,(string)$event['reminder_method'],'skipped',$lastError); continue; }
        $subject='Reminder: '.$event['title'];
        $when=date('l, F j, Y \a\t g:i A',strtotime((string)$event['event_date']));
        $body="Hello ".($reg['name'] ?: 'Participant').",\n\nThis is a reminder for {$event['title']}.\nDate: {$when}\n".(!empty($event['location'])?"Location: {$event['location']}\n":'').(!empty($event['meeting_link'])?"Join link: {$event['meeting_link']}\n":'')."\nWe look forward to seeing you.";
        $ok=false;
        if(function_exists('send_email')) { try { $ok=(bool)send_email($email,$subject,$body); } catch(Throwable $e){ $lastError=$e->getMessage(); } }
        else { $from=defined('MAIL_FROM')?MAIL_FROM:'noreply@example.com'; $ok=@mail($email,$subject,$body,"From: {$from}\r\nContent-Type: text/plain; charset=UTF-8"); }
        if($ok){$sent++;event_log_reminder($conn,$eventId,(int)$reg['id'],$email,(string)$event['reminder_method'],'sent');}else{$failed++;$lastError=$lastError?:'Mail transport returned false';event_log_reminder($conn,$eventId,(int)$reg['id'],$email,(string)$event['reminder_method'],'failed',$lastError);}
    }
    $stmt->close();
    $status=$failed===0?'sent':'failed'; $sentAt=$failed===0?date('Y-m-d H:i:s'):null;
    $up=$conn->prepare("UPDATE events SET reminder_status=?,reminder_sent_at=?,reminder_last_error=? WHERE id=?"); $up->bind_param('sssi',$status,$sentAt,$lastError,$eventId); $up->execute(); $up->close();
    echo sprintf("[%s] Event %d: %d sent, %d failed\n",date('c'),$eventId,$sent,$failed);
}
