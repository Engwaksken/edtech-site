<?php
declare(strict_types=1);

require_once __DIR__ . '/report-schemas.php';

function rs_get_notification_roles(): array
{
    $roles = ['admin', 'programs_lead', 'program_director', 'program_manager', 'consultant', 'meal_officer'];

    if (defined('MS_PRIVILEGED_ROLES') && is_array(MS_PRIVILEGED_ROLES)) {
        $roles = array_values(array_unique(array_merge($roles, MS_PRIVILEGED_ROLES)));
    }

    return $roles;
}


function rs_current_admin_role(): string
{
    return (string)($GLOBALS['ADMIN']['role'] ?? '');
}


function rs_is_stakeholder_role(?string $role = null): bool
{
    $role = $role !== null ? $role : rs_current_admin_role();

    if ($role === '') {
        return false;
    }

    return in_array($role, rs_get_notification_roles(), true);
}

function rs_h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rs_render_form(array $schema, array $values, array $context): string
{
    $html = '';
    foreach ($schema['sections'] as $section) {
        $html .= '<div class="rs-section"><h3 class="rs-section-title">' . rs_h($section['title']) . '</h3>';
        foreach ($section['fields'] as $field) {
            $html .= rs_render_field($field, $values[$field['key']] ?? null, $context);
        }
        $html .= '</div>';
    }
    return $html;
}

function rs_render_field(array $field, $value, array $context): string
{
    $key   = $field['key'];
    $label = $field['label'] ?? '';
    $name  = "field[{$key}]";

    switch ($field['type']) {

        case 'text':
        case 'date':
            $v = is_string($value) ? $value : ($field['default'] ?? '');
            $type = $field['type'] === 'date' ? 'date' : 'text';
            return '<div class="rs-field"><label>' . rs_h($label) . '</label>'
                 . '<input type="' . $type . '" name="' . rs_h($name) . '" value="' . rs_h((string)$v) . '"'
                 . (!empty($field['placeholder']) ? ' placeholder="' . rs_h($field['placeholder']) . '"' : '')
                 . ' class="rs-input"></div>';

        case 'textarea':
            $v = is_string($value) ? $value : ($field['default'] ?? '');
            return '<div class="rs-field"><label>' . rs_h($label) . '</label>'
                 . '<textarea name="' . rs_h($name) . '" rows="3" class="rs-input">' . rs_h((string)$v) . '</textarea></div>';

        case 'choice':
            $v = is_string($value) ? $value : '';
            $out = '<div class="rs-field"><label>' . rs_h($label) . '</label><div class="rs-pillrow">';
            foreach ($field['options'] as $opt) {
                $checked = ($opt === $v) ? 'checked' : '';
                $id = 'f_' . md5($name . $opt);
                $out .= '<label class="rs-pill"><input type="radio" name="' . rs_h($name) . '" id="' . $id . '" value="' . rs_h($opt) . '" ' . $checked . '> ' . rs_h($opt) . '</label>';
            }
            $out .= '</div></div>';
            return $out;

        case 'multi_choice':
            $v = is_array($value) ? $value : [];
            $out = '<div class="rs-field"><label>' . rs_h($label) . '</label><div class="rs-pillrow">';
            foreach ($field['options'] as $opt) {
                $checked = in_array($opt, $v, true) ? 'checked' : '';
                $out .= '<label class="rs-pill"><input type="checkbox" name="' . rs_h($name) . '[]" value="' . rs_h($opt) . '" ' . $checked . '> ' . rs_h($opt) . '</label>';
            }
            $out .= '</div></div>';
            return $out;

        case 'scale_table':
            return rs_render_scale_table($field, is_array($value) ? $value : []);

        case 'fixed_rows_table':
            return rs_render_fixed_rows_table($field, is_array($value) ? $value : []);

        case 'dynamic_table':
            return rs_render_dynamic_table($field, is_array($value) ? $value : [], $context);

        default:
            return '';
    }
}

