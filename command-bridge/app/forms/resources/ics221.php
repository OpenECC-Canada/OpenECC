<?php
declare(strict_types=1);
// ICS 221 Demobilization Check-Out for one resource on the Resources Unit page (?id=<resource_unit_intake id>).
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/forms/ics221/Ics221OfficialPdfFieldMapper.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) { ics_pdf_fail(400, 'No active incident.'); }
if (!current_user_can_resources_view($incidentId)) { ics_pdf_fail(403, 'Forbidden'); }

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM resource_unit_intake WHERE id = ? AND incident_id = ?');
$st->execute([$id, $incidentId]);
$intake = $st->fetch();
if (!$intake) { ics_pdf_fail(404, 'That resource could not be found.'); }
$st = $pdo->prepare('SELECT * FROM resource_demob_tracker WHERE resource_unit_intake_id = ? AND incident_id = ?');
$st->execute([$id, $incidentId]);
$demob = $st->fetch() ?: [];

$user = current_user();
$name = trim((string)($user['real_name'] ?? $user['display_name'] ?? $user['username'] ?? ''));
$roles = get_user_role_codes($incidentId, (int)($user['id'] ?? 0));
$preparedBy = $name . ($roles ? ', ' . implode('/', $roles) : '');

try {
    $built = Ics221OfficialPdfFieldMapper::build($incident, $intake, $demob, $preparedBy, date('Y-m-d H:i:s'));
    audit_log((int)($user['id'] ?? 0) ?: null, 'print', 'ICS221', $id, 'ICS 221 demobilization check-out printed for '
        . ((string)($intake['source_request_number'] ?? '') ?: 'resource #' . $id));
    ics_pdf_send_official_with_continuation('ICS221', [$built['fields']], $built['continuations'], ics_pdf_header_lines($incident, []),
        'ICS 221', 'ICS221_Demob_Checkout_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($intake['source_request_number'] ?? $id)) . '.pdf');
} catch (Throwable $e) {
    error_log('ICS 221 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 221 PDF could not be generated. Please contact the system administrator.');
}
exit;
