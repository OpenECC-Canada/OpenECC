<?php
declare(strict_types=1);
// Staging status board (#45): every received resource at a facility, with its staging status (available, assigned,
// out of service, released) and assignment. Kept by the Staging Area Manager (form code STAGING in Form Permissions).
// Tables resource_staging and resource_staging_history (migration 0017). Resources come from the Resources Unit
// (resource_unit_intake) once received or checked in; their facility defaults to the 213RR receiving location.
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/facilities.php';
require_login();
header('Cache-Control: no-store, private');

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$perms = $incidentId > 0 ? (!empty($user['is_sysadmin']) ? ['can_view' => 1, 'can_edit' => 1] : get_current_user_form_permissions('STAGING', $incidentId)) : [];
$canView = !empty($perms['can_view']) || !empty($perms['can_edit']) || !empty($perms['can_create']);
$canManage = !empty($perms['can_edit']) || !empty($perms['can_create']);
$self = app_url('/forms/staging/index.php');

const STAGING_STATUSES = ['available' => 'Available', 'assigned' => 'Assigned', 'out_of_service' => 'Out of service', 'released' => 'Released'];

if ($incidentId <= 0 || !$canView) {
    http_response_code($incidentId <= 0 ? 200 : 403);
    render_header('Staging');
    echo '<div class="card"><p>' . ($incidentId <= 0 ? 'No active incident.' : 'You do not have access to the staging board.') . '</p></div>';
    render_footer();
    exit;
}