function rs_render_scale_table(array $field, array $value): string
{
    $key = $field['key'];
    $out = '<div class="rs-field rs-scale-wrap"><label>' . rs_h($field['label']) . '</label>';
    $out .= '<table class="rs-table"><thead><tr><th>Criterion</th><th colspan="5" style="text-align:center">1&ndash;5</th><th>Comments</th></tr></thead><tbody>';
    $total = 0;
    foreach ($field['rows'] as $rowKey => $rowLabel) {
        $rowVal = $value[$rowKey] ?? ['score' => '', 'comment' => ''];
        $score  = (string)($rowVal['score'] ?? '');
        $comment = (string)($rowVal['comment'] ?? '');
        if ($score !== '') $total += (int)$score;
        $out .= '<tr><td>' . rs_h($rowLabel) . '</td>';
        for ($i = 1; $i <= 5; $i++) {
            $checked = ((string)$i === $score) ? 'checked' : '';
            $out .= '<td style="text-align:center"><input type="radio" name="field[' . rs_h($key) . '][' . rs_h($rowKey) . '][score]" value="' . $i . '" ' . $checked . '></td>';
        }
        $out .= '<td><input type="text" class="rs-input" name="field[' . rs_h($key) . '][' . rs_h($rowKey) . '][comment]" value="' . rs_h($comment) . '"></td></tr>';
    }
    $out .= '</tbody></table>';
    if (!empty($field['show_total'])) {
        $max = $field['max_total'] ?? (count($field['rows']) * 5);
        $out .= '<div class="rs-total">Total score: <strong class="rs-total-num" data-scale-total="' . rs_h($key) . '">' . (int)$total . '</strong> / ' . (int)$max . '</div>';
    }
    $out .= '</div>';
    return $out;
}

