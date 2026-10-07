<?php


require_once '../../includes/config.php';   
require_once 'auth.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php'; 
if (!file_exists($autoload)) $autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) die('Dompdf autoload.php not found. Check your vendor path.');
require $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

/* -- Fetch data -------------------------------------- */
$type       = $_GET['type']       ?? 'all';
$venture_id = (int)($_GET['venture_id'] ?? 0);

$ventures_res = $conn->query("SELECT * FROM ventures ORDER BY name ASC");
$ventures = [];
if ($ventures_res) while ($r = $ventures_res->fetch_assoc()) $ventures[(int)$r['id']] = $r;

$analyses_res = $conn->query("
    SELECT ga.*, v.name AS venture_name
    FROM gap_analyses ga
    LEFT JOIN ventures v ON v.id = ga.venture_id
    ORDER BY ga.venture_id, ga.analysis_date DESC
");
$analyses = [];
$analysisMap = []; // venture_id => first (latest) analysis
if ($analyses_res) while ($r = $analyses_res->fetch_assoc()) {
    $analyses[] = $r;
    if (!isset($analysisMap[(int)$r['venture_id']])) {
        $analysisMap[(int)$r['venture_id']] = $r;
    }
}

$gaps_res = $conn->query("
    SELECT g.*, ga.venture_id, ga.analyst_name, ga.analysis_date, v.name AS venture_name
    FROM gap_items g
    JOIN gap_analyses ga ON ga.id = g.analysis_id
    LEFT JOIN ventures v ON v.id = ga.venture_id
    ORDER BY ga.venture_id, g.priority_score ASC, g.created_at DESC
");
$all_gaps = [];
if ($gaps_res) while ($r = $gaps_res->fetch_assoc()) $all_gaps[] = $r;

$categories = [
    'business_model'     => 'Business Model',
    'pedagogy'           => 'Pedagogy & Curriculum',
    'technology'         => 'Technology',
    'marketing_sales'    => 'Marketing & Sales',
    'team_talent'        => 'Team & Talent',
    'finance'            => 'Finance',
    'monitoring_eval'    => 'M&E / Reporting',
    'scale_partnerships' => 'Scale & Partnerships',
    'sops'               => 'SOPs & Safeguarding',
];

/* -- HTML helpers ------------------------------------ */
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function statusBadge(string $status): string {
    $map = [
        'resolved'    => ['#16a34a', '#dcfce7', 'Resolved'],
        'in_progress' => ['#d97706', '#fef3c7', 'In Progress'],
        'not_started' => ['#9ca3af', '#f3f4f6', 'Not Started'],
    ];
    [$fg, $bg, $label] = $map[$status] ?? ['#9ca3af', '#f3f4f6', ucfirst(str_replace('_', ' ', $status))];
    return "<span style='background:{$bg};color:{$fg};padding:2px 7px;border-radius:10px;font-size:8px;font-weight:700'>{$label}</span>";
}

function impactDot(string $val): string {
    $map = ['H' => ['#dc2626', '#fee2e2'], 'M' => ['#d97706', '#fef3c7'], 'L' => ['#16a34a', '#dcfce7']];
    [$fg, $bg] = $map[$val] ?? ['#9ca3af', '#f3f4f6'];
    return "<span style='background:{$bg};color:{$fg};padding:1px 6px;border-radius:10px;font-size:8px;font-weight:800'>{$val}</span>";
}

/* -- Global CSS -------------------------------------- */
$css = '
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 9px; color: #0f1117; background: #fff; }

  .page { padding: 18px 20px 14px; }
  .page-break { page-break-after: always; }

  /* Title band */
  .title-band { background: #0f1117; color: #fff; padding: 10px 14px; border-radius: 4px 4px 0 0; margin-bottom: 0; }
  .title-band h1 { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
  .title-band p  { font-size: 8px; color: #94a3b8; font-weight: 300; }
  .sub-band { background: #1e293b; color: #cbd5e1; padding: 5px 14px; font-size: 8px; margin-bottom: 12px; border-radius: 0 0 4px 4px; }

  /* Stat strip */
  .stat-strip { display: table; width: 100%; border-collapse: collapse; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
  .stat-cell  { display: table-cell; padding: 8px 12px; border-right: 1px solid #e5e7eb; vertical-align: top; }
  .stat-cell:last-child { border-right: none; }
  .stat-label { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; margin-bottom: 3px; }
  .stat-val   { font-size: 18px; font-weight: 700; color: #0f1117; line-height: 1; }
  .stat-sub   { font-size: 7px; color: #9ca3af; margin-top: 1px; }

  /* Section headers */
  .section-header { background: #fc7f10; color: #fff; padding: 5px 10px; font-size: 9px; font-weight: 700; margin: 10px 0 0; border-radius: 3px 3px 0 0; }
  .sub-header     { background: #1e293b; color: #e2e8f0; padding: 4px 10px; font-size: 8px; font-weight: 600; margin: 0 0 0; }
  .info-header    { background: #f8fafc; border: 1px solid #e5e7eb; padding: 4px 10px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin-bottom: 0; border-radius: 3px 3px 0 0; }

  /* Info grid */
  .info-grid { border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 3px 3px; margin-bottom: 10px; overflow: hidden; }
  .info-row  { display: table; width: 100%; border-collapse: collapse; border-bottom: 1px solid #e5e7eb; }
  .info-row:last-child { border-bottom: none; }
  .info-lbl  { display: table-cell; width: 16%; padding: 4px 8px; font-weight: 700; font-size: 8px; color: #374151; background: #f9fafb; border-right: 1px solid #e5e7eb; vertical-align: middle; }
  .info-val  { display: table-cell; width: 34%; padding: 4px 8px; font-size: 8px; color: #0f1117; border-right: 1px solid #e5e7eb; vertical-align: middle; }
  .info-val:last-child { border-right: none; }

  /* Tables */
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8px; }
  th    { background: #1e293b; color: #e2e8f0; padding: 5px 6px; text-align: left; font-size: 7.5px; font-weight: 700; border: 1px solid #334155; }
  td    { padding: 4px 6px; border: 1px solid #e5e7eb; vertical-align: top; line-height: 1.35; }
  tr:nth-child(even) td { background: #f8fafc; }
  tr:hover td { }
  .center { text-align: center; }
  .bold   { font-weight: 700; }

  /* Summary table */
  .sum-row-total { background: #1e293b !important; }
  .sum-row-total td { color: #e2e8f0; font-weight: 700; border-color: #334155; }

  /* Category section */
  .cat-section { margin-bottom: 14px; }
  .cat-summary-row td { background: #f1f5f9 !important; font-weight: 700; font-size: 7.5px; }

  /* Footer */
  .footer { text-align: center; color: #9ca3af; font-size: 7px; padding-top: 8px; border-top: 1px solid #e5e7eb; margin-top: 16px; }

  /* Progress bar */
  .prog-wrap { background: #f1f5f9; border-radius: 3px; height: 6px; overflow: hidden; display: inline-block; width: 60px; vertical-align: middle; margin-right: 3px; }
  .prog-fill  { height: 100%; border-radius: 3px; display: inline-block; }

  /* Venture header card */
  .v-header { background: linear-gradient(135deg, #0f1117 0%, #1e293b 100%); color: #fff; padding: 12px 16px; border-radius: 4px; margin-bottom: 10px; }
  .v-header h2 { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
  .v-header p  { font-size: 8px; color: #94a3b8; }
</style>
';

/* ------------------------------------------------------
   SINGLE VENTURE HTML BUILDER
------------------------------------------------------ */
function buildVentureHtml(array $venture, array $gaps, array $categories, ?array $analysis): string {
    $name = $venture['name'] ?? 'Unknown';
    $totalGaps  = count($gaps);
    $resolved   = count(array_filter($gaps, fn($g) => $g['status'] === 'resolved'));
    $inprog     = count(array_filter($gaps, fn($g) => $g['status'] === 'in_progress'));
    $critical   = count(array_filter($gaps, fn($g) => $g['impact'] === 'H' && $g['urgency'] === 'H' && $g['status'] !== 'resolved'));
    $overallPct = $totalGaps > 0 ? round(array_sum(array_column($gaps, 'pct_complete')) / $totalGaps) : 0;
    $pctColor   = $overallPct >= 70 ? '#16a34a' : ($overallPct >= 40 ? '#d97706' : '#dc2626');

    $html = '
    <div class="page">
      <div class="title-band">
        <h1>' . e(strtoupper($name)) . '  |  Gap Analysis  |  EdTech Fellowship Program</h1>
        <p>One section per gap category - Mastercard Foundation EdTech Fellowship</p>
      </div>
      <div class="sub-band">Generated ' . date('d M Y, H:i') . '  |  Analyst: ' . e($analysis['analyst_name'] ?? '-') . '</div>

      <!-- STAT STRIP -->
      <div class="stat-strip">
        <div class="stat-cell"><div class="stat-label">Total Gaps</div><div class="stat-val">' . $totalGaps . '</div></div>
        <div class="stat-cell"><div class="stat-label">Resolved</div><div class="stat-val" style="color:#16a34a">' . $resolved . '</div><div class="stat-sub">' . ($totalGaps > 0 ? round($resolved / $totalGaps * 100) : 0) . '% of total</div></div>
        <div class="stat-cell"><div class="stat-label">In Progress</div><div class="stat-val" style="color:#d97706">' . $inprog . '</div></div>
        <div class="stat-cell"><div class="stat-label">Critical</div><div class="stat-val" style="color:#dc2626">' . $critical . '</div><div class="stat-sub">H×H unresolved</div></div>
        <div class="stat-cell"><div class="stat-label">Avg. Progress</div><div class="stat-val" style="color:' . $pctColor . '">' . $overallPct . '%</div></div>
      </div>

      <!-- VENTURE INFO -->
      <div class="info-header">Venture Information</div>
      <div class="info-grid">
        <div class="info-row">
          <div class="info-lbl">Venture Name</div><div class="info-val">' . e($name) . '</div>
          <div class="info-lbl">Stage / Phase</div><div class="info-val">' . e($analysis['stage'] ?? '-') . '</div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Founder(s)</div><div class="info-val">' . e($analysis['founders'] ?? '-') . '</div>
          <div class="info-lbl">Sector</div><div class="info-val">' . e($analysis['sector'] ?? 'EdTech') . '</div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Region</div><div class="info-val">' . e($analysis['region'] ?? '-') . '</div>
          <div class="info-lbl">Analysis Date</div><div class="info-val">' . e($analysis['analysis_date'] ?? '-') . '</div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Analyst / Coach</div><div class="info-val">' . e($analysis['analyst_name'] ?? '-') . '</div>
          <div class="info-lbl">Review Date</div><div class="info-val">' . e($analysis['review_date'] ?? '-') . '</div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Key Risk</div><div class="info-val" style="width:84%;border-right:none" colspan="3">' . e($analysis['key_risk'] ?? '-') . '</div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Next Action</div><div class="info-val" style="width:84%;border-right:none">' . e($analysis['next_action'] ?? '-') . '</div>
        </div>
      </div>

      <!-- CATEGORY SUMMARY -->
      <div class="section-header">Gap Category Summary</div>
      <table>
        <thead>
          <tr>
            <th style="width:4%">#</th>
            <th style="width:22%">Category</th>
            <th style="width:8%" class="center">Total</th>
            <th style="width:8%" class="center">Resolved</th>
            <th style="width:8%" class="center">Progress</th>
            <th style="width:22%">Top Gap</th>
            <th style="width:14%" class="center">Status</th>
          </tr>
        </thead>
        <tbody>';

    $catNum = 1;
    foreach ($categories as $catKey => $catLabel) {
        $cg    = array_values(array_filter($gaps, fn($g) => $g['gap_category'] === $catKey));
        $ct    = count($cg);
        $cr    = count(array_filter($cg, fn($g) => $g['status'] === 'resolved'));
        $cpct  = $ct > 0 ? round(array_sum(array_column($cg, 'pct_complete')) / $ct) : 0;
        $topG  = '';
        foreach ($cg as $cgi) { if ($cgi['impact'] === 'H') { $topG = $cgi['challenge'] ?? ''; break; } }
        if (!$topG && !empty($cg)) $topG = $cg[0]['challenge'] ?? '';
        $status = $cpct >= 70 ? 'On Track' : ($cpct >= 40 ? 'In Progress' : ($ct > 0 ? 'Needs Attention' : '-'));
        [$sfg, $sbg] = $cpct >= 70 ? ['#16a34a','#dcfce7'] : ($cpct >= 40 ? ['#d97706','#fef3c7'] : ($ct > 0 ? ['#dc2626','#fee2e2'] : ['#9ca3af','#f3f4f6']));
        $pbar = $ct > 0 ? '<div class="prog-wrap"><div class="prog-fill" style="width:' . $cpct . '%;background:' . ($cpct >= 70 ? '#16a34a' : ($cpct >= 40 ? '#d97706' : '#dc2626')) . '"></div></div> ' . $cpct . '%' : '-';
        $html .= '<tr>
            <td class="center">' . $catNum++ . '</td>
            <td class="bold">' . e($catLabel) . '</td>
            <td class="center">' . ($ct ?: '-') . '</td>
            <td class="center">' . ($cr ?: '-') . '</td>
            <td class="center">' . $pbar . '</td>
            <td style="font-size:7.5px">' . e(mb_strimwidth($topG, 0, 60, '…')) . '</td>
            <td class="center"><span style="background:' . $sbg . ';color:' . $sfg . ';padding:2px 6px;border-radius:8px;font-size:7.5px;font-weight:700">' . $status . '</span></td>
          </tr>';
    }
    $html .= '</tbody></table>';

    /* Gap detail sections */
    foreach ($categories as $catKey => $catLabel) {
        $cg = array_values(array_filter($gaps, fn($g) => $g['gap_category'] === $catKey));
        if (empty($cg)) continue;

        $ct   = count($cg);
        $cr   = count(array_filter($cg, fn($g) => $g['status'] === 'resolved'));
        $cpct = $ct > 0 ? round(array_sum(array_column($cg, 'pct_complete')) / $ct) : 0;

        $html .= '
        <div class="cat-section">
          <div class="section-header">' . e($catLabel) . '</div>
          <table>
            <thead>
              <tr>
                <th style="width:3%">#</th>
                <th style="width:22%">Gap / Challenge</th>
                <th style="width:18%">Root Cause</th>
                <th style="width:5%" class="center">Imp.</th>
                <th style="width:5%" class="center">Urg.</th>
                <th style="width:4%" class="center">Pri.</th>
                <th style="width:20%">Intervention</th>
                <th style="width:10%">Responsible</th>
                <th style="width:5%" class="center">Month</th>
                <th style="width:10%" class="center">Status</th>
                <th style="width:6%" class="center">Done %</th>
              </tr>
            </thead>
            <tbody>
              <tr class="cat-summary-row">
                <td class="center bold">?</td>
                <td colspan="2" class="bold">SUMMARY - ' . e($catLabel) . '</td>
                <td colspan="3"></td>
                <td colspan="2"></td>
                <td></td>
                <td class="center bold">' . $cr . '/' . $ct . ' resolved</td>
                <td class="center bold">' . $cpct . '%</td>
              </tr>';
        foreach ($cg as $gi => $gap) {
            $html .= '<tr>
                <td class="center">' . ($gi + 1) . '</td>
                <td>' . e($gap['challenge'] ?? '-') . '</td>
                <td style="font-size:7.5px;color:#4b5563">' . e($gap['root_cause'] ?? '-') . '</td>
                <td class="center">' . impactDot($gap['impact'] ?? '') . '</td>
                <td class="center">' . impactDot($gap['urgency'] ?? '') . '</td>
                <td class="center bold">' . e($gap['priority_score'] ?? '-') . '</td>
                <td style="font-size:7.5px">' . e($gap['intervention'] ?? '-') . '</td>
                <td style="font-size:7.5px">' . e($gap['responsible'] ?? '-') . '</td>
                <td class="center">' . ($gap['target_month'] ? 'M' . (int)$gap['target_month'] : '-') . '</td>
                <td class="center">' . statusBadge($gap['status'] ?? 'not_started') . '</td>
                <td class="center">' . (int)($gap['pct_complete'] ?? 0) . '%</td>
              </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<div class="footer">EdTech Fellowship Program | Mastercard Foundation | Generated ' . date('d M Y H:i') . '</div></div>';
    return $html;
}

/* ------------------------------------------------------
   ALL-VENTURES SUMMARY HTML BUILDER
------------------------------------------------------ */
function buildAllVenturesHtml(array $ventures, array $all_gaps, array $analysisMap, array $categories): string {
    $totalGaps  = count($all_gaps);
    $totalRes   = count(array_filter($all_gaps, fn($g) => $g['status'] === 'resolved'));
    $totalCrit  = count(array_filter($all_gaps, fn($g) => $g['impact'] === 'H' && $g['urgency'] === 'H' && $g['status'] !== 'resolved'));
    $overallPct = $totalGaps > 0 ? round(array_sum(array_column($all_gaps, 'pct_complete')) / $totalGaps) : 0;
    $pctColor   = $overallPct >= 70 ? '#16a34a' : ($overallPct >= 40 ? '#d97706' : '#dc2626');

    $html = '
    <div class="page">
      <div class="title-band">
        <h1>EdTech Fellowship Program  |  Mastercard Foundation</h1>
        <p>Venture Gap Analysis Dashboard - Cross-Venture Summary - 12-Month Support Period</p>
      </div>
      <div class="sub-band">Generated ' . date('d M Y, H:i') . '  |  Total Ventures: ' . count($ventures) . '</div>

      <div class="stat-strip">
        <div class="stat-cell"><div class="stat-label">Ventures</div><div class="stat-val">' . count($ventures) . '</div><div class="stat-sub">in program</div></div>
        <div class="stat-cell"><div class="stat-label">Total Gaps</div><div class="stat-val">' . $totalGaps . '</div><div class="stat-sub">identified</div></div>
        <div class="stat-cell"><div class="stat-label">Resolved</div><div class="stat-val" style="color:#16a34a">' . $totalRes . '</div><div class="stat-sub">' . ($totalGaps > 0 ? round($totalRes / $totalGaps * 100) : 0) . '% of total</div></div>
        <div class="stat-cell"><div class="stat-label">Critical</div><div class="stat-val" style="color:#dc2626">' . $totalCrit . '</div><div class="stat-sub">H×H unresolved</div></div>
        <div class="stat-cell"><div class="stat-label">Avg. Progress</div><div class="stat-val" style="color:' . $pctColor . '">' . $overallPct . '%</div></div>
      </div>

      <div class="section-header">Venture Gap Summary</div>
      <table>
        <thead>
          <tr>
            <th style="width:3%">#</th>
            <th style="width:18%">Venture</th>
            <th style="width:9%">Stage</th>
            <th style="width:7%" class="center">Total Gaps</th>
            <th style="width:7%" class="center">Resolved</th>
            <th style="width:10%" class="center">Progress</th>
            <th style="width:12%">Top Category</th>
            <th style="width:6%" class="center">Critical</th>
            <th style="width:11%" class="center">Status</th>
            <th style="width:10%">Last Analysis</th>
            <th style="width:7%">Analyst</th>
          </tr>
        </thead>
        <tbody>';

    $vNum = 1;
    foreach ($ventures as $vid => $v) {
        $vg   = array_filter($all_gaps, fn($g) => (int)$g['venture_id'] === $vid);
        $vt   = count($vg);
        $vr   = count(array_filter($vg, fn($g) => $g['status'] === 'resolved'));
        $vpct = $vt > 0 ? round(array_sum(array_column(array_values($vg), 'pct_complete')) / $vt) : 0;
        $vc   = count(array_filter($vg, fn($g) => $g['impact'] === 'H' && $g['urgency'] === 'H' && $g['status'] !== 'resolved'));
        $vs   = $vpct >= 70 ? 'On Track' : ($vpct >= 40 ? 'In Progress' : ($vt > 0 ? 'Needs Attn.' : 'No Data'));
        [$sfg, $sbg] = $vpct >= 70 ? ['#16a34a','#dcfce7'] : ($vpct >= 40 ? ['#d97706','#fef3c7'] : ($vt > 0 ? ['#dc2626','#fee2e2'] : ['#9ca3af','#f3f4f6']));
        $catCounts = [];
        foreach ($vg as $gi) { $catCounts[$gi['gap_category']] = ($catCounts[$gi['gap_category']] ?? 0) + 1; }
        arsort($catCounts);
        $topCat = $categories[array_key_first($catCounts) ?? ''] ?? '-';
        $la = $analysisMap[$vid] ?? null;
        $pbar = $vt > 0 ? $vpct . '%' : '-';

        $html .= '<tr>
            <td class="center">' . $vNum++ . '</td>
            <td class="bold">' . e($v['name']) . '</td>
            <td style="font-size:7.5px">' . e($la['stage'] ?? '-') . '</td>
            <td class="center">' . ($vt ?: '-') . '</td>
            <td class="center">' . ($vr ?: '-') . '</td>
            <td class="center">' . $pbar . '</td>
            <td style="font-size:7.5px">' . e($topCat) . '</td>
            <td class="center">' . ($vc ?: '-') . '</td>
            <td class="center"><span style="background:' . $sbg . ';color:' . $sfg . ';padding:2px 6px;border-radius:8px;font-size:7px;font-weight:700">' . $vs . '</span></td>
            <td style="font-size:7.5px">' . e($la['analysis_date'] ?? '-') . '</td>
            <td style="font-size:7px">' . e($la['analyst_name'] ?? '-') . '</td>
          </tr>';
    }

    /* Totals row */
    $html .= '<tr class="sum-row-total">
        <td></td><td class="bold">PROGRAM TOTAL</td><td></td>
        <td class="center bold">' . $totalGaps . '</td>
        <td class="center bold">' . $totalRes . '</td>
        <td class="center bold">' . $overallPct . '%</td>
        <td></td>
        <td class="center bold">' . $totalCrit . '</td>
        <td></td><td></td><td></td>
      </tr>';
    $html .= '</tbody></table>';

    /* Category breakdown */
    $html .= '
      <div class="section-header" style="margin-top:14px">Cross-Venture Gap Category Breakdown</div>
      <table>
        <thead>
          <tr>
            <th style="width:22%">Category</th>
            <th style="width:8%" class="center">Total</th>
            <th style="width:8%" class="center">Resolved</th>
            <th style="width:8%" class="center">In Progress</th>
            <th style="width:8%" class="center">Not Started</th>
            <th style="width:12%" class="center">Avg. Progress</th>
            <th style="width:8%" class="center">Critical</th>
            <th style="width:12%" class="center">Status</th>
          </tr>
        </thead>
        <tbody>';

    foreach ($categories as $catKey => $catLabel) {
        $cg   = array_values(array_filter($all_gaps, fn($g) => $g['gap_category'] === $catKey));
        $ct   = count($cg);
        $cr   = count(array_filter($cg, fn($g) => $g['status'] === 'resolved'));
        $ci   = count(array_filter($cg, fn($g) => $g['status'] === 'in_progress'));
        $cn   = $ct - $cr - $ci;
        $cpct = $ct > 0 ? round(array_sum(array_column($cg, 'pct_complete')) / $ct) : 0;
        $ccrit = count(array_filter($cg, fn($g) => $g['impact'] === 'H' && $g['urgency'] === 'H' && $g['status'] !== 'resolved'));
        $cs   = $cpct >= 70 ? 'On Track' : ($cpct >= 40 ? 'In Progress' : ($ct > 0 ? 'Needs Attention' : '-'));
        [$sfg, $sbg] = $cpct >= 70 ? ['#16a34a','#dcfce7'] : ($cpct >= 40 ? ['#d97706','#fef3c7'] : ($ct > 0 ? ['#dc2626','#fee2e2'] : ['#9ca3af','#f3f4f6']));
        $html .= '<tr>
            <td class="bold">' . e($catLabel) . '</td>
            <td class="center">' . ($ct ?: '-') . '</td>
            <td class="center">' . ($cr ?: '-') . '</td>
            <td class="center">' . ($ci ?: '-') . '</td>
            <td class="center">' . ($cn ?: '-') . '</td>
            <td class="center">' . ($ct > 0 ? $cpct . '%' : '-') . '</td>
            <td class="center">' . ($ccrit ?: '-') . '</td>
            <td class="center"><span style="background:' . $sbg . ';color:' . $sfg . ';padding:2px 6px;border-radius:8px;font-size:7px;font-weight:700">' . $cs . '</span></td>
          </tr>';
    }
    $html .= '</tbody></table>
      <div class="footer">EdTech Fellowship Program | Mastercard Foundation | Generated ' . date('d M Y H:i') . '</div>
    </div>';

    return $html;
}

/* ------------------------------------------------------
   BUILD HTML & RENDER
------------------------------------------------------ */
if ($type === 'venture' && $venture_id > 0) {
    if (!isset($ventures[$venture_id])) {
        http_response_code(404); die('Venture not found.');
    }
    $v      = $ventures[$venture_id];
    $vGaps  = array_values(array_filter($all_gaps, fn($g) => (int)$g['venture_id'] === $venture_id));
    $la     = $analysisMap[$venture_id] ?? null;
    $body   = buildVentureHtml($v, $vGaps, $categories, $la);
    $filename = 'GapReport_' . preg_replace('/[^a-z0-9]+/i', '_', $v['name']) . '_' . date('Ymd') . '.pdf';
    $isLandscape = true;

} else {
    /* All ventures + per-venture pages */
    $body = buildAllVenturesHtml($ventures, $all_gaps, $analysisMap, $categories);

    /* Per-venture pages */
    foreach ($ventures as $vid => $v) {
        $vGaps = array_values(array_filter($all_gaps, fn($g) => (int)$g['venture_id'] === $vid));
        $la    = $analysisMap[$vid] ?? null;
        $body .= '<div class="page-break"></div>';
        $body .= buildVentureHtml($v, $vGaps, $categories, $la);
    }
    $filename = 'GapReport_AllVentures_' . date('Ymd') . '.pdf';
    $isLandscape = true;
}

$fullHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $css . '</head><body>' . $body . '</body></html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');
$options->set('dpi', 120);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($fullHtml);
$dompdf->setPaper('A4', $isLandscape ? 'landscape' : 'portrait');
$dompdf->render();
$dompdf->stream($filename, ['Attachment' => true]);
exit;