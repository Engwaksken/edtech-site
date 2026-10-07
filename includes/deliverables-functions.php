<?php
declare(strict_types=1);

function deliverable_status_label(string $status): string {
    return match ($status) {
        'not_started'=>'Not Started','in_progress'=>'In Progress','submitted'=>'Submitted',
        'returned_for_revision'=>'Returned for Revision','approved'=>'Approved',
        'overdue'=>'Overdue','completed'=>'Completed',default=>ucwords(str_replace('_',' ',$status))
    };
}

function deliverable_status_class(string $status, string $dueDate=''): string {
    if (in_array($status,['completed','approved'],true)) return 'status-green';
    if ($status==='submitted') return 'status-blue';
    if ($status==='overdue') return 'status-red';
    if ($status==='not_started') return 'status-grey';
    if ($dueDate!=='') {
        $days=(int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($dueDate))->format('%r%a');
        if ($days<0) return 'status-red';
        if ($days===1) return 'status-orange';
        if ($days>=0 && $days<=3) return 'status-yellow';
    }
    return 'status-blue';
}

function deliverable_due_label(string $date): string {
    $days=(int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date))->format('%r%a');
    return match(true){$days<0=>abs($days).' days overdue',$days===0=>'Today',$days===1=>'Tomorrow',$days<=7=>'In '.$days.' days',default=>(new DateTimeImmutable($date))->format('j M Y')};
}

function refresh_overdue_deliverables(mysqli $conn): void {
    $conn->query("UPDATE deliverable_assignments SET status='overdue' WHERE due_date<CURDATE() AND status IN ('not_started','in_progress','returned_for_revision')");
}

function mentor_profile_id(mysqli $conn,int $userId): int {
    $stmt=$conn->prepare('SELECT id FROM mentor_profiles WHERE user_id=? LIMIT 1');
    $stmt->bind_param('i',$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return (int)($row['id']??0);
}

function deliverable_summary(array $rows): array {
    $s=['total'=>count($rows),'completed'=>0,'submitted'=>0,'overdue'=>0,'due_week'=>0];
    foreach($rows as $r){
        if(in_array($r['status'],['completed','approved'],true))$s['completed']++;
        if($r['status']==='submitted')$s['submitted']++;
        if($r['status']==='overdue')$s['overdue']++;
        if(date('o-W',strtotime($r['due_date']))===date('o-W'))$s['due_week']++;
    }
    return $s;
}