function rs_render_fixed_rows_table(array $field, array $value): string
{
    $key = $field['key'];
    $cols = $field['columns'];
    $out = '<div class="rs-field"><label>' . rs_h($field['label']) . '</label>';
    $out .= '<table class="rs-table"><thead><tr><th>' . rs_h($field['row_col_label'] ?? 'Item') . '</th>';
    foreach ($cols as $c) $out .= '<th>' . rs_h($c['label']) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($field['rows'] as $rowKey => $rowLabel) {
        $rowVal = $value[$rowKey] ?? [];
        $out .= '<tr><td>' . rs_h($rowLabel) . '</td>';
        foreach ($cols as $c) {
            $cv = (string)($rowVal[$c['key']] ?? '');
            $cname = 'field[' . $key . '][' . $rowKey . '][' . $c['key'] . ']';
            if ($c['type'] === 'choice') {
                $out .= '<td><select class="rs-input" name="' . rs_h($cname) . '"><option value="">--</option>';
                foreach ($c['options'] as $opt) {
                    $sel = ($opt === $cv) ? 'selected' : '';
                    $out .= '<option value="' . rs_h($opt) . '" ' . $sel . '>' . rs_h($opt) . '</option>';
                }
                $out .= '</select></td>';
            } else {
                $out .= '<td><input type="text" class="rs-input" name="' . rs_h($cname) . '" value="' . rs_h($cv) . '"></td>';
            }
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

function rs_render_dynamic_table(array $field, array $rows, array $context): string
{
    $key  = $field['key'];
    $cols = $field['columns'];
    $tblId = 'dt_' . $key;

    $renderRow = function (array $rowVal) use ($key, $cols, $context): string {
        $out = '<tr>';
        foreach ($cols as $c) {
            $cv = (string)($rowVal[$c['key']] ?? '');
            $cname = 'field[' . $key . '][][' . $c['key'] . ']';
            if ($c['type'] === 'choice') {
                $out .= '<td><select class="rs-input" name="' . rs_h($cname) . '"><option value="">--</option>';
                foreach ($c['options'] as $opt) {
                    $sel = ($opt === $cv) ? 'selected' : '';
                    $out .= '<option value="' . rs_h($opt) . '" ' . $sel . '>' . rs_h($opt) . '</option>';
                }
                $out .= '</select></td>';
            } elseif ($c['type'] === 'venture_select') {
                $out .= '<td><select class="rs-input" name="' . rs_h($cname) . '"><option value="">Select venture</option>';
                foreach ($context['ventures'] ?? [] as $v) {
                    $sel = ((string)$v['id'] === $cv) ? 'selected' : '';
                    $out .= '<option value="' . (int)$v['id'] . '" ' . $sel . '>' . rs_h($v['name']) . '</option>';
                }
                $out .= '</select></td>';
            } elseif ($c['type'] === 'date') {
                $out .= '<td><input type="date" class="rs-input" name="' . rs_h($cname) . '" value="' . rs_h($cv) . '"></td>';
            } elseif ($c['type'] === 'textarea') {
                $out .= '<td><textarea class="rs-input" rows="1" name="' . rs_h($cname) . '">' . rs_h($cv) . '</textarea></td>';
            } else {
                $out .= '<td><input type="text" class="rs-input" name="' . rs_h($cname) . '" value="' . rs_h($cv) . '"></td>';
            }
        }
        $out .= '<td class="rs-row-actions"><button type="button" class="rs-row-remove" onclick="rsRemoveRow(this)">&times;</button></td></tr>';
        return $out;
    };

    $out = '<div class="rs-field"><label>' . rs_h($field['label']) . '</label>';
    $out .= '<table class="rs-table rs-dyn-table" id="' . $tblId . '"><thead><tr>';
    foreach ($cols as $c) $out .= '<th>' . rs_h($c['label']) . '</th>';
    $out .= '<th></th></tr></thead><tbody>';

    if (empty($rows)) $rows = [[]]; 
    foreach ($rows as $rowVal) $out .= $renderRow(is_array($rowVal) ? $rowVal : []);

    $out .= '</tbody></table>';
  
    $out .= '<template id="' . $tblId . '_tpl"><table><tbody>' . $renderRow([]) . '</tbody></table></template>';
    $out .= '<button type="button" class="btn btn-sm btn-secondary rs-add-row" data-target="' . $tblId . '"><i class="fa fa-plus"></i> Add row</button>';
    $out .= '</div>';
    return $out;
}


function rs_engine_js(): string
{
    return <<<'JS'
<script>
function rsRemoveRow(btn){
    const tr = btn.closest('tr');
    const tbody = tr.parentElement;
    if (tbody.querySelectorAll('tr').length > 1) tr.remove();
    else tr.querySelectorAll('input,textarea,select').forEach(el=>{ if(el.type==='checkbox'||el.type==='radio') el.checked=false; else el.value=''; });
}
document.addEventListener('click', function(e){
    const btn = e.target.closest('.rs-add-row');
    if (!btn) return;
    const tableId = btn.dataset.target;
    const table = document.getElementById(tableId);
    const tpl = document.getElementById(tableId + '_tpl');
    if (!table || !tpl) return;
    const newRow = tpl.content.querySelector('tr').cloneNode(true);
    table.querySelector('tbody').appendChild(newRow);
});
document.addEventListener('input', function(e){
    const wrap = e.target.closest('.rs-scale-wrap');
    if (!wrap) return;
    const totalEl = wrap.querySelector('[data-scale-total]');
    if (!totalEl) return;
    let sum = 0;
    wrap.querySelectorAll('input[type=radio]:checked').forEach(r => sum += parseInt(r.value, 10) || 0);
    totalEl.textContent = sum;
});
</script>
JS;
}


function rs_engine_css(): string
{
    return <<<'CSS'
<style>
.rs-section{background:#fff;border:1.5px solid var(--border,#e5e7eb);border-radius:12px;padding:18px 20px;margin-bottom:16px}
.rs-section-title{font-size:13.5px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#c2410c;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #fed7aa}
.rs-field{margin-bottom:14px}
.rs-field > label{display:block;font-size:12.5px;font-weight:700;color:var(--text,#111827);margin-bottom:6px}
.rs-input{width:100%;padding:7px 10px;border:1.5px solid var(--border,#e5e7eb);border-radius:7px;font-size:13px;font-family:'DM Sans',sans-serif}
textarea.rs-input{resize:vertical;min-height:44px}
.rs-pillrow{display:flex;flex-wrap:wrap;gap:8px}
.rs-pill{display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1.5px solid var(--border,#e5e7eb);border-radius:20px;padding:5px 12px;font-size:12.5px;font-weight:600;cursor:pointer}
.rs-pill input{margin:0}
.rs-table{width:100%;border-collapse:collapse;margin-top:4px}
.rs-table th,.rs-table td{border:1px solid var(--border,#e5e7eb);padding:6px 8px;font-size:12px;vertical-align:top}
.rs-table th{background:#fff7ed;color:#7c2d12;font-weight:700;text-align:left}
.rs-row-actions{text-align:center;width:30px}
.rs-row-remove{border:none;background:#fee2e2;color:#991b1b;border-radius:6px;width:22px;height:22px;cursor:pointer;font-weight:700}
.rs-total{margin-top:8px;font-size:13px;background:#fff7ed;border-radius:8px;padding:6px 10px;display:inline-block}
.rs-add-row{margin-top:8px}
</style>
CSS;
}



function rs_collect(array $schema): array
{
    $posted = $_POST['field'] ?? [];
    $data = [];

    foreach ($schema['sections'] as $section) {
        foreach ($section['fields'] as $field) {
            $key = $field['key'];
            $raw = $posted[$key] ?? null;

            switch ($field['type']) {
                case 'multi_choice':
                    $data[$key] = is_array($raw) ? array_values(array_map('strval', $raw)) : [];
                    break;

                case 'choice':
                case 'text':
                case 'date':
                case 'textarea':
                    $data[$key] = is_string($raw) ? trim($raw) : '';
                    break;

                case 'scale_table':
                    $clean = [];
                    if (is_array($raw)) {
                        foreach ($raw as $rowKey => $rowVal) {
                            $clean[$rowKey] = [
                                'score'   => isset($rowVal['score']) ? (int)$rowVal['score'] : null,
                                'comment' => trim((string)($rowVal['comment'] ?? '')),
                            ];
                        }
                    }
                    $data[$key] = $clean;
                    break;

                case 'fixed_rows_table':
                    $clean = [];
                    if (is_array($raw)) {
                        foreach ($raw as $rowKey => $rowVal) {
                            $rc = [];
                            foreach ($field['columns'] as $c) {
                                $rc[$c['key']] = trim((string)($rowVal[$c['key']] ?? ''));
                            }
                            $clean[$rowKey] = $rc;
                        }
                    }
                    $data[$key] = $clean;
                    break;

                case 'dynamic_table':
                    $clean = [];
                    if (is_array($raw)) {
                        foreach ($raw as $rowVal) {
                            if (!is_array($rowVal)) continue;
                            $hasContent = false;
                            $rc = [];
                            foreach ($field['columns'] as $c) {
                                $v = trim((string)($rowVal[$c['key']] ?? ''));
                                if ($v !== '') $hasContent = true;
                                $rc[$c['key']] = $v;
                            }
                            if ($hasContent) $clean[] = $rc;
                        }
                    }
                    $data[$key] = $clean;
                    break;
            }
        }
    }

    return $data;
}

function rs_scale_total(array $field, array $data): int
{
    $sum = 0;
    foreach (($data[$field['key']] ?? []) as $row) {
        if (isset($row['score'])) $sum += (int)$row['score'];
    }
    return $sum;
}



function rs_render_pdf_html(array $schema, array $meta, array $data): string
{
    $esc = 'rs_h';
    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
    $html .= 'body{font-family:DejaVu Sans, sans-serif;font-size:10px;color:#1f2937}';
    $html .= 'h1{font-size:16px;color:#c2410c;margin:0 0 2px}';
    $html .= 'h2{font-size:10px;color:#6b7280;margin:0 0 14px;font-weight:normal}';
    $html .= 'h3{font-size:11.5px;background:#fff7ed;color:#7c2d12;padding:5px 8px;margin:16px 0 8px;border-left:4px solid #ea580c}';
    $html .= 'table{width:100%;border-collapse:collapse;margin-bottom:6px}';
    $html .= 'th,td{border:1px solid #e5e7eb;padding:4px 6px;text-align:left;vertical-align:top;font-size:9.5px}';
    $html .= 'th{background:#fff7ed;color:#7c2d12}';
    $html .= '.meta-row{margin-bottom:3px}.meta-lbl{font-weight:bold;display:inline-block;width:170px}';
    $html .= '.footerbar{margin-top:20px}.bar1{background:#f59e0b;height:6px}.bar2{background:#dc2626;height:6px}';
    $html .= '</style></head><body>';

    $html .= '<h1>Hive Colab &mdash; Mastercard Foundation EdTech Fellowship</h1>';
    $html .= '<h2>' . $esc($schema['meta']['label'] ?? '') . ' &middot; Generated ' . $esc(date('j M Y, g:ia')) . '</h2>';

    $html .= '<div style="margin-bottom:12px">';
    foreach ($meta as $label => $val) {
        if ($val === null || $val === '') continue;
        $html .= '<div class="meta-row"><span class="meta-lbl">' . $esc((string)$label) . ':</span> ' . $esc((string)$val) . '</div>';
    }
    $html .= '</div>';

    foreach ($schema['sections'] as $section) {
        $html .= '<h3>' . $esc($section['title']) . '</h3>';
        foreach ($section['fields'] as $field) {
            $html .= rs_render_field_pdf($field, $data[$field['key']] ?? null);
        }
    }

    $html .= '<div class="footerbar"><div class="bar1"></div><div class="bar2"></div></div>';
    $html .= '</body></html>';

    return $html;
}

function rs_render_field_pdf(array $field, $value): string
{
    $label = rs_h($field['label'] ?? '');

    switch ($field['type']) {
        case 'text':
        case 'date':
            return '<p><strong>' . $label . ':</strong> ' . rs_h((string)$value) . '</p>';

        case 'textarea':
            return '<p><strong>' . $label . ':</strong><br>' . nl2br(rs_h((string)$value)) . '</p>';

        case 'choice':
            return '<p><strong>' . $label . ':</strong> ' . rs_h((string)$value) . '</p>';

        case 'multi_choice':
            $v = is_array($value) ? $value : [];
            return '<p><strong>' . $label . ':</strong> ' . rs_h(implode(', ', $v)) . '</p>';

        case 'scale_table':
            $rows = is_array($value) ? $value : [];
            $out = '<table><thead><tr><th>Criterion</th><th>Score</th><th>Comments</th></tr></thead><tbody>';
            $total = 0;
            foreach ($field['rows'] as $rowKey => $rowLabel) {
                $score = $rows[$rowKey]['score'] ?? '';
                $comment = $rows[$rowKey]['comment'] ?? '';
                if ($score !== '' && $score !== null) $total += (int)$score;
                $out .= '<tr><td>' . rs_h($rowLabel) . '</td><td>' . rs_h((string)$score) . '/5</td><td>' . rs_h((string)$comment) . '</td></tr>';
            }
            $out .= '</tbody></table>';
            if (!empty($field['show_total'])) {
                $max = $field['max_total'] ?? (count($field['rows']) * 5);
                $out .= '<p><strong>Total score:</strong> ' . (int)$total . ' / ' . (int)$max . '</p>';
            }
            return $out;

        case 'fixed_rows_table':
            $rows = is_array($value) ? $value : [];
            $cols = $field['columns'];
            $out = '<table><thead><tr><th>' . rs_h($field['row_col_label'] ?? 'Item') . '</th>';
            foreach ($cols as $c) $out .= '<th>' . rs_h($c['label']) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($field['rows'] as $rowKey => $rowLabel) {
                $out .= '<tr><td>' . rs_h($rowLabel) . '</td>';
                foreach ($cols as $c) $out .= '<td>' . rs_h((string)($rows[$rowKey][$c['key']] ?? '')) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
            return $out;

        case 'dynamic_table':
            $rows = is_array($value) ? $value : [];
            $cols = $field['columns'];
            if (empty($rows)) return '<p><strong>' . $label . ':</strong> <em>None recorded.</em></p>';
            $out = '<p><strong>' . $label . '</strong></p><table><thead><tr>';
            foreach ($cols as $c) $out .= '<th>' . rs_h($c['label']) . '</th>';
            $out .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $out .= '<tr>';
                foreach ($cols as $c) {
                    $cv = $row[$c['key']] ?? '';
                    if ($c['type'] === 'venture_select' && $cv !== '') {
                        $cv = $GLOBALS['RS_VENTURE_NAME_LOOKUP'][(int)$cv] ?? $cv;
                    }
                    $out .= '<td>' . rs_h((string)$cv) . '</td>';
                }
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
            return $out;

        default:
            return '';
    }
}


function rs_save_report(
    mysqli $conn,
    string $type,
    int $mentorId,
    ?int $ventureId,
    ?int $cohortId,
    ?int $sessionId,
    ?string $periodLabel,
    string $title,
    array $payload,
    string $status = 'submitted'
): int {
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $submittedAt = $status === 'submitted' ? date('Y-m-d H:i:s') : null;

    $st = $conn->prepare("
        INSERT INTO structured_reports
            (report_type, mentor_id, venture_id, cohort_id, session_id, period_label, title, payload, status, submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$st) return 0;

    $st->bind_param(
        'siiiisssss',
        $type, $mentorId, $ventureId, $cohortId, $sessionId, $periodLabel, $title, $payloadJson, $status, $submittedAt
    );
    $ok = $st->execute();
    $id = $ok ? (int)$conn->insert_id : 0;
    $st->close();

    return $id;
}

function rs_save_pdf_path(mysqli $conn, int $reportId, string $pdfPath): void
{
    $st = $conn->prepare("UPDATE structured_reports SET pdf_path = ? WHERE id = ? LIMIT 1");
    if (!$st) return;
    $st->bind_param('si', $pdfPath, $reportId);
    $st->execute();
    $st->close();
}


function rs_generate_pdf(array $schema, array $meta, array $data, int $reportId): ?string
{
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) return null;
    require_once $autoload;
    if (!class_exists('\\Dompdf\\Dompdf')) return null;

    $dest_dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__, 2) . '/uploads', '/') . '/structured-reports/';
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

    $filename = 'report_' . $reportId . '_' . uniqid() . '.pdf';
    $html = rs_render_pdf_html($schema, $meta, $data);

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html);
    $dompdf->render();

    file_put_contents($dest_dir . $filename, $dompdf->output());

    return 'uploads/structured-reports/' . $filename;
}



function rs_privileged_recipients(mysqli $conn): array
{
    $roles = rs_get_notification_roles();
    if (empty($roles)) return [];

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));

    $sql = "
        SELECT full_name, email, role
        FROM admin_users
        WHERE role IN ($placeholders)
          AND status IN ('1','active')
          AND email IS NOT NULL AND email != ''
    ";
    $st = $conn->prepare($sql);
    if (!$st) return [];

    $st->bind_param($types, ...$roles);
    $st->execute();
    $res = $st->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $out[] = $row;
    }
    $st->close();

    return $out;
}


function rs_notify_stakeholders(mysqli $conn, int $reportId, string $reportLabel, string $mentorName, string $ventureName = ''): array
{
    $recipients = rs_privileged_recipients($conn);
    $sent = 0;
    $failed = 0;

    $subject = 'New ' . $reportLabel . ' submitted' . ($ventureName !== '' ? ' - ' . $ventureName : '');
    $safeMentor = rs_h($mentorName);
    $safeVenture = rs_h($ventureName);
    $safeLabel = rs_h($reportLabel);

    $content = "
        <h3>New mentor report submitted</h3>
        <p>{$safeMentor} has submitted a <strong>{$safeLabel}</strong>" . ($ventureName !== '' ? " for <strong>{$safeVenture}</strong>" : '') . ".</p>
        <p>Please log in to review the full report.</p>
    ";
    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;
    $altBody = "{$mentorName} has submitted a {$reportLabel}" . ($ventureName !== '' ? " for {$ventureName}" : '') . ".\n\nPlease log in to review the full report.";

    foreach ($recipients as $r) {
        $ok = false;
        $err = '';

        if (function_exists('sendEmail')) {
            $result = sendEmail($r['email'], $subject, $body, $altBody);
            $ok = ($result === true);
            if (!$ok) $err = (string)$result;
        } else {
            $err = 'sendEmail() not available';
        }

        if ($ok) $sent++; else $failed++;

        $log = $conn->prepare("
            INSERT INTO structured_report_notifications (report_id, recipient_name, recipient_email, recipient_role, channel, sent, error)
            VALUES (?, ?, ?, ?, 'email', ?, ?)
        ");
        if ($log) {
            $sentInt = $ok ? 1 : 0;
            $log->bind_param('isssis', $reportId, $r['full_name'], $r['email'], $r['role'], $sentInt, $err);
            $log->execute();
            $log->close();
        }

        // In-app system notification (best-effort; table may not exist yet).
        $sysTitle = 'New ' . $reportLabel;
        $sysBody  = $mentorName . ' submitted a ' . $reportLabel . ($ventureName !== '' ? ' for ' . $ventureName : '');
        @$conn->query("
            INSERT INTO admin_notifications (role, title, body, link)
            VALUES ('" . $conn->real_escape_string($r['role']) . "', '" . $conn->real_escape_string($sysTitle) . "', '"
            . $conn->real_escape_string($sysBody) . "', 'mentor-reports.php?view_report=" . (int)$reportId . "')
        ");
    }

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($recipients)];
}

/* ================================================================
   LISTING / LOADING HELPERS for the UI
================================================================ */

function rs_list_reports(mysqli $conn, array $filters): array
{
    $where = [];
    $params = [];
    $types = '';

    if (!empty($filters['report_type'])) {
        $where[] = 'sr.report_type = ?';
        $params[] = $filters['report_type'];
        $types .= 's';
    }
    if (!empty($filters['mentor_id'])) {
        $where[] = 'sr.mentor_id = ?';
        $params[] = (int)$filters['mentor_id'];
        $types .= 'i';
    }
    if (!empty($filters['venture_id'])) {
        $where[] = 'sr.venture_id = ?';
        $params[] = (int)$filters['venture_id'];
        $types .= 'i';
    }

    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT sr.*, m.full_name AS mentor_name, v.name AS venture_name
        FROM structured_reports sr
        LEFT JOIN mentors m ON m.id = sr.mentor_id
        LEFT JOIN ventures v ON v.id = sr.venture_id
        $ws
        ORDER BY sr.created_at DESC
    ";
    $st = $conn->prepare($sql);
    if (!$st) return [];
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $st->close();

    return $out;
}

/* ================================================================
   REVIEW WORKFLOW -- approve / reject / request changes, with
   comments, applied uniformly to structured_reports AND the legacy
   mentor_reports table. Decision authority is deliberately a
   different (smaller) list than the notification list: Consultant is
   notified and can view/comment, but does not render this decision.
================================================================ */

function rs_get_review_roles(): array
{
    return ['super_admin', 'admin', 'meal_officer', 'programs_lead', 'program_director', 'program_manager'];
}

/**
 * True if the current user may approve/reject/request-changes on a
 * mentor report -- either because ms_can_manage() already grants that
 * (unchanged from before), or because their admin role is one of
 * rs_get_review_roles() above.
 */
function rs_can_review(): bool
{
    if (function_exists('ms_can_manage') && ms_can_manage()) {
        return true;
    }

    return in_array(rs_current_admin_role(), rs_get_review_roles(), true);
}

/**
 * Emails the mentor who owns a report once a decision has been made on
 * it. $reportLabel is the human template name (e.g. "Final Startup
 * Report") used in the subject/body; $reportTitle is the specific
 * report's title. Best-effort -- silently no-ops if sendEmail() or the
 * mentor's email address isn't available.
 */
function rs_notify_mentor_of_decision(
    mysqli $conn,
    int $mentorId,
    string $reportLabel,
    string $reportTitle,
    string $decision,
    string $comment,
    string $reviewerName
): void {
    if ($mentorId <= 0 || !function_exists('sendEmail')) {
        return;
    }

    $st = $conn->prepare("SELECT full_name, email FROM mentors WHERE id = ? LIMIT 1");
    if (!$st) return;
    $st->bind_param('i', $mentorId);
    $st->execute();
    $mentor = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$mentor || empty($mentor['email']) || !filter_var($mentor['email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $actionLabels = [
        'approved'          => 'approved',
        'rejected'          => 'rejected',
        'changes_requested' => 'requested changes on',
    ];
    $actionLabel = $actionLabels[$decision] ?? 'reviewed';

    $subject     = ucfirst($actionLabel) . ': ' . $reportTitle;
    $safeName    = rs_h((string)$mentor['full_name']);
    $safeBy      = rs_h($reviewerName);
    $safeTitle   = rs_h($reportTitle);
    $safeAction  = rs_h($actionLabel);
    $safeComment = rs_h($comment);
    $safeLabel   = rs_h($reportLabel);

    $content = "
        <h3>Update on your {$safeLabel}</h3>
        <p>Hello {$safeName},</p>
        <p>{$safeBy} has {$safeAction} your report.</p>
        <div class='info-box'>
            <p><strong>Report:</strong> {$safeTitle}</p>
    ";

    if ($comment !== '') {
        $content .= "<p><strong>Comment:</strong> {$safeComment}</p>";
    }

    $content .= "
        </div>
        <p>Please log in to view the full details.</p>
    ";

    $body = function_exists('email_wrapper') ? email_wrapper($content) : $content;

    $altBody = "Hello {$mentor['full_name']},\n\n"
        . "{$reviewerName} has {$actionLabel} your report: {$reportTitle}\n"
        . ($comment !== '' ? "Comment: {$comment}\n\n" : "\n")
        . "Please log in to view the full details.";

    sendEmail($mentor['email'], $subject, $body, $altBody);
}

/**
 * Applies a review decision to a structured_reports row: updates
 * status/reviewed_by/reviewed_at, logs it to structured_report_reviews,
 * and notifies the owning mentor. Returns the affected row (for the
 * caller's flash message) or null if the report wasn't found.
 */
function rs_review_structured_report(
    mysqli $conn,
    int $reportId,
    string $decision,
    string $comment,
    string $reviewerName
): ?array {
    $st = $conn->prepare("SELECT * FROM structured_reports WHERE id = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('i', $reportId);
    $st->execute();
    $report = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$report) return null;

    $st = $conn->prepare("UPDATE structured_reports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? LIMIT 1");
    if (!$st) return null;
    $st->bind_param('ssi', $decision, $reviewerName, $reportId);
    $ok = $st->execute();
    $st->close();

    if (!$ok) return null;

    $log = $conn->prepare("
        INSERT INTO structured_report_reviews (report_id, author_type, author_name, action, comment_text)
        VALUES (?, 'admin', ?, ?, ?)
    ");
    if ($log) {
        $log->bind_param('isss', $reportId, $reviewerName, $decision, $comment);
        $log->execute();
        $log->close();
    }

    $types = rs_report_types();
    $label = $types[$report['report_type']]['label'] ?? 'report';

    rs_notify_mentor_of_decision(
        $conn,
        (int)$report['mentor_id'],
        $label,
        (string)$report['title'],
        $decision,
        $comment,
        $reviewerName
    );

    return $report;
}

/* ================================================================
   OVERVIEW -- combined stats + recent-reports table shown at the top
   of the page for stakeholders/managers (everyone apart from plain
   mentors, who only need their own tab-scoped view).
================================================================ */

function rs_overview_stats(mysqli $conn): array
{
    $buckets = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'changes_requested' => 0, 'draft' => 0];

    $res = $conn->query("SELECT status, COUNT(*) AS c FROM structured_reports GROUP BY status");
    while ($res && ($row = $res->fetch_assoc())) {
        $key = $row['status'] === 'submitted' ? 'pending' : $row['status'];
        if (isset($buckets[$key])) $buckets[$key] += (int)$row['c'];
    }

    $chk = $conn->query("SHOW TABLES LIKE 'mentor_reports'");
    if ($chk && $chk->num_rows) {
        $res = $conn->query("SELECT status, COUNT(*) AS c FROM mentor_reports GROUP BY status");
        while ($res && ($row = $res->fetch_assoc())) {
            $key = $row['status'] === 'pending' ? 'pending' : $row['status'];
            if (isset($buckets[$key])) $buckets[$key] += (int)$row['c'];
        }
    }

    return $buckets;
}

/**
 * Merges structured_reports + legacy mentor_reports into one
 * newest-first list for the overview table. Each row carries a `kind`
 * (report_type slug, or 'legacy_document') so the caller can build the
 * right badge/download link/tab-link per row.
 */
function rs_overview_list(mysqli $conn, int $limit = 50): array
{
    $limit = max(1, $limit);
    $out   = [];

    $res = $conn->query("
        SELECT sr.id, sr.report_type AS kind, sr.title, sr.status, sr.submitted_at, sr.created_at,
               m.full_name AS mentor_name, v.name AS venture_name, sr.pdf_path
        FROM structured_reports sr
        LEFT JOIN mentors m ON m.id = sr.mentor_id
        LEFT JOIN ventures v ON v.id = sr.venture_id
        ORDER BY sr.created_at DESC
        LIMIT {$limit}
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $row['source'] = 'structured';
        $out[] = $row;
    }

    $chk = $conn->query("SHOW TABLES LIKE 'mentor_reports'");
    if ($chk && $chk->num_rows) {
        $res = $conn->query("
            SELECT mr.id, 'legacy_document' AS kind, mr.title, mr.status,
                   mr.created_at AS submitted_at, mr.created_at,
                   m.full_name AS mentor_name, NULL AS venture_name, mr.file_path AS pdf_path
            FROM mentor_reports mr
            LEFT JOIN mentors m ON m.id = mr.mentor_id
            ORDER BY mr.created_at DESC
            LIMIT {$limit}
        ");
        while ($res && ($row = $res->fetch_assoc())) {
            $row['source'] = 'legacy';
            $out[] = $row;
        }
    }

    usort($out, fn($a, $b) => strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']));

    return array_slice($out, 0, $limit);
}