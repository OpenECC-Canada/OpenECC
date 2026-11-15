<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once $appRoot . '/includes/ics233_actions.php';
require_once __DIR__ . '/Ics233OfficialPdfOverlay.php';

// The tracker is one list per incident, so there is no form record to require.
$ctx = ics_pdf_context('ICS233', false);
$incidentId = (int)$ctx['incident_id'];
$filled = ics213_filled_positions($incidentId);
$actions = ics233_actions($incidentId, false);
if (($_GET['include'] ?? '') === 'completed') {
    $actions = array_merge($actions, ics233_actions($incidentId, true));
    usort($actions, static fn($a, $b) => (int)$a['item_no'] <=> (int)$b['item_no']);
}
$rows = [];
foreach ($actions as $a) {
    $rows[] = [
        'item_no' => (int)$a['item_no'], 'item' => (string)$a['item'], 'poc' => ics233_poc_label($a, $filled), 'briefed' => !empty($a['briefed_at']),
        'start_date' => $a['start_date'], 'status_text' => (string)$a['status_text'], 'target_date' => $a['target_date'], 'actual_date' => $a['actual_date'],
    ];
}

try {
    $exporter = new OfficialPdfTemplateExporter();
    $tempDir = getenv('COMMAND_BRIDGE_PDF_TEMP_DIR') ?: sys_get_temp_dir();
    [$overlay, $pages] = Ics233OfficialPdfOverlay::write(ics_pdf_incident_name($ctx['incident']), $rows, $tempDir);
    $path = $exporter->stampTemplate('ICS233', $overlay, $pages);
    $filename = 'ICS233_Open_Action_Tracker_Incident_' . $incidentId . '.pdf';
    if (ics_pdf_preview_requested()) {
        $exporter->sendInline($path, $filename);
    } else {
        $exporter->sendDownload($path, $filename);
    }
} catch (Throwable $e) {
    error_log('ICS 233 official PDF export failed: ' . $e->getMessage());
    ics_pdf_fail(500, 'The ICS 233 PDF could not be generated. Please contact the system administrator.');
}
exit;
