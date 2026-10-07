<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$page_title  = 'Dashboard';
$current_nav = 'dashboard.php';

$venture_id = (int)($venture_id ?? 0);

if ($venture_id <= 0) {
    die('Invalid venture session.');
}

function dash_count(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_row() : [0];
    $stmt->close();

    return (int)($row[0] ?? 0);
}

function dash_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

$founder_name = $VENTURE['founder_name'] ?? '';
$first_name = trim(explode(' ', $founder_name ?: ($VENTURE['name'] ?? 'Founder'))[0]);

$doc_count = dash_count(
    $conn,
    "SELECT COUNT(*) FROM venture_documents WHERE venture_id = ? AND is_latest = 1",
    "i",
    [$venture_id]
);

$team_count = dash_count(
    $conn,
    "SELECT COUNT(*) FROM venture_team_members WHERE venture_id = ?",
    "i",
    [$venture_id]
);

$investor_count = dash_count(
    $conn,
    "SELECT COUNT(*) FROM investor_matches WHERE venture_id = ? AND status != 'passed'",
    "i",
    [$venture_id]
);

$event_count = dash_count(
    $conn,
    "SELECT COUNT(*)
     FROM event_registrations er
     JOIN events e ON e.id = er.event_id
     WHERE er.name = ?
       AND e.event_date >= NOW()
       AND e.status != 'cancelled'",
    "s",
    [$founder_name]
);

$recent_docs = dash_rows(
    $conn,
    "SELECT *
     FROM venture_documents
     WHERE venture_id = ?
       AND is_latest = 1
     ORDER BY uploaded_at DESC
     LIMIT 4",
    "i",
    [$venture_id]
);

$upcoming_events = dash_rows(
    $conn,
    "SELECT DISTINCT e.*
     FROM events e
     JOIN event_registrations er ON er.event_id = e.id
     WHERE er.name = ?
       AND e.event_date >= NOW()
       AND e.status != 'cancelled'
     ORDER BY e.event_date ASC
     LIMIT 3",
    "s",
    [$founder_name]
);

$investor_pipe = dash_rows(
    $conn,
    "SELECT 
        m.*,
        i.name AS inv_name,
        i.firm_name,
        i.investor_type
     FROM investor_matches m
     JOIN investors i ON i.id = m.investor_id
     WHERE m.venture_id = ?
       AND m.status != 'passed'
     ORDER BY m.created_at DESC
     LIMIT 4",
    "i",
    [$venture_id]
);

$profile_fields = [
    'name'          => 'Venture Name',
    'tagline'       => 'Tagline',
    'description'   => 'Description',
    'founder_name'  => 'Founder Name',
    'founder_email' => 'Founder Email',
    'sector'        => 'Sector',
    'country'       => 'Country',
    'website'       => 'Website',
];

$profile_checks = [];
$filled = 0;

foreach ($profile_fields as $field => $label) {
    $done = !empty($VENTURE[$field]);
    if ($done) $filled++;

    $profile_checks[] = [
        'field' => $field,
        'label' => $label,
        'done'  => $done,
    ];
}

$logo_done = !empty($VENTURE['logo']);
if ($logo_done) $filled++;

$profile_checks[] = [
    'field' => 'logo',
    'label' => 'Logo',
    'done'  => $logo_done,
];

$total_profile_items = count($profile_fields) + 1;
$profile_pct = $total_profile_items > 0
    ? (int)round(($filled / $total_profile_items) * 100)
    : 0;

$stages = [
    'idea'     => 'Idea',
    'mvp'      => 'MVP',
    'pre_seed' => 'Pre-Seed',
    'seed'     => 'Seed',
    'series_a' => 'Series A',
    'growth'   => 'Growth',
];

$doc_categories = [
    'pitch_deck'      => 'Pitch Deck',
    'financial_model' => 'Financial Model',
    'business_plan'   => 'Business Plan',
    'legal'           => 'Legal',
    'product_demo'    => 'Demo',
    'team_profile'    => 'Team',
    'due_diligence'   => 'Due Diligence',
    'impact_report'   => 'Impact',
    'other'           => 'Other',
];

$doc_icons = [
    'pitch_deck'      => 'fa-file-powerpoint',
    'financial_model' => 'fa-file-excel',
    'business_plan'   => 'fa-file-word',
    'legal'           => 'fa-file-contract',
    'product_demo'    => 'fa-film',
    'team_profile'    => 'fa-users',
    'due_diligence'   => 'fa-search-dollar',
    'impact_report'   => 'fa-chart-bar',
    'other'           => 'fa-file-alt',
];

$doc_colors = [
    'pitch_deck'      => '#ef4444',
    'financial_model' => '#10b981',
    'business_plan'   => '#3b82f6',
    'legal'           => '#8b5cf6',
    'product_demo'    => '#f59e0b',
    'team_profile'    => '#06b6d4',
    'due_diligence'   => '#6366f1',
    'impact_report'   => '#84cc16',
    'other'           => '#6b7280',
];

$match_statuses = [
    'pending'           => 'Pending',
    'interested'        => 'Interested',
    'meeting_scheduled' => 'Meeting',
    'passed'            => 'Passed',
    'invested'          => 'Invested',
];

$ms_badge = [
    'pending'           => 'badge-muted',
    'interested'        => 'badge-gold',
    'meeting_scheduled' => 'badge-warning',
    'passed'            => 'badge-danger',
    'invested'          => 'badge-success',
];