$facilities = cb_facilities($incidentId);
$facilityIds = array_map(static fn($f) => (int)$f['id'], $facilities);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = (string)($_POST['back_facility'] ?? '');
    try {
        if (!$canManage) { throw new RuntimeException('You can view the staging board but not change it.'); }
        $intakeId = (int)($_POST['intake_id'] ?? 0);
        $st = $pdo->prepare('SELECT rui.id, rui.source_request_number, rui.resource_kind, rui.resource_type, er.location_id AS expected_location_id
            FROM resource_unit_intake rui LEFT JOIN external_checkin_expected_resources er ON er.resource_unit_intake_id = rui.id
            WHERE rui.id = ? AND rui.incident_id = ?');
        $st->execute([$intakeId, $incidentId]);
        $res = $st->fetch();
        if (!$res) { throw new RuntimeException('That resource could not be found.'); }
        $status = (string)($_POST['status'] ?? '');
        if (!isset(STAGING_STATUSES[$status])) { throw new RuntimeException('Choose a status.'); }
        $assignment = mb_substr(trim((string)($_POST['assignment'] ?? '')), 0, 255);
        if ($status === 'assigned' && $assignment === '') { throw new RuntimeException('Say what it is assigned to (for example "Division A").'); }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        $loc = (int)($_POST['location_id'] ?? 0);
        $loc = in_array($loc, $facilityIds, true) ? $loc : ((int)($res['expected_location_id'] ?? 0) ?: null);

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO resource_staging (incident_id, resource_unit_intake_id, location_id, status, assignment, note, arrived_at, status_changed_at, updated_by_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE location_id = VALUES(location_id), status = VALUES(status), assignment = VALUES(assignment), note = VALUES(note),
                    status_changed_at = NOW(), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()')
            ->execute([$incidentId, $intakeId, $loc, $status, $status === 'assigned' ? $assignment : ($assignment ?: null), $note ?: null, $userId ?: null]);
        $pdo->prepare('INSERT INTO resource_staging_history (incident_id, resource_unit_intake_id, location_id, status, assignment, note, changed_by_user_id, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$incidentId, $intakeId, $loc, $status, $assignment ?: null, $note ?: null, $userId ?: null]);
        $pdo->commit();
        $label = trim(((string)$res['source_request_number'] !== '' ? $res['source_request_number'] . ' ' : '') . trim($res['resource_kind'] . ' ' . $res['resource_type']));
        audit_log($userId, 'update', 'STAGING', $intakeId, 'Staging: ' . ($label ?: 'resource #' . $intakeId) . ' set to ' . STAGING_STATUSES[$status]
            . ($assignment !== '' ? ' (' . $assignment . ')' : '') . ($note !== '' ? '; ' . $note : ''));
        flash('success', ($label ?: 'Resource') . ': ' . STAGING_STATUSES[$status] . '.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($e instanceof RuntimeException)) { security_log($e, 'staging'); }
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The change could not be saved.');
    }
    header('Location: ' . $self . ($back !== '' ? '?facility=' . rawurlencode($back) : ''));
    exit;
}

// Which facility to show: ?facility=ID or "all"; default the first open staging area, else all.
$want = (string)($_GET['facility'] ?? '');
if ($want === '') {
    foreach ($facilities as $f) {
        if ((string)$f['lifecycle_status'] === 'active' && stripos((string)$f['location_type'], 'staging') !== false) { $want = (string)$f['id']; break; }
    }
    if ($want === '') { $want = 'all'; }
}
$showFacility = $want === 'all' ? 0 : (int)$want;
$showReleased = !empty($_GET['released']);

$sql = "SELECT rui.id, rui.source_request_number, rui.resource_category, rui.resource_kind, rui.resource_type, rui.resource_description,
        rui.quantity_received, rui.supplier_name, rui.received_at, rui.checked_in_at, rui.intake_status,
        er.location_id AS expected_location_id,
        rs.location_id AS staging_location_id, rs.status AS staging_status, rs.assignment, rs.note, rs.arrived_at, rs.status_changed_at,
        COALESCE(rs.location_id, er.location_id) AS at_location_id
    FROM resource_unit_intake rui
    LEFT JOIN external_checkin_expected_resources er ON er.resource_unit_intake_id = rui.id
    LEFT JOIN resource_staging rs ON rs.resource_unit_intake_id = rui.id
    WHERE rui.incident_id = ? AND rui.intake_status IN ('received','checked_in','converted')";
$args = [$incidentId];
if ($showFacility > 0) { $sql .= ' AND COALESCE(rs.location_id, er.location_id) = ?'; $args[] = $showFacility; }
if (!$showReleased) { $sql .= " AND COALESCE(rs.status, 'available') <> 'released'"; }
$st = $pdo->prepare($sql . " ORDER BY FIELD(COALESCE(rs.status, 'available'), 'available', 'assigned', 'out_of_service', 'released'), rui.resource_kind, rui.resource_type, rui.id");
$st->execute($args);
$rows = $st->fetchAll() ?: [];
$counts = array_fill_keys(array_keys(STAGING_STATUSES), 0);
foreach ($rows as $r) { $counts[(string)($r['staging_status'] ?: 'available')]++; }
$facilityNames = [];
foreach ($facilities as $f) { $facilityNames[(int)$f['id']] = (string)$f['location_name']; }

render_header('Staging');
?>
<div class="card">
  <h2>Staging</h2>
  <p class="small">Resources the Resources Unit has received or checked in, with their staging status. Set a resource to
    <strong>Assigned</strong> when it leaves for an assignment, <strong>Out of service</strong> when it can't be used, and
    <strong>Released</strong> when it is demobilized (print its ICS 221 from the Resources Unit page).</p>
  <form method="get" class="inline-form">
    <label>Facility
      <select name="facility" onchange="this.form.submit()">
        <option value="all"<?= $showFacility === 0 ? ' selected' : '' ?>>All facilities</option>
        <?php foreach ($facilities as $f): ?>
          <option value="<?= (int)$f['id'] ?>"<?= $showFacility === (int)$f['id'] ? ' selected' : '' ?>><?= e(cb_facility_label($f)) ?><?= (string)$f['lifecycle_status'] !== 'active' ? ' [' . e(cb_facility_statuses()[(string)$f['lifecycle_status']] ?? '') . ']' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="checkbox-line"><input type="checkbox" name="released" value="1"<?= $showReleased ? ' checked' : '' ?> onchange="this.form.submit()"> Show released</label>
    <noscript><button type="submit" class="btn btn-secondary">Show</button></noscript>
  </form>
  <div class="staging-counts">
    <?php foreach (STAGING_STATUSES as $k => $label): if ($k === 'released' && !$showReleased) { continue; } ?>
      <span class="staging-count staging-<?= e($k) ?>"><strong><?= (int)$counts[$k] ?></strong> <?= e($label) ?></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <p>No resources <?= $showFacility > 0 ? 'at this facility' : 'in staging' ?> yet. Resources appear here once the Resources Unit records their receipt.</p>
  <?php else: ?>
  <table class="data-table cb-cards">
    <thead><tr><th>Resource</th><th>Facility</th><th>Time in</th><th>Status</th><?php if ($canManage): ?><th>Change</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $status = (string)($r['staging_status'] ?: 'available');
        $timeIn = (string)($r['arrived_at'] ?: ($r['checked_in_at'] ?: $r['received_at']));
        $atLoc = (int)($r['at_location_id'] ?? 0);
    ?>
      <tr>
        <td><strong><?= e(trim((string)$r['resource_kind'] . ' ' . (string)$r['resource_type']) ?: 'Resource') ?></strong>
          <?php if (trim((string)$r['source_request_number']) !== ''): ?><br><span class="small"><?= e((string)$r['source_request_number']) ?></span><?php endif; ?>
          <?php if (trim((string)$r['resource_description']) !== ''): ?><br><span class="small"><?= e(mb_substr((string)$r['resource_description'], 0, 120)) ?></span><?php endif; ?>
          <?php if (trim((string)$r['supplier_name']) !== ''): ?><br><span class="small">From <?= e((string)$r['supplier_name']) ?></span><?php endif; ?></td>
        <td><?= e($facilityNames[$atLoc] ?? 'Not set') ?></td>
        <td><?= e($timeIn !== '' ? substr($timeIn, 0, 16) : '') ?></td>
        <td><span class="staging-badge staging-<?= e($status) ?>"><?= e(STAGING_STATUSES[$status] ?? $status) ?></span>
          <?php if (!empty($r['assignment'])): ?><br><span class="small"><?= e((string)$r['assignment']) ?></span><?php endif; ?>
          <?php if (!empty($r['note'])): ?><br><span class="small"><?= e((string)$r['note']) ?></span><?php endif; ?>
          <?php if (!empty($r['status_changed_at'])): ?><br><span class="small">since <?= e(substr((string)$r['status_changed_at'], 0, 16)) ?></span><?php endif; ?></td>
        <?php if ($canManage): ?>
        <td>
          <form method="post" class="staging-form"><?= csrf_field() ?>
            <input type="hidden" name="intake_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="back_facility" value="<?= e($showFacility > 0 ? (string)$showFacility : 'all') ?>">
            <select name="status" aria-label="Status">
              <?php foreach (STAGING_STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $status ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <input name="assignment" maxlength="255" value="<?= e((string)($r['assignment'] ?? '')) ?>" placeholder="Assignment (e.g. Division A)" aria-label="Assignment">
            <select name="location_id" aria-label="Facility">
              <?php if ($atLoc === 0 || !isset($facilityNames[$atLoc])): ?><option value="0" selected>Facility not set</option><?php endif; ?>
              <?php foreach ($facilities as $f): ?><option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $atLoc ? ' selected' : '' ?>><?= e((string)$f['location_name']) ?></option><?php endforeach; ?>
            </select>
            <input name="note" maxlength="255" placeholder="Note (optional)" aria-label="Note">
            <button type="submit" class="btn btn-secondary btn-small">Update</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
