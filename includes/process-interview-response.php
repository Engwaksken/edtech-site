<?php

require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($conn) || !($conn instanceof mysqli)) die('System error.');
$conn->set_charset('utf8mb4');

$site_url = rtrim(SITE_URL, '/');

$token = trim((string)($_POST['interview_token'] ?? ''));
if ($token === '') { header('Location: /'); exit; }

$stmt = $conn->prepare("SELECT * FROM interviews WHERE token=? LIMIT 1");
$stmt->bind_param('s', $token); $stmt->execute();
$interview = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$interview) { header('Location: /'); exit; }

$interview_id = (int)$interview['id'];

// Check duplicate if not allowing multiple
if ((int)$interview['allow_multiple'] === 0) {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $skey = 'iv_done_' . $interview_id;
    if (isset($_SESSION[$skey])) {
        header('Location: ' . $site_url . '/interview.php?t=' . urlencode($token) . '&done=1');
        exit;
    }
}

// Collect meta
$interviewer_name   = trim($_POST['interviewer_name']  ?? '');
$interviewer_role   = trim($_POST['interviewer_role']  ?? '');
$interview_date     = trim($_POST['interview_date']    ?? '') ?: date('Y-m-d');
$interview_location = trim($_POST['interview_location'] ?? '');
$venture_contact    = trim($_POST['venture_contact']   ?? '');
$duration_minutes   = (int)($_POST['duration_minutes'] ?? 0) ?: null;
$interview_method   = trim($_POST['interview_method']  ?? 'in_person');

$sections_json  = $interview['sections_json'] ?? '[]';
$questions_json = $interview['questions_json'] ?? '[]';
$selected_sections = json_decode($sections_json, true) ?: [];
$custom_questions  = json_decode($questions_json, true) ?: [];

// All section questions (same structure as interview.php)
$SECTION_QUESTIONS = [
    'venture_profile'   => [
        ['q'=>'Venture name','type'=>'text','req'=>true],
        ['q'=>'Date of interview','type'=>'date','req'=>true],
        ['q'=>'Name of person interviewed (venture representative)','type'=>'text','req'=>true],
        ['q'=>'Role / position of interviewee','type'=>'text','req'=>false],
        ['q'=>'Year the venture was founded','type'=>'number','req'=>false],
        ['q'=>'Current stage of the venture','type'=>'select','req'=>false,'options'=>['Ideation','MVP / Prototype','Early growth','Growth','Scale']],
    ],
    'product_service' => [
        ['q'=>'Describe your core product or service','type'=>'textarea','req'=>true],
        ['q'=>'Who is your primary target user / beneficiary?','type'=>'textarea','req'=>true],
        ['q'=>'What problem does your product solve?','type'=>'textarea','req'=>true],
        ['q'=>'How is your product differentiated from alternatives?','type'=>'textarea','req'=>false],
        ['q'=>'What stage is your product development at?','type'=>'select','req'=>false,'options'=>['Idea','Prototype','Pilot','Live / scaled']],
    ],
    'market_customers' => [
        ['q'=>'Who are your key customer segments?','type'=>'textarea','req'=>true],
        ['q'=>'How many active users / customers do you have?','type'=>'text','req'=>false],
        ['q'=>'What is your current geographic reach?','type'=>'text','req'=>false],
        ['q'=>'How do you currently acquire customers?','type'=>'textarea','req'=>false],
        ['q'=>'What is your customer retention rate?','type'=>'text','req'=>false],
    ],
    'business_model' => [
        ['q'=>'Describe your revenue model','type'=>'textarea','req'=>true],
        ['q'=>'What are your primary revenue streams?','type'=>'textarea','req'=>false],
        ['q'=>'What is your average revenue per user / customer?','type'=>'text','req'=>false],
        ['q'=>'Are your unit economics positive? Explain.','type'=>'textarea','req'=>false],
        ['q'=>'What are your key cost drivers?','type'=>'textarea','req'=>false],
    ],
    'team' => [
        ['q'=>'How many full-time team members do you have?','type'=>'number','req'=>false],
        ['q'=>'How many part-time / contractors?','type'=>'number','req'=>false],
        ['q'=>"Describe the founding team's backgrounds",'type'=>'textarea','req'=>false],
        ['q'=>'What key roles are currently missing?','type'=>'textarea','req'=>false],
        ['q'=>'Do you have a formal HR policy or handbook?','type'=>'radio','req'=>false,'options'=>['Yes','No','In development']],
    ],
    'finance' => [
        ['q'=>'What is your total funding raised to date?','type'=>'text','req'=>false],
        ['q'=>'What are your current funding sources?','type'=>'textarea','req'=>false],
        ['q'=>'What is your monthly burn rate?','type'=>'text','req'=>false],
        ['q'=>'How many months of runway do you have?','type'=>'number','req'=>false],
        ['q'=>'Are your financial records / books up to date?','type'=>'radio','req'=>false,'options'=>['Yes, fully','Partially','No']],
        ['q'=>'What funding are you currently seeking?','type'=>'textarea','req'=>false],
    ],
    'technology' => [
        ['q'=>'What technology stack does your product use?','type'=>'textarea','req'=>false],
        ['q'=>'Is your technology built in-house or outsourced?','type'=>'radio','req'=>false,'options'=>['Fully in-house','Outsourced','Hybrid']],
        ['q'=>'What are your main technical limitations?','type'=>'textarea','req'=>false],
        ['q'=>'Does your platform work on low-bandwidth / 2G?','type'=>'radio','req'=>false,'options'=>['Yes','No','Partially']],
        ['q'=>'How do you handle data security and privacy?','type'=>'textarea','req'=>false],
    ],
    'pedagogy' => [
        ['q'=>'Is your curriculum aligned to national standards?','type'=>'radio','req'=>false,'options'=>['Yes','No','Partially']],
        ['q'=>'Describe the learning methodology used','type'=>'textarea','req'=>false],
        ['q'=>'How do you measure learning outcomes?','type'=>'textarea','req'=>false],
        ['q'=>'Do you have qualified curriculum or education staff?','type'=>'radio','req'=>false,'options'=>['Yes','No','Outsourced']],
    ],
    'partnerships' => [
        ['q'=>'List your key partners or institutional relationships','type'=>'textarea','req'=>false],
        ['q'=>'Do you have government or regulatory relationships?','type'=>'radio','req'=>false,'options'=>['Yes','No','In development']],
        ['q'=>'What partnerships are you actively pursuing?','type'=>'textarea','req'=>false],
    ],
    'monitoring_eval' => [
        ['q'=>'Do you have a formal M&E framework?','type'=>'radio','req'=>false,'options'=>['Yes','No','In development']],
        ['q'=>'How do you track and report impact?','type'=>'textarea','req'=>false],
        ['q'=>'What are your key performance indicators (KPIs)?','type'=>'textarea','req'=>false],
        ['q'=>'How frequently do you report to donors / funders?','type'=>'select','req'=>false,'options'=>['Monthly','Quarterly','Bi-annually','Annually','Ad hoc']],
    ],
    'challenges' => [
        ['q'=>'What are the top 3 challenges the venture is facing?','type'=>'textarea','req'=>true],
        ['q'=>'Which challenge is most urgent to resolve?','type'=>'textarea','req'=>true],
        ['q'=>'What is currently blocking growth?','type'=>'textarea','req'=>false],
        ['q'=>'Are there safeguarding or duty-of-care risks?','type'=>'radio','req'=>false,'options'=>['Yes — describe','No','Unsure']],
        ['q'=>'Describe any safeguarding concerns if applicable','type'=>'textarea','req'=>false],
    ],
    'support_needs' => [
        ['q'=>'What type of support is most needed right now?','type'=>'checkbox','req'=>true,'options'=>['Technical assistance','Financial/Funding','Mentorship','Market access','Legal/Compliance','Training','Partnerships','Other']],
        ['q'=>'What would have the biggest impact in the next 90 days?','type'=>'textarea','req'=>true],
        ['q'=>'What does the venture need from the program?','type'=>'textarea','req'=>false],
        ['q'=>'Any other comments or observations?','type'=>'textarea','req'=>false],
    ],
];

