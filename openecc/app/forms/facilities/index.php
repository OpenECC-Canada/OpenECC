<?php
declare(strict_types=1);
// Incident facilities (includes/facilities.php). Replaces Settings > Check-in Locations: the same locations, with
// facility details, managed by the Facilities Unit (form code FACILITIES in Form Permissions).
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
$canView = current_user_can_facilities_view($incidentId);
$canManage = current_user_can_facilities_manage($incidentId);
$self = app_url('/forms/facilities/index.php');

if ($incidentId <= 0 || !$canView) {
    http_response_code($incidentId <= 0 ? 200 : 403);
    render_header('Facilities');
    echo '<div class="card"><p>' . ($incidentId <= 0 ? 'No active incident.' : 'You do not have access to the facility list.') . '</p></div>';
    render_footer();
    exit;
}

$statuses = cb_facility_statuses();
$services = cb_facility_services();

/** A number from a form field, or null. */
function facilities_num($v, bool $decimal = false)
{
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) { return null; }
    return $decimal ? round((float)$v, 6) : max(0, (int)$v);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['facility_id'] ?? 0);
    try {
        if (!$canManage) { throw new RuntimeException('You can view the facility list but not change it.'); }
        $f = $id > 0 ? cb_facility($incidentId, $id) : null;
        if ($id > 0 && !$f) { throw new RuntimeException('That facility could not be found.'); }

        if ($action === 'save') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160);
            if ($name === '') { throw new RuntimeException('Enter the facility name.'); }
            $type = mb_substr(trim((string)($_POST['type'] ?? '')), 0, 80) ?: 'Other';
            $lat = facilities_num($_POST['latitude'] ?? '', true);
            $lng = facilities_num($_POST['longitude'] ?? '', true);
            if (($lat !== null && ($lat < -90 || $lat > 90)) || ($lng !== null && ($lng < -180 || $lng > 180))) {
                throw new RuntimeException('Latitude must be between -90 and 90, and longitude between -180 and 180.');
            }
            $svc = array_values(array_intersect(array_keys($services), array_map('strval', (array)($_POST['services'] ?? []))));
            $fields = [
                'location_name' => $name,
                'location_type' => $type,
                'street_address' => mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255),
                'is_checkin_point' => !empty($_POST['is_checkin_point']) ? 1 : 0,
                'latitude' => $lat,
                'longitude' => $lng,
                'capacity_people' => facilities_num($_POST['capacity_people'] ?? ''),
                'capacity_vehicles' => facilities_num($_POST['capacity_vehicles'] ?? ''),
                'hours_text' => mb_substr(trim((string)($_POST['hours_text'] ?? '')), 0, 100) ?: null,
                'contact_name' => mb_substr(trim((string)($_POST['contact_name'] ?? '')), 0, 150) ?: null,
                'contact_phone' => mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 50) ?: null,
                'services' => $svc ? implode(',', $svc) : null,
                'accessibility' => mb_substr(trim((string)($_POST['accessibility'] ?? '')), 0, 255) ?: null,
                'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
            ];
            $pdo->beginTransaction();
            if ($f) {
                $set = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($fields)));
                $pdo->prepare("UPDATE external_checkin_locations SET {$set}, updated_by = ?, updated_at = NOW() WHERE id = ? AND incident_id = ?")
                    ->execute(array_merge(array_values($fields), [$userId, (int)$f['id'], $incidentId]));
                $fid = (int)$f['id'];
                cb_facility_event($incidentId, 'location_updated', ['location_id' => $fid]);
                $msg = $name . ' saved.';
            } else {
                // access_code_hash stays non-null in the schema: Companion Check-In uses each user's PIN, so store an
                // unrecoverable placeholder (as Check-in Locations did).
                $cols = array_keys($fields);
                $pdo->prepare('INSERT INTO external_checkin_locations (incident_id, ' . implode(', ', $cols) . ', location_token, access_code_hash, lifecycle_status, created_by, updated_by)
                        VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, "draft", ?, ?)')
                    ->execute(array_merge([$incidentId], array_values($fields),
                        [bin2hex(random_bytes(16)), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $userId, $userId]));
                $fid = (int)$pdo->lastInsertId();
                cb_facility_event($incidentId, 'location_created', ['location_id' => $fid]);
                $msg = $name . ' added as Planned. Set it to Open when it is ready.';
            }
            $pdo->commit();
            audit_log($userId, $f ? 'update' : 'create', 'FACILITIES', $fid, ($f ? 'Facility changed: ' : 'Facility added: ') . $name . ' (' . $type . ')'
                . ($fields['is_checkin_point'] ? ', check-in point' : ''));
            flash('success', $msg . (cb_facility_queue_sync($fid) ?? ''));
            header('Location: ' . $self . '#f' . $fid);
            exit;
        }

        if ($action === 'status' && $f) {
            $status = (string)($_POST['status'] ?? '');
            if (!isset($statuses[$status])) { throw new RuntimeException('Unknown status.'); }
            $extra = '';
            if ($status === 'active' && empty($f['opened_at'])) { $extra = ', opened_at = NOW(), closed_at = NULL'; }
            elseif ($status === 'active') { $extra = ', closed_at = NULL'; }
            elseif ($status === 'closed') { $extra = ', closed_at = NOW()'; }
            $pdo->prepare("UPDATE external_checkin_locations SET lifecycle_status = ?{$extra}, updated_by = ?, updated_at = NOW() WHERE id = ? AND incident_id = ?")
                ->execute([$status, $userId, (int)$f['id'], $incidentId]);
            cb_facility_event($incidentId, 'location_status_changed', ['location_id' => (int)$f['id'], 'status' => $status]);
            audit_log($userId, 'update', 'FACILITIES', (int)$f['id'], 'Facility ' . $f['location_name'] . ' set to ' . $statuses[$status]);
            flash('success', $f['location_name'] . ' is now ' . $statuses[$status] . '.' . (cb_facility_queue_sync((int)$f['id']) ?? ''));
            header('Location: ' . $self . '#f' . (int)$f['id']);
            exit;
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($e instanceof RuntimeException)) { security_log($e, 'facilities'); }
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The facility could not be saved.');
        header('Location: ' . $self . ($id > 0 ? '?edit=' . $id : ''));
        exit;
    }
}

