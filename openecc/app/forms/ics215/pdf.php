<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once __DIR__ . '/Ics215OfficialPdfFieldMapper.php';

$ctx = ics_pdf_context('ICS215');
$pdo = db();
$formRecordId = (int)$ctx['form_record']['id'];
$st = $pdo->prepare('SELECT * FROM form_215_headers WHERE form_record_id = ? LIMIT 1');
$st->execute([$formRecordId]);
$header = $st->fetch() ?: [];
$cols = json_decode((string)($header['resource_columns'] ?? ''), true);
$header['resource_columns'] = is_array($cols) ? $cols : [];
$st = $pdo->prepare('SELECT * FROM form_215_rows WHERE form_record_id = ? ORDER BY row_order, id');
$st->execute([$formRecordId]);
$rows = [];
foreach ($st->fetchAll() as $r) {
    $r['resources'] = (array)json_decode((string)($r['resources_json'] ?? ''), true);
    $rows[] = $r;
}
$op = $ctx['op'];
$opId = (int)($ctx['form_record']['operational_period_id'] ?? 0);
if ($opId > 0) {
    $op = get_operational_period_by_id($ctx['incident_id'], $opId) ?: $op;
}

try {
    $built = Ics215OfficialPdfFieldMapper::build($header, $ctx['incident'], $op, $rows);
    ics_pdf_send_official_with_continuation('ICS215', $built['pages'], $built['continuations'],
        ics_pdf_header_lines($ctx['incident'], $op), 'ICS 215', 'ICS215_Operational_Planning_Worksheet_Incident_' . $ctx['incident_id'] . '.pdf');
} catch (Throwable $e) {
    error_log('ICS 215 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 215 PDF could not be generated. Please contact the system administrator.');
}
exit;