$conn->begin_transaction();
try {
    // Insert response row
    $ins = $conn->prepare("
        INSERT INTO interview_responses
            (interview_id, interviewer_name, interviewer_role, interview_date,
             interview_location, venture_contact, duration_minutes, interview_method,
             interview_type, submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param('isssssiss',
        $interview_id, $interviewer_name, $interviewer_role, $interview_date,
        $interview_location, $venture_contact, $duration_minutes,
        $interview_method, $interview['interview_type']
    );
    $ins->execute();
    $response_id = (int)$conn->insert_id;
    $ins->close();

    $astmt = $conn->prepare("
        INSERT INTO interview_answers
            (response_id, section_key, question_text, answer_value, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    $sort = 0;
    foreach ($selected_sections as $skey) {
        if (!isset($SECTION_QUESTIONS[$skey])) continue;
        foreach ($SECTION_QUESTIONS[$skey] as $qi => $q) {
            $fname = 'sec_' . $skey . '_q' . $qi;
            $val   = '';
            if ($q['type'] === 'checkbox') {
                $vals = (array)($_POST[$fname] ?? []);
                $val  = implode('; ', array_filter(array_map('trim', $vals)));
            } else {
                $val = trim((string)($_POST[$fname] ?? ''));
            }
            $astmt->bind_param('isssi', $response_id, $skey, $q['q'], $val, $sort);
            $astmt->execute();
            $sort++;
        }
    }

    foreach ($custom_questions as $qi => $q) {
        $fname = 'custom_q' . $qi;
        $val   = '';
        if (($q['type'] ?? '') === 'checkbox') {
            $vals = (array)($_POST[$fname] ?? []);
            $val  = implode('; ', array_filter(array_map('trim', $vals)));
        } else {
            $val = trim((string)($_POST[$fname] ?? ''));
        }
        $sec = 'custom';
        $qt  = $q['question'] ?? '';
        $astmt->bind_param('isssi', $response_id, $sec, $qt, $val, $sort);
        $astmt->execute();
        $sort++;
    }
    $astmt->close();

    // Update interview status
    $conn->query("UPDATE interviews SET status='in_progress' WHERE id={$interview_id} AND status='pending' LIMIT 1");

    $conn->commit();

    // Mark session as done
    $_SESSION['iv_done_' . $interview_id] = true;

    header('Location: ' . $site_url . '/interview.php?t=' . urlencode($token) . '&done=1');
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    error_log('Interview response failed: ' . $e->getMessage());
    header('Location: ' . $site_url . '/interview.php?t=' . urlencode($token) . '&error=1');
    exit;
}