$facilities = cb_facilities($incidentId);
$editId = (int)($_GET['edit'] ?? 0);
$editing = $canManage && $editId > 0 ? cb_facility($incidentId, $editId) : null;
$adding = $canManage && isset($_GET['new']);
$types = cb_facility_types();

render_header('Facilities');
?>
<script src="<?= e(app_url('/assets/js/external_checkin_qr.js')) ?>" defer></script>
<div class="card">
  <h2>Facilities</h2>
  <p class="small">Every facility for this incident: the ECC, reception centres, staging areas, camps and so on. Facilities marked
    as a <strong>check-in point</strong> are where people and resources check in (Companion Check-In, the Check-In Gateway, and the
    ICS 213RR receiving location). Contact details and notes stay on this server and are never sent to the Check-In Gateway.</p>
  <?php if ($canManage): ?><p><a class="btn btn-primary" href="<?= e($self . '?new=1#facility-form') ?>">Add a facility</a></p><?php endif; ?>
</div>

<?php if ($editing || $adding): $cur = $editing ?? ['is_checkin_point' => 0, 'location_type' => 'Staging Area']; $curSvc = explode(',', (string)($cur['services'] ?? '')); ?>
<div class="card" id="facility-form">
  <h3><?= $editing ? 'Change ' . e((string)$editing['location_name']) : 'Add a facility' ?></h3>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="facility_id" value="<?= (int)($cur['id'] ?? 0) ?>">
    <label>Name<input name="name" maxlength="160" required value="<?= e((string)($cur['location_name'] ?? '')) ?>"></label>
    <label>Type
      <select name="type">
        <?php $curType = (string)($cur['location_type'] ?? ''); $seen = false; foreach ($types as $t): $sel = strcasecmp($t, $curType) === 0; $seen = $seen || $sel; ?>
          <option value="<?= e($t) ?>"<?= $sel ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; if (!$seen && $curType !== ''): ?><option value="<?= e($curType) ?>" selected><?= e($curType) ?></option><?php endif; ?>
      </select>
    </label>
    <label class="span-2">Address<input name="address" maxlength="255" value="<?= e((string)($cur['street_address'] ?? '')) ?>"></label>
    <label class="checkbox-line span-2"><input type="checkbox" name="is_checkin_point" value="1"<?= !empty($cur['is_checkin_point']) ? ' checked' : '' ?>>
      Check-in point (people and resources check in here; shown on the Check-In Gateway when Open)</label>
    <label>Latitude<input name="latitude" inputmode="decimal" value="<?= e((string)($cur['latitude'] ?? '')) ?>" placeholder="e.g. 52.8394"></label>
    <label>Longitude<input name="longitude" inputmode="decimal" value="<?= e((string)($cur['longitude'] ?? '')) ?>" placeholder="e.g. -110.8571"></label>
    <label>Capacity (people)<input type="number" min="0" name="capacity_people" value="<?= e((string)($cur['capacity_people'] ?? '')) ?>"></label>
    <label>Capacity (vehicles)<input type="number" min="0" name="capacity_vehicles" value="<?= e((string)($cur['capacity_vehicles'] ?? '')) ?>"></label>
    <label>Hours<input name="hours_text" maxlength="100" value="<?= e((string)($cur['hours_text'] ?? '')) ?>" placeholder="e.g. 24 hours, or 07:00-22:00"></label>
    <span></span>
    <label>Contact name<input name="contact_name" maxlength="150" value="<?= e((string)($cur['contact_name'] ?? '')) ?>"></label>
    <label>Contact phone<input name="contact_phone" maxlength="50" value="<?= e((string)($cur['contact_phone'] ?? '')) ?>"></label>
    <fieldset class="span-2"><legend>Services</legend>
      <?php foreach ($services as $k => $label): ?>
        <label class="checkbox-line" style="display:inline-flex;margin-right:12px"><input type="checkbox" name="services[]" value="<?= e($k) ?>"<?= in_array($k, $curSvc, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="span-2">Accessibility<input name="accessibility" maxlength="255" value="<?= e((string)($cur['accessibility'] ?? '')) ?>" placeholder="e.g. ramp at the north door, accessible washroom"></label>
    <label class="span-2">Notes (stay on this server)<textarea name="notes" rows="3"><?= e((string)($cur['notes'] ?? '')) ?></textarea></label>
    <p class="form-actions span-2">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Save' : 'Add facility' ?></button>
      <a class="btn" href="<?= e($self) ?>">Cancel</a>
    </p>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Facilities (<?= count($facilities) ?>)</h3>
  <?php if (!$facilities): ?>
    <p>No facilities yet.<?= $canManage ? ' Press <strong>Add a facility</strong> to start with the ECC.' : '' ?></p>
  <?php else: ?>
  <table class="data-table cb-cards">
    <thead><tr><th>Facility</th><th>Status</th><th>Capacity</th><th>Contact</th><th>Services</th><th>Check-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($facilities as $f):
        $fid = (int)$f['id'];
        $st = (string)$f['lifecycle_status'];
        $svcLabels = array_values(array_intersect_key($services, array_flip(array_filter(explode(',', (string)($f['services'] ?? ''))))));
        $publicUrl = !empty($f['is_checkin_point']) && $st === 'active' ? cb_facility_public_url((string)$f['location_token']) : '';
        $hasMap = $f['latitude'] !== null && $f['longitude'] !== null;
    ?>
      <tr id="f<?= $fid ?>">
        <td><strong><?= e((string)$f['location_name']) ?></strong><br><span class="small"><?= e((string)$f['location_type']) ?><?= trim((string)$f['street_address']) !== '' ? ' &middot; ' . e((string)$f['street_address']) : '' ?></span>
          <?php if ($hasMap): ?><br><a class="small" target="_blank" rel="noopener" href="<?= e('https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$f['latitude']) . '&mlon=' . rawurlencode((string)$f['longitude']) . '#map=16/' . rawurlencode((string)$f['latitude']) . '/' . rawurlencode((string)$f['longitude'])) ?>">Map</a><?php endif; ?>
          <?php if (trim((string)($f['accessibility'] ?? '')) !== ''): ?><br><span class="small">Accessibility: <?= e((string)$f['accessibility']) ?></span><?php endif; ?>
          <?php if (trim((string)($f['notes'] ?? '')) !== ''): ?><br><span class="small"><?= nl2br(e((string)$f['notes'])) ?></span><?php endif; ?></td>
        <td><span class="facility-status facility-status-<?= e($st) ?>"><?= e($statuses[$st] ?? $st) ?></span>
          <?php if (!empty($f['hours_text'])): ?><br><span class="small"><?= e((string)$f['hours_text']) ?></span><?php endif; ?>
          <?php if (!empty($f['opened_at'])): ?><br><span class="small">Opened <?= e(substr((string)$f['opened_at'], 0, 16)) ?></span><?php endif; ?>
          <?php if ($st === 'closed' && !empty($f['closed_at'])): ?><br><span class="small">Closed <?= e(substr((string)$f['closed_at'], 0, 16)) ?></span><?php endif; ?></td>
        <td><?= $f['capacity_people'] !== null ? (int)$f['capacity_people'] . ' people' : '' ?><?= $f['capacity_vehicles'] !== null ? ($f['capacity_people'] !== null ? '<br>' : '') . (int)$f['capacity_vehicles'] . ' vehicles' : '' ?></td>
        <td><?= e((string)($f['contact_name'] ?? '')) ?><?php if (!empty($f['contact_phone'])): ?><br><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$f['contact_phone'])) ?>"><?= e((string)$f['contact_phone']) ?></a><?php endif; ?></td>
        <td class="small"><?= e(implode(', ', $svcLabels)) ?></td>
        <td><?php if (empty($f['is_checkin_point'])): ?><span class="small">No</span>
          <?php else: ?>Yes
            <?php if ($canManage): ?>
            <details><summary class="small">Public link and QR</summary>
              <?php if ($st !== 'active'): ?><p class="small">Public check-in is available once this facility is Open.</p>
              <?php elseif ($publicUrl === ''): ?><p class="small">A public entry URL could not be determined. Connect the Check-In Gateway on the server with <code>sudo tools/setup_checkin.sh</code>.</p>
              <?php else: ?>
                <p><a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener noreferrer">Open public check-in page</a></p>
                <div class="external-checkin-url-row">
                  <input id="external-checkin-url-<?= $fid ?>" readonly value="<?= e($publicUrl) ?>" aria-label="Public check-in URL">
                  <button type="button" class="btn btn-secondary btn-small" data-external-checkin-copy="<?= e($publicUrl) ?>">Copy link</button>
                </div>
                <canvas id="external-checkin-qr-<?= $fid ?>" class="external-checkin-qr" data-external-checkin-qr="<?= e($publicUrl) ?>" aria-label="QR code for <?= e((string)$f['location_name']) ?>"></canvas>
                <div class="external-checkin-qr-actions"><button type="button" class="btn btn-secondary btn-small" data-external-checkin-download="external-checkin-qr-<?= $fid ?>" data-location-name="<?= e((string)$f['location_name']) ?>">Download QR code</button></div>
              <?php endif; ?>
            </details>
            <?php endif; ?>
          <?php endif; ?></td>
        <td><?php if ($canManage): ?>
          <a class="btn btn-small" href="<?= e($self . '?edit=' . $fid . '#facility-form') ?>">Edit</a>
          <form method="post" class="inline-form" style="margin-top:6px"><?= csrf_field() ?>
            <input type="hidden" name="action" value="status"><input type="hidden" name="facility_id" value="<?= $fid ?>">
            <select name="status" aria-label="Status for <?= e((string)$f['location_name']) ?>">
              <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $st ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-small">Set</button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
