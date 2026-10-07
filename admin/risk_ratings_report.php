<?php
declare(strict_types=1);

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
use Dompdf\Dompdf;
use Dompdf\Options;


$page_title = 'Risk Ratings Report';

$allowed_roles = ['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer', 'project Officer'];

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['role']) ||
    !in_array((string)$_SESSION['role'], $allowed_roles, true)
) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available.');
}

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}


$riskKeys = ['governance', 'operational', 'delivery', 'fiducial', 'safeguarding', 'reputational'];

$ratings = [];
$result  = $conn->query("
    SELECT
        rr.id                        AS rr_id,
        rr.application_id,
        rr.overall_rating,
        rr.overall_rating_label,
        rr.recommendation,
        rr.reviewer_comments,
        rr.prepared_by_name,
        rr.status,
        a.startup_name,
        o.opportunity_title,
        pu.full_name                 AS prepared_by_full_name
    FROM risk_ratings rr
    INNER JOIN applications a ON a.application_id = rr.application_id
    LEFT JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
    LEFT JOIN users pu ON pu.user_id = rr.prepared_by
    WHERE rr.status = 'generated'
    ORDER BY rr.updated_at DESC, rr.id DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ratings[] = $row;
    }
}

// Fetch per-section scores for all fetched ratings in one query
$sectionScores = [];
if (!empty($ratings)) {
    $ratingIds    = array_column($ratings, 'rr_id');
    $placeholders = implode(',', array_fill(0, count($ratingIds), '?'));
    $types        = str_repeat('i', count($ratingIds));

    $sStmt = $conn->prepare("
        SELECT risk_rating_id, pillar_key AS section_title, rating_score AS section_score
        FROM risk_rating_sections
        WHERE risk_rating_id IN ($placeholders)
    ");
    if ($sStmt) {
        $sStmt->bind_param($types, ...$ratingIds);
        $sStmt->execute();
        $sRes = $sStmt->get_result();
        while ($sRow = $sRes->fetch_assoc()) {
            $rrId  = (int)$sRow['risk_rating_id'];
            $title = strtolower(trim((string)$sRow['section_title']));
            $score = (float)$sRow['section_score'];
            foreach ($riskKeys as $key) {
                if (str_contains($title, $key)) {
                    $sectionScores[$rrId][$key] = $score;
                    break;
                }
            }
        }
        $sStmt->close();
    }
}

// Enrich each rating: attach scores + compute avg
foreach ($ratings as &$rr) {
    $rrId         = (int)$rr['rr_id'];
    $scores       = $sectionScores[$rrId] ?? [];
    $rr['scores'] = $scores;
    $total        = array_sum(array_values($scores));
    // Always divide by 6 (the 6 defined risk categories)
    $rr['avg_score'] = ($total > 0) ? round($total / 6, 2) : 0;
    $prep            = trim((string)($rr['prepared_by_full_name'] ?? $rr['prepared_by_name'] ?? ''));
    $rr['preparer']  = ($prep !== '') ? $prep : 'N/A';
}
unset($rr);

/* =========================================================================
   HELPERS
========================================================================= */
function scoreClass(float $v): string {
    if ($v <= 0)   return 'score-none';
    if ($v <= 1.5) return 'score-low';
    if ($v <= 2.5) return 'score-mid';
    return 'score-high';
}

function recClass(string $rec): string {
    $r = strtolower(trim($rec));
    if (in_array($r, ['proceed', 'yes for cohort one'], true))               return 'rec-proceed';
    if (str_contains($r, 'condition') || str_contains($r, 'maybe') || $r === 'hold') return 'rec-cond';
    if (in_array($r, ['decline', 'not yet ready'], true))                    return 'rec-decline';
    return '';
}

function fmtScore(float $v): string {
    if ($v <= 0) return '&#8211;';  // en-dash
    return ($v == (int)$v) ? (string)(int)$v : number_format($v, 1);
}

/* =========================================================================
   SCORE / REC CELL STYLE STRING  (used inside DOMPDF HTML)
========================================================================= */
function sCss(float $v): string {
    if ($v <= 0)   return 'background:#F2F2F2; color:#999;';
    if ($v <= 1.5) return 'background:#C6EFCE; color:#276221; font-weight:bold;';
    if ($v <= 2.5) return 'background:#FFEB9C; color:#7D4E17; font-weight:bold;';
    return 'background:#FFC7CE; color:#9C0006; font-weight:bold;';
}

function recCss(string $rec): string {
    $r = strtolower(trim($rec));
    if (in_array($r, ['proceed', 'yes for cohort one'], true))               return 'background:#C6EFCE; color:#276221;';
    if (str_contains($r, 'condition') || str_contains($r, 'maybe') || $r === 'hold') return 'background:#FFEB9C; color:#7D4E17;';
    if (in_array($r, ['decline', 'not yet ready'], true))                    return 'background:#FFC7CE; color:#9C0006;';
    return '';
}

/* =========================================================================
   PDF GENERATION  DOMPDF
   Triggered by ?pdf=1
========================================================================= */
if (isset($_GET['pdf']) && $_GET['pdf'] === '1') {

    // ---- Locate dompdf autoloader ----
    $autoloadCandidates = [
        __DIR__               . '/vendor/autoload.php',
        dirname(__DIR__)      . '/vendor/autoload.php',
        dirname(__DIR__, 2)   . '/vendor/autoload.php',
        dirname(__DIR__, 3)   . '/vendor/autoload.php',
    ];

    foreach ($autoloadCandidates as $al) {
        if (file_exists($al)) {
            require_once $al;
            break;
        }
    }

    if (!class_exists('Dompdf\Dompdf')) {
        ob_end_clean();
        $_SESSION['error'] = 'DOMPDF is not installed. Run <code>composer require dompdf/dompdf</code> in your project root, then try again.';
        header('Location: risk_ratings_report.php');
        exit();
    }

    

    // ---- Build HTML for DOMPDF ----
    $totalStartups = count($ratings);
    $generatedDate = date('d F Y, H:i');

    // Build table rows
    $rowsHtml = '';
    foreach ($ratings as $i => $rr) {
        $scores  = $rr['scores'];
        $avg     = $rr['avg_score'];
        $rec     = (string)($rr['recommendation']    ?? '');
        $comment = (string)($rr['reviewer_comments'] ?? '');
        $rowBg   = ($i % 2 === 0) ? '#FFFFFF' : '#D9E1F2';

        $gov  = (float)($scores['governance']   ?? 0);
        $oper = (float)($scores['operational']  ?? 0);
        $del  = (float)($scores['delivery']     ?? 0);
        $fid  = (float)($scores['fiducial']     ?? 0);
        $safe = (float)($scores['safeguarding'] ?? 0);
        $rep  = (float)($scores['reputational'] ?? 0);

        $rowsHtml .= '<tr class="legacy-style-a9e1d31654">';
        $rowsHtml .= '<td class="legacy-style-793b7ed444">'         . ($i + 1) . '</td>';
        $rowsHtml .= '<td class="legacy-style-c4b2bc0ed3">'                      . htmlspecialchars($rr['startup_name'] ?? '-', ENT_QUOTES) . '</td>';
        $rowsHtml .= '<td>'                                                . htmlspecialchars($rr['preparer'],              ENT_QUOTES) . '</td>';
        $rowsHtml .= '<td class="legacy-style-91d051c024">' . ($gov  > 0 ? (($gov  == (int)$gov)  ? (int)$gov  : number_format($gov,  1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-d49efc37af">' . ($oper > 0 ? (($oper == (int)$oper) ? (int)$oper : number_format($oper, 1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-9f4a43b594">' . ($del  > 0 ? (($del  == (int)$del)  ? (int)$del  : number_format($del,  1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-0c264dd3a2">' . ($fid  > 0 ? (($fid  == (int)$fid)  ? (int)$fid  : number_format($fid,  1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-93ffed172f">' . ($safe > 0 ? (($safe == (int)$safe) ? (int)$safe : number_format($safe, 1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-3c8c9cec61">' . ($rep  > 0 ? (($rep  == (int)$rep)  ? (int)$rep  : number_format($rep,  1)) : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-e82d780131">' . ($avg  > 0 ? number_format($avg, 2)                                          : '&ndash;') . '</td>';
        $rowsHtml .= '<td class="legacy-style-0fc6deeeb9">'                   . htmlspecialchars($rec  ?: '&ndash;', ENT_QUOTES) . '</td>';
        $rowsHtml .= '<td class="legacy-style-bc5bfcbcab">'           . htmlspecialchars($comment ?: '&ndash;', ENT_QUOTES) . '</td>';
        $rowsHtml .= '</tr>' . "\n";
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="12" class="legacy-style-f5fa8a76da">No generated risk ratings found.</td></tr>';
    }

    $pdfHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">

</head>
<body>

<div class="banner">
    <h1> RISK RATING REPORT - DUE DILIGENCE</h1>
    <div class="sub">
        Average score across 6 risk categories &nbsp;(scale: 1 = Low &nbsp;|&nbsp; 5 = High)
        &nbsp;&bull;&nbsp;
        Only startups with status: Generated
    </div>
</div>

<div class="meta">
    <span>Total startups: <strong>{$totalStartups}</strong></span>
    <span>Generated: {$generatedDate}</span>
</div>

<div class="legend">
    <table>
        <tr>
            <td class="lbl">Legend:</td>
            <td class="leg-low">&#9632;&nbsp; Avg 1.0&ndash;1.5 &mdash; Low Risk / Yes for Cohort One</td>
            <td class="leg-mid">&#9632;&nbsp; Avg 1.6&ndash;2.5 &mdash; Moderate Risk / Maybe</td>
            <td class="leg-high">&#9632;&nbsp; Avg 2.6&ndash;5.0 &mdash; High Risk / Not Yet Ready</td>
            <td class="leg-none">&#9632;&nbsp; No score recorded</td>
        </tr>
    </table>
</div>

<table class="rt">
    <thead>
        <tr>
            <th class="legacy-style-9d7e14142a">No</th>
            <th class="tl legacy-style-fd14ca83c7">Entity Name</th>
            <th class="tl legacy-style-006a6141a1">Prepared By</th>
            <th class="legacy-style-5db8347ca2">Governance<br>Risks</th>
            <th class="legacy-style-5db8347ca2">Operational<br>Risk</th>
            <th class="legacy-style-5db8347ca2">Delivery<br>Risk</th>
            <th class="legacy-style-5db8347ca2">Fiducial<br>Risk</th>
            <th class="legacy-style-8db5008c7a">Safeguarding<br>Risks</th>
            <th class="legacy-style-8db5008c7a">Reputational<br>Risk</th>
            <th class="legacy-style-5db8347ca2">Avg Score<br>(out of 5)</th>
            <th class="legacy-style-006a6141a1">Recommendation</th>
            <th class="tl legacy-style-8c69c0456b">Comments</th>
        </tr>
    </thead>
    <tbody>
        {$rowsHtml}
    </tbody>
</table>

<div class="foot">
    Avg Score = (Governance + Operational + Delivery + Fiducial + Safeguarding + Reputational) &divide; 6
    &nbsp;&nbsp;|&nbsp;&nbsp;
    Auto-generated from the Risk Rating Management System
</div>

</body>
</html>
HTML;

    // ---- DOMPDF render ----
    $options = new Options();
    $options->set('isRemoteEnabled',      false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont',          'DejaVu Sans');
    $options->set('chroot',               __DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($pdfHtml, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'Risk_Ratings_Report_' . date('Y-m-d') . '.pdf';

    ob_end_clean();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit();
}

/* =========================================================================
   HTML PREVIEW
========================================================================= */
require_once 'includes/header.php';

// Quick summary stats
$totalCount   = count($ratings);
$lowCount     = 0; $midCount = 0; $highCount = 0; $noScore = 0;
$proceedCount = 0; $holdCount = 0; $declineCount = 0;

foreach ($ratings as $rr) {
    $avg = $rr['avg_score'];
    if ($avg <= 0)       $noScore++;
    elseif ($avg <= 1.5) $lowCount++;
    elseif ($avg <= 2.5) $midCount++;
    else                 $highCount++;

    $rl = strtolower(trim($rr['recommendation'] ?? ''));
    if ($rl === 'proceed' || $rl === 'yes for cohort one')                        $proceedCount++;
    elseif (str_contains($rl,'condition') || str_contains($rl,'maybe') || $rl === 'hold') $holdCount++;
    elseif ($rl === 'decline' || $rl === 'not yet ready')                         $declineCount++;
}
?>



<div class="container-fluid py-4">
  <div class="rrr-wrap">

    <?php if (!empty($_SESSION['error'])): ?>
      <div class="alert alert-danger alert-dismissible fade show">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Page header -->
    <div class="rrr-header">
      <div>
        <h2><i class="fas fa-file-chart-bar me-2 text-primary"></i>Risk Ratings Report</h2>
        <div class="sub">All generated risk assessments - average across 6 risk categories</div>
      </div>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($totalCount > 0): ?>
          <a href="risk_ratings_report.php?pdf=1" class="btn-pdf">
            <i class="fas fa-file-pdf"></i> Download PDF Report
          </a>
        <?php endif; ?>
        <a href="manage_risk_ratings.php" class="btn btn-light border">
          <i class="fas fa-arrow-left me-1"></i> Back
        </a>
      </div>
    </div>

    <!-- Stats strip -->
    <div class="rrr-stats">
      <div class="rstat c-blue">
        <div class="sl">Total</div>
        <div class="sv"><?= $totalCount ?></div>
      </div>
      <div class="rstat c-green">
        <div class="sl">Low Risk</div>
        <div class="sv"><?= $lowCount ?></div>
      </div>
      <div class="rstat c-amber">
        <div class="sl">Moderate</div>
        <div class="sv"><?= $midCount ?></div>
      </div>
      <div class="rstat c-red">
        <div class="sl">High Risk</div>
        <div class="sv"><?= $highCount ?></div>
      </div>
      <div class="rstat">
        <div class="sl">No Score</div>
        <div class="sv"><?= $noScore ?></div>
      </div>
      <div class="rstat c-green">
        <div class="sl">Proceed</div>
        <div class="sv"><?= $proceedCount ?></div>
      </div>
      <div class="rstat c-amber">
        <div class="sl">Hold/Maybe</div>
        <div class="sv"><?= $holdCount ?></div>
      </div>
      <div class="rstat c-red">
        <div class="sl">Decline</div>
        <div class="sv"><?= $declineCount ?></div>
      </div>
    </div>

    <!-- Legend -->
    <div class="legend-row">
      <div class="legend-item"><span class="legend-swatch ls-low"></span>Avg 1.0-1.5 &nbsp;Low Risk / Yes for Cohort One</div>
      <div class="legend-item"><span class="legend-swatch ls-mid"></span>Avg 1.6-2.5 &nbsp;Moderate Risk / Maybe</div>
      <div class="legend-item"><span class="legend-swatch ls-high"></span>Avg 2.6-5.0 &nbsp;High Risk / Not Yet Ready</div>
      <div class="legend-item"><span class="legend-swatch ls-none"></span>No score recorded</div>
    </div>

    <!-- Table card -->
    <div class="rrr-card">
      <div class="rrr-card-head">
        <div>
          <div class="ctitle">
            <i class="fas fa-shield-alt me-2"></i> Risk Rating Report - Due Diligence
          </div>
          <div class="sub2">
            Avg = (Governance + Operational + Delivery + Fiducial + Safeguarding + Reputational) &divide; 6
            &nbsp;&bull;&nbsp; Scale: 1 = Low &rarr; 5 = High
          </div>
        </div>
        <span class="count-pill"><?= $totalCount ?> startup<?= $totalCount !== 1 ? 's' : '' ?></span>
      </div>

      <?php if ($totalCount === 0): ?>
        <div class="rrr-empty">
          <i class="fas fa-folder-open"></i>
          <div class="legacy-style-fc8ad139bf">No generated risk ratings found</div>
          <div class="legacy-style-3a3bbc6f70">
            Only risk ratings with status <strong>Generated</strong> appear here.
            Go to <a href="manage_risk_ratings.php">Manage Risk Ratings</a> to generate assessments.
          </div>
        </div>
      <?php else: ?>

        <div class="table-responsive">
          <table class="rrr-table">
            <thead>
              <tr>
                <th class="legacy-style-9d7e14142a">No</th>
                <th class="left legacy-style-e995ee2fab">Entity Name</th>
                <th class="left legacy-style-006a6141a1">Prepared By</th>
                <th class="legacy-style-5db8347ca2">Governance<br>Risks</th>
                <th class="legacy-style-5db8347ca2">Operational<br>Risk</th>
                <th class="legacy-style-5db8347ca2">Delivery<br>Risk</th>
                <th class="legacy-style-5db8347ca2">Fiducial<br>Risk</th>
                <th class="legacy-style-8db5008c7a">Safeguarding<br>Risks</th>
                <th class="legacy-style-8db5008c7a">Reputational<br>Risk</th>
                <th class="legacy-style-8db5008c7a">
                  Avg Score
                  <br><span class="legacy-style-a62a206fd5">(out of 5)</span>
                </th>
                <th class="legacy-style-006a6141a1">Recommendation</th>
                <th class="left legacy-style-8c69c0456b">Comments</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ratings as $i => $rr):
                $scores  = $rr['scores'];
                $avg     = $rr['avg_score'];
                $rec     = (string)($rr['recommendation']    ?? '');
                $comment = (string)($rr['reviewer_comments'] ?? '');
                $gov  = (float)($scores['governance']   ?? 0);
                $oper = (float)($scores['operational']  ?? 0);
                $del  = (float)($scores['delivery']     ?? 0);
                $fid  = (float)($scores['fiducial']     ?? 0);
                $safe = (float)($scores['safeguarding'] ?? 0);
                $rep  = (float)($scores['reputational'] ?? 0);
              ?>
              <tr>
                <td class="center legacy-style-65711d484a"><?= $i + 1 ?></td>
                <td class="legacy-style-534436e95e"><?= h($rr['startup_name'] ?? '-') ?></td>
                <td class="legacy-style-e48b05836a"><?= h($rr['preparer']) ?></td>
                <td class="<?= scoreClass($gov)  ?>"><?= fmtScore($gov)  ?></td>
                <td class="<?= scoreClass($oper) ?>"><?= fmtScore($oper) ?></td>
                <td class="<?= scoreClass($del)  ?>"><?= fmtScore($del)  ?></td>
                <td class="<?= scoreClass($fid)  ?>"><?= fmtScore($fid)  ?></td>
                <td class="<?= scoreClass($safe) ?>"><?= fmtScore($safe) ?></td>
                <td class="<?= scoreClass($rep)  ?>"><?= fmtScore($rep)  ?></td>
                <td class="<?= scoreClass($avg)  ?>"><?= $avg > 0 ? number_format($avg, 2) : '-' ?></td>
                <td class="<?= recClass($rec) ?>"><?= h($rec ?: '-') ?></td>
                <td class="legacy-style-ff23eda6da"><?= h($comment ?: '-') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="table-foot">
          <i class="fas fa-calculator me-1"></i>
          Avg Score = (Governance + Operational + Delivery + Fiducial + Safeguarding + Reputational) &divide; 6
          &nbsp;&nbsp;|&nbsp;&nbsp;
          Report generated: <?= date('d M Y, H:i') ?>
          &nbsp;&nbsp;|&nbsp;&nbsp;
          <?= $totalCount ?> startup<?= $totalCount !== 1 ? 's' : '' ?> displayed
        </div>

      <?php endif; ?>
    </div><!-- /.rrr-card -->

  </div><!-- /.rrr-wrap -->
</div><!-- /.container-fluid -->

<?php require_once 'includes/footer.php'; ?>