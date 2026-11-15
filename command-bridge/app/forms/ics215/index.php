<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once __DIR__ . '/Ics215OfficialPdfFieldMapper.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS215', $incidentId) : get_form_permission_defaults('ICS215');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
if ($incidentId <= 0 || !$canView) {
    http_response_code($incidentId <= 0 ? 200 : 403);
    render_header('ICS 215 - Operational Planning Worksheet');
    echo '<div class="card"><p>' . ($incidentId <= 0 ? 'No active incident.' : 'You do not have access to the ICS 215.') . '</p></div>';
    render_footer();
    exit;
}

$op = current_operational_period($incidentId) ?: [];
$opId = (int)($op['id'] ?? 0);
$formRecord = get_form_record($incidentId, 'ICS215', $opId ?: null);
$formRecordId = (int)($formRecord['id'] ?? 0);
$preparer = resolve_form_preparer($incidentId, 'ICS215');
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$error = null;
const ICS215_COLS = Ics215OfficialPdfFieldMapper::COLUMNS;

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS215', $incidentId, $opId, $formRecordId);

/** A whole number 0-9999 from a form field, or null when left empty. */
function ics215_count($v): ?int
{
    $v = trim((string)$v);
    return $v === '' || !is_numeric($v) ? null : max(0, min(9999, (int)$v));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    try {
        $pdo->beginTransaction();
        if ($formRecordId <= 0) {
            $formRecordId = get_or_create_form_record($incidentId, 'ICS215', '215', $userId, $opId ?: null);
        }
        $cols = [];
        for ($c = 0; $c < ICS215_COLS; $c++) { $cols[] = mb_substr(trim((string)($_POST['cols'][$c] ?? '')), 0, 60); }
        $preparedAt = trim((string)($_POST['prepared_at'] ?? ''));
        $preparedAt = $preparedAt !== '' && strtotime($preparedAt) ? date('Y-m-d H:i:s', strtotime($preparedAt)) : date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO form_215_headers (form_record_id, resource_columns, prepared_by_name, prepared_by_position, prepared_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE resource_columns = VALUES(resource_columns), prepared_by_name = VALUES(prepared_by_name),
                    prepared_by_position = VALUES(prepared_by_position), prepared_at = VALUES(prepared_at), updated_at = NOW()')
            ->execute([$formRecordId, json_encode($cols, JSON_UNESCAPED_UNICODE), mb_substr(trim((string)($_POST['prepared_by_name'] ?? '')), 0, 150),
                mb_substr(trim((string)($_POST['prepared_by_position'] ?? '')), 0, 150), $preparedAt]);

        $pdo->prepare('DELETE FROM form_215_rows WHERE form_record_id = ?')->execute([$formRecordId]);
        $ins = $pdo->prepare('INSERT INTO form_215_rows (form_record_id, row_order, branch, division_group, work_assignment, resources_json, overhead,
                special_equipment, reporting_location, arrival_time, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $order = 0;
        foreach ((array)($_POST['rows'] ?? []) as $r) {
            if (!is_array($r) || !empty($r['remove'])) { continue; }
            $res = [];
            for ($c = 0; $c < ICS215_COLS; $c++) {
                $req = ics215_count($r['res'][$c]['req'] ?? '');
                $have = ics215_count($r['res'][$c]['have'] ?? '');
                if ($req !== null || $have !== null) { $res[(string)$c] = ['req' => $req, 'have' => $have]; }
            }
            $text = array_map(static fn($k) => trim((string)($r[$k] ?? '')),
                ['branch', 'division_group', 'work_assignment', 'overhead', 'special_equipment', 'reporting_location', 'arrival_time']);
            if (implode('', $text) === '' && !$res) { continue; }
            [$branch, $div, $work, $overhead, $equip, $loc, $arrival] = $text;
            $ins->execute([$formRecordId, ++$order, mb_substr($branch, 0, 100), mb_substr($div, 0, 100), $work, json_encode($res),
                $overhead, $equip, mb_substr($loc, 0, 255), mb_substr($arrival, 0, 50)]);
        }
        $pdo->prepare('UPDATE form_records SET updated_at = NOW() WHERE id = ?')->execute([$formRecordId]);
        $pdo->commit();
        audit_log($userId, 'update', 'ICS215', $formRecordId, 'ICS 215 saved: ' . $order . ' work assignment(s)');
        flash('success', 'ICS 215 saved.');
        header('Location: ' . app_url('/forms/ics215/index.php'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        security_log($e, 'ics215_save');
        $error = 'The ICS 215 could not be saved.';
    }
}

$header = [];
$rows = [];
if ($formRecordId > 0) {
    $st = $pdo->prepare('SELECT * FROM form_215_headers WHERE form_record_id = ?');
    $st->execute([$formRecordId]);
    $header = $st->fetch() ?: [];
    $st = $pdo->prepare('SELECT * FROM form_215_rows WHERE form_record_id = ? ORDER BY row_order, id');
    $st->execute([$formRecordId]);
    $rows = $st->fetchAll() ?: [];
}
$cols = json_decode((string)($header['resource_columns'] ?? ''), true);
$cols = is_array($cols) ? array_pad(array_slice($cols, 0, ICS215_COLS), ICS215_COLS, '') : array_fill(0, ICS215_COLS, '');
$usedCols = array_keys(array_filter($cols, static fn($l) => trim((string)$l) !== ''));
$rows[] = [];   // one empty assignment to fill in

// Totals (blocks 11-13).
$totals = [];
foreach ($rows as $r) {
    foreach ((array)json_decode((string)($r['resources_json'] ?? ''), true) as $c => $v) {
        [$req, $have, $need] = Ics215OfficialPdfFieldMapper::numbers($v);
        if ($req === null && $have === null) { continue; }
        $totals[(int)$c] = [($totals[(int)$c][0] ?? 0) + (int)$req, ($totals[(int)$c][1] ?? 0) + (int)$have, ($totals[(int)$c][2] ?? 0) + (int)$need];
    }
}
$roleTitle = '';
foreach (get_user_role_codes($incidentId, $userId) as $rc) { if (in_array(strtoupper($rc), ['OSC', 'PSC', 'IC', 'UC', 'DIC'], true)) { $roleTitle = strtoupper($rc); break; } }
$roleNames = ['OSC' => 'Operations Section Chief', 'PSC' => 'Planning Section Chief', 'IC' => 'Incident Commander', 'UC' => 'Unified Command', 'DIC' => 'Deputy Incident Commander'];

render_header('ICS 215 - Operational Planning Worksheet');
render_ics_form_status('ICS215', $formRecord, $preparer, $signature, ['error' => $error, 'show_print' => false, 'show_pdf' => true,
    'pdf_url' => '/forms/ics215/pdf.php', 'op_id' => $opId, 'incident_id' => $incidentId]);
?>
<form method="post" id="ics215-form">
<?= csrf_field() ?>
<div class="card">
  <h2>ICS 215 - Operational Planning Worksheet</h2>
  <p class="small">The Operations Section Chief plans the next operational period here: each work assignment, the resources it
    needs and what is already on hand. The assignments carry over to the <a href="<?= e(app_url('/forms/ics204/index.php')) ?>">ICS 204</a>,
    the hazards are reviewed on the <a href="<?= e(app_url('/forms/ics215a/index.php')) ?>">ICS 215A</a>, and "Need" tells Logistics what to order.</p>
  <div class="grid-2">
    <label>1. Incident Name<input value="<?= e(trim((string)($incident['incident_name'] ?? $incident['name'] ?? ''))) ?>" readonly></label>
    <label>2. Operational Period<input value="<?= e($op ? operational_period_label($op) : '') ?>" readonly></label>
  </div>
</div>

<div class="card">
  <h3>6. Resources (columns)</h3>
  <p class="small">Name up to 12 resource kinds and types, for example "Engine T3" or "Crew T2". Only named columns show in the assignments below.</p>
  <div class="ics215-cols">
    <?php for ($c = 0; $c < ICS215_COLS; $c++): ?>
      <label><span class="small">Column <?= $c + 1 ?></span><input name="cols[<?= $c ?>]" maxlength="60" value="<?= e((string)$cols[$c]) ?>"></label>
    <?php endfor; ?>
  </div>
  <?php if (!$usedCols): ?><p class="flash warning">Name at least one resource column and press <strong>Save</strong> to enter the numbers.</p><?php endif; ?>
</div>

<?php foreach ($rows as $i => $r):
    $res = (array)json_decode((string)($r['resources_json'] ?? ''), true);
    $isNew = !$r;
?>
<fieldset class="card ics215-row">
  <legend><?= $isNew ? 'New work assignment' : 'Work assignment ' . ($i + 1) ?></legend>
  <div class="grid-2">
    <label>3. Branch<input name="rows[<?= $i ?>][branch]" maxlength="100" value="<?= e((string)($r['branch'] ?? '')) ?>"></label>
    <label>4. Division, Group or Other<input name="rows[<?= $i ?>][division_group]" maxlength="100" value="<?= e((string)($r['division_group'] ?? '')) ?>"></label>
  </div>
  <label>5. Work Assignment and Special Instructions<textarea name="rows[<?= $i ?>][work_assignment]" rows="3"><?= e((string)($r['work_assignment'] ?? '')) ?></textarea></label>
  <?php if ($usedCols): ?>
  <table class="ics215-res">
    <thead><tr><th></th><?php foreach ($usedCols as $c): ?><th><?= e((string)$cols[$c]) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach (['req' => 'Req.', 'have' => 'Have'] as $k => $label): ?>
      <tr><th><?= e($label) ?></th>
        <?php foreach ($usedCols as $c): $v = $res[(string)$c][$k] ?? ''; ?>
          <td><input type="number" min="0" max="9999" step="1" inputmode="numeric" name="rows[<?= $i ?>][res][<?= $c ?>][<?= $k ?>]" value="<?= e($v === null ? '' : (string)$v) ?>"
            data-col="<?= $c ?>" data-kind="<?= $k ?>" aria-label="<?= e($label . ' ' . $cols[$c]) ?>"></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      <tr class="ics215-need"><th>Need</th>
        <?php foreach ($usedCols as $c): [, , $need] = Ics215OfficialPdfFieldMapper::numbers($res[(string)$c] ?? []); ?>
          <td data-need-col="<?= $c ?>"><?= $need === null ? '' : (int)$need ?></td>
        <?php endforeach; ?></tr>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="grid-2">
    <label>7. Overhead Position(s)<textarea name="rows[<?= $i ?>][overhead]" rows="2"><?= e((string)($r['overhead'] ?? '')) ?></textarea></label>
    <label>8. Special Equipment and Supplies<textarea name="rows[<?= $i ?>][special_equipment]" rows="2"><?= e((string)($r['special_equipment'] ?? '')) ?></textarea></label>
    <label>9. Reporting Location<input name="rows[<?= $i ?>][reporting_location]" maxlength="255" value="<?= e((string)($r['reporting_location'] ?? '')) ?>"></label>
    <label>10. Requested Arrival Time<input name="rows[<?= $i ?>][arrival_time]" maxlength="50" value="<?= e((string)($r['arrival_time'] ?? '')) ?>" placeholder="e.g. 06:00"></label>
  </div>
  <?php if (!$isNew): ?><label class="checkbox-line"><input type="checkbox" name="rows[<?= $i ?>][remove]" value="1"> Remove this assignment when saving</label><?php endif; ?>
</fieldset>
<?php endforeach; ?>

<div class="card">
  <?php if ($usedCols): ?>
  <h3>Totals</h3>
  <table class="ics215-res">
    <thead><tr><th></th><?php foreach ($usedCols as $c): ?><th><?= e((string)$cols[$c]) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach (['11. Total required', '12. Total have on hand', '13. Total need to order'] as $k => $label): ?>
        <tr><th><?= e($label) ?></th><?php foreach ($usedCols as $c): ?><td><?= isset($totals[$c]) ? (int)$totals[$c][$k] : '' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small">Totals update when you save.</p>
  <?php endif; ?>
  <h3>14. Prepared by</h3>
  <div class="grid-2">
    <label>Name<input name="prepared_by_name" maxlength="150" value="<?= e((string)($header['prepared_by_name'] ?? ($user['real_name'] ?? $user['display_name'] ?? $user['username'] ?? ''))) ?>"></label>
    <label>Position / Title<input name="prepared_by_position" maxlength="150" value="<?= e((string)($header['prepared_by_position'] ?? ($roleNames[$roleTitle] ?? ''))) ?>"></label>
    <label>Date / Time<input type="datetime-local" name="prepared_at" value="<?= e(str_replace(' ', 'T', substr((string)($header['prepared_at'] ?? date('Y-m-d H:i')), 0, 16))) ?>"></label>
  </div>
  <?php if ($canEdit): ?>
    <p class="form-actions"><button type="submit" class="btn btn-primary">Save</button></p>
    <p class="small">To add another assignment, fill in the empty one and save: a new empty one appears. Signing is done from the banner at the top.</p>
  <?php endif; ?>
</div>
</form>
<script>
(function () {
  // "Need" = required minus on hand, as you type.
  document.querySelectorAll('.ics215-row').forEach(function (row) {
    function update() {
      row.querySelectorAll('[data-need-col]').forEach(function (cell) {
        var c = cell.getAttribute('data-need-col');
        var req = row.querySelector('input[data-col="' + c + '"][data-kind="req"]');
        var have = row.querySelector('input[data-col="' + c + '"][data-kind="have"]');
        if (!req || !have || (req.value === '' && have.value === '')) { cell.textContent = ''; return; }
        cell.textContent = String(Math.max(0, (parseInt(req.value, 10) || 0) - (parseInt(have.value, 10) || 0)));
      });
    }
    row.addEventListener('input', update);
  });
}());
</script>
<?php render_footer(); ?>
