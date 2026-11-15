<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/ics233_actions.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$me = (int)($user['id'] ?? 0);

$perms = $incidentId > 0 ? get_current_user_form_permissions('ICS233', $incidentId) : get_form_permission_defaults('ICS233');
$canView = !empty($perms['can_view']);
$canManage = !empty($perms['can_edit']) || !empty($perms['can_create']);
if ($incidentId <= 0 || !$canView) {
    http_response_code($incidentId <= 0 ? 200 : 403);
    render_header('ICS 233 - Open Action Tracker');
    echo '<div class="card"><p>' . ($incidentId <= 0 ? 'No active incident.' : 'You do not have access to the ICS 233.') . '</p></div>';
    render_footer();
    exit;
}

$filled = ics213_filled_positions($incidentId);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['ics233_action'] ?? '');
    $id = (int)($_POST['action_id'] ?? 0);
    try {
        $a = $id > 0 ? ics233_action($incidentId, $id) : null;
        if ($id > 0 && !$a) { throw new RuntimeException('That action could not be found.'); }
        $isPoc = $a && ics233_user_is_poc($incidentId, $a);

        if ($action === 'save') {
            if (!$canManage) { throw new RuntimeException('Only the Planning Section Chief (or whoever keeps the tracker) can add or change actions.'); }
            $item = trim((string)($_POST['item'] ?? ''));
            if ($item === '') { throw new RuntimeException('Describe the action (3. Item).'); }
            $poc = strtoupper(trim((string)($_POST['poc_role_code'] ?? '')));
            if ($poc !== '' && !isset($filled[$poc]) && strtoupper((string)($a['poc_role_code'] ?? '')) !== $poc) { $poc = ''; }
            $pocText = trim((string)($_POST['poc_text'] ?? ''));
            if ($poc === '' && $pocText === '') { throw new RuntimeException('Say who it is for (4. For / POC).'); }
            $start = ics233_date_or_null((string)($_POST['start_date'] ?? '')) ?? date('Y-m-d');
            $target = ics233_date_or_null((string)($_POST['target_date'] ?? ''));
            $status = mb_substr(trim((string)($_POST['status_text'] ?? '')), 0, 255);
            $fields = [mb_substr($item, 0, 500), $poc !== '' ? $poc : null, $pocText !== '' ? mb_substr($pocText, 0, 200) : null, $start, $target, $status];
            if ($a) {
                $pdo->prepare('UPDATE form_233_actions SET item=?, poc_role_code=?, poc_text=?, start_date=?, target_date=?, status_text=?, updated_at=NOW() WHERE id=?')
                    ->execute(array_merge($fields, [(int)$a['id']]));
                $newPoc = $poc !== '' && $poc !== strtoupper((string)($a['poc_role_code'] ?? ''));
                if ($newPoc) {
                    $pdo->prepare('UPDATE form_233_actions SET briefed_at=NULL, briefed_by_user_id=NULL WHERE id=?')->execute([(int)$a['id']]);
                }
                $saved = ics233_action($incidentId, (int)$a['id']);
                audit_log($me, 'update', 'ICS233', (int)$a['id'], 'ICS 233 action ' . (int)$a['item_no'] . ' changed: ' . mb_substr($item, 0, 200));
                flash('success', 'Action ' . (int)$a['item_no'] . ' saved.');
            } else {
                $no = ics233_next_item_no($incidentId);
                $pdo->prepare('INSERT INTO form_233_actions (incident_id, item_no, item, poc_role_code, poc_text, start_date, target_date, status_text, created_by_user_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute(array_merge([$incidentId, $no], $fields, [$me ?: null]));
                $saved = ics233_action($incidentId, (int)$pdo->lastInsertId());
                $newPoc = $poc !== '';
                audit_log($me, 'create', 'ICS233', (int)$saved['id'], 'ICS 233 action ' . $no . ' added for ' . ($poc ?: $pocText) . ': ' . mb_substr($item, 0, 200));
                flash('success', 'Action ' . $no . ' added.');
            }
            if ($newPoc && $saved) {
                ics233_notify_poc($incidentId, $saved, 'ICS 233 action for you: ' . mb_substr($item, 0, 100),
                    'Action ' . (int)$saved['item_no'] . ($target ? ', target ' . $target : '') . '. Open the action tracker to mark it briefed and keep its status up to date.');
            }

        } elseif ($action === 'brief') {
            if (!$a || !($isPoc || $canManage)) { throw new RuntimeException('Only the POC or the tracker keeper can mark this briefed.'); }
            $pdo->prepare('UPDATE form_233_actions SET briefed_at=NOW(), briefed_by_user_id=?, updated_at=NOW() WHERE id=?')->execute([$me ?: null, (int)$a['id']]);
            audit_log($me, 'update', 'ICS233', (int)$a['id'], 'ICS 233 action ' . (int)$a['item_no'] . ' marked briefed to the POC');
            ics233_mark_read((int)$a['id']);
            flash('success', 'Action ' . (int)$a['item_no'] . ' marked briefed.');

        } elseif ($action === 'status') {
            if (!$a || !($isPoc || $canManage)) { throw new RuntimeException('Only the POC or the tracker keeper can update this action.'); }
            $status = mb_substr(trim((string)($_POST['status_text'] ?? '')), 0, 255);
            $done = !empty($_POST['complete']);
            $actual = $done ? (ics233_date_or_null((string)($_POST['actual_date'] ?? '')) ?? date('Y-m-d')) : null;
            if ($done && $status === '') { $status = 'Completed'; }
            $pdo->prepare('UPDATE form_233_actions SET status_text=?, is_completed=?, actual_date=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $done ? 1 : 0, $actual, (int)$a['id']]);
            audit_log($me, $done ? 'complete' : 'update', 'ICS233', (int)$a['id'], 'ICS 233 action ' . (int)$a['item_no'] . ($done ? ' completed' : ' status') . ': ' . $status);
            if ($done) {
                ics233_mark_read((int)$a['id']);
                if ($isPoc && (int)($a['created_by_user_id'] ?? 0) > 0) {
                    ics233_notify_users($incidentId, [(int)$a['created_by_user_id']], $a, 'ICS 233 action ' . (int)$a['item_no'] . ' completed',
                        mb_substr((string)$a['item'], 0, 200));
                }
            }
            flash('success', 'Action ' . (int)$a['item_no'] . ($done ? ' marked completed.' : ' updated.'));

        } elseif ($action === 'reopen') {
            if (!$a || !$canManage) { throw new RuntimeException('Only the tracker keeper can reopen an action.'); }
            $pdo->prepare("UPDATE form_233_actions SET is_completed=0, actual_date=NULL, updated_at=NOW() WHERE id=?")->execute([(int)$a['id']]);
            audit_log($me, 'update', 'ICS233', (int)$a['id'], 'ICS 233 action ' . (int)$a['item_no'] . ' reopened');
            flash('success', 'Action ' . (int)$a['item_no'] . ' reopened.');

        } elseif ($action === 'delete') {
            if (!$a || !$canManage) { throw new RuntimeException('Only the tracker keeper can remove an action.'); }
            $pdo->prepare('UPDATE form_233_actions SET deleted_at=NOW() WHERE id=?')->execute([(int)$a['id']]);
            audit_log($me, 'delete', 'ICS233', (int)$a['id'], 'ICS 233 action ' . (int)$a['item_no'] . ' removed: ' . mb_substr((string)$a['item'], 0, 200));
            flash('success', 'Action ' . (int)$a['item_no'] . ' removed.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
        header('Location: ' . app_url('/forms/ics233/index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'The action could not be saved.';
        if (!($e instanceof RuntimeException)) { security_log($e, 'ics233'); }
    }
}

$open = ics233_actions($incidentId, false);
$done = ics233_actions($incidentId, true);
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 && $canManage ? ics233_action($incidentId, $editId) : null;
$pdfBase = app_url('/forms/ics233/pdf.php');
$today = date('Y-m-d');

/** Small form for the POC (or keeper) to update status / complete one action. */
$statusForm = static function (array $a): string {
    return '<form method="post" class="ics233-status-form">' . csrf_field()
        . '<input type="hidden" name="action_id" value="' . (int)$a['id'] . '">'
        . '<input type="text" name="status_text" maxlength="255" value="' . e((string)($a['status_text'] ?? '')) . '" aria-label="Status">'
        . '<label class="checkbox-line"><input type="checkbox" name="complete" value="1"> Completed</label>'
        . '<button type="submit" name="ics233_action" value="status" class="btn btn-secondary btn-small">Update</button></form>';
};

render_header('ICS 233 - Open Action Tracker');
?>
<div class="card">
  <h2>ICS 233 - Incident Open Action Tracker</h2>
  <p class="small">Tasks the Incident Commander gives the team that are not incident objectives (those go on the
    <a href="<?= e(app_url('/forms/ics202/index.php')) ?>">ICS 202</a>). One list for the whole incident: open actions
    carry over from one operational period to the next.</p>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <p>
    <a class="btn" target="_blank" rel="noopener" href="<?= e($pdfBase . '?preview=1') ?>">Preview PDF (open actions)</a>
    <a class="btn" href="<?= e($pdfBase) ?>">Download PDF</a>
    <?php require_once dirname(dirname(__DIR__)) . '/includes/printing.php'; echo cb_print_button($pdfBase); ?>
    <a class="btn" target="_blank" rel="noopener" href="<?= e($pdfBase . '?preview=1&include=completed') ?>">PDF with completed</a>
  </p>
</div>

<div class="card">
  <h3>Open actions (<?= count($open) ?>)</h3>
  <table class="data-table cb-cards ics233-table">
    <thead><tr><th>2. No.</th><th>3. Item</th><th>4. For / POC</th><th>5. Briefed</th><th>6. Start</th><th>7. Status</th><th>8. Target</th><th></th></tr></thead>
    <tbody>
    <?php if (!$open): ?><tr><td colspan="8">No open actions.</td></tr><?php endif; ?>
    <?php foreach ($open as $a): $due = ics233_due_state($a, $today); $isPoc = ics233_user_is_poc($incidentId, $a); ?>
      <tr id="a<?= (int)$a['id'] ?>" class="<?= $due !== '' ? 'ics233-' . $due : '' ?>">
        <td><?= (int)$a['item_no'] ?></td>
        <td><?= nl2br(e((string)$a['item'])) ?></td>
        <td><?= e(ics233_poc_label($a, $filled)) ?></td>
        <td><?php if ($a['briefed_at']): ?>X <span class="small"><?= e(substr((string)$a['briefed_at'], 0, 16)) ?><?= $a['briefed_by_name'] ? ', ' . e((string)$a['briefed_by_name']) : '' ?></span>
          <?php elseif ($isPoc || $canManage): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
              <button type="submit" name="ics233_action" value="brief" class="btn btn-secondary btn-small"><?= $isPoc ? 'I\'ve been briefed' : 'Mark briefed' ?></button></form>
          <?php else: ?>No<?php endif; ?></td>
        <td><?= e((string)$a['start_date']) ?></td>
        <td><?= ($isPoc || $canManage) ? $statusForm($a) : e((string)$a['status_text']) ?></td>
        <td><?= e((string)($a['target_date'] ?? '')) ?><?php if ($due === 'overdue'): ?><br><span class="priority-high">Overdue</span><?php elseif ($due === 'due'): ?><br><span class="ics233-due-label">Due soon</span><?php endif; ?></td>
        <td><?php if ($canManage): ?><a class="btn btn-small" href="<?= e(ics233_url((int)$a['id'])) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): $cur = $editing ?? []; ?>
<div class="card" id="ics233-edit">
  <h3><?= $editing ? 'Change action ' . (int)$editing['item_no'] : 'Add an action' ?></h3>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action_id" value="<?= (int)($cur['id'] ?? 0) ?>">
    <label class="span-2">3. Item<textarea name="item" rows="2" maxlength="500" required><?= e((string)($cur['item'] ?? '')) ?></textarea></label>
    <label>4. For / POC (position)
      <select name="poc_role_code">
        <option value="">-- Not a position (type below) --</option>
        <?php foreach ($filled as $code => $p): ?>
          <option value="<?= e($code) ?>"<?= $code === strtoupper((string)($cur['poc_role_code'] ?? '')) ? ' selected' : '' ?>><?= e($code . ' - ' . $p['title'] . ': ' . implode(', ', $p['names'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Name or note (optional)<input name="poc_text" maxlength="200" value="<?= e((string)($cur['poc_text'] ?? '')) ?>" placeholder="e.g. Liaison with the county"></label>
    <label>6. Start date<input type="date" name="start_date" value="<?= e((string)($cur['start_date'] ?? $today)) ?>"></label>
    <label>8. Target date<input type="date" name="target_date" value="<?= e((string)($cur['target_date'] ?? '')) ?>"></label>
    <label class="span-2">7. Status<input name="status_text" maxlength="255" value="<?= e((string)($cur['status_text'] ?? '')) ?>" placeholder="e.g. Awaiting feedback"></label>
    <p class="span-2 small">Picking a position puts a notice on the dashboard of whoever holds it, so they can mark it briefed and keep the status up to date.</p>
    <p class="form-actions span-2">
      <button type="submit" name="ics233_action" value="save" class="btn btn-primary"><?= $editing ? 'Save action' : 'Add action' ?></button>
      <?php if ($editing): ?>
        <button type="submit" name="ics233_action" value="delete" class="btn btn-secondary" formnovalidate onclick="return confirm('Remove this action from the tracker?');">Remove</button>
        <a class="btn" href="<?= e(app_url('/forms/ics233/index.php')) ?>">Cancel</a>
      <?php endif; ?>
    </p>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <details<?= $done && !$open ? ' open' : '' ?>>
    <summary><strong>Completed actions (<?= count($done) ?>)</strong></summary>
    <table class="data-table cb-cards">
      <thead><tr><th>No.</th><th>Item</th><th>For / POC</th><th>Start</th><th>Status</th><th>Target</th><th>9. Actual</th><th></th></tr></thead>
      <tbody>
      <?php if (!$done): ?><tr><td colspan="8">None yet.</td></tr><?php endif; ?>
      <?php foreach ($done as $a): ?>
        <tr>
          <td><?= (int)$a['item_no'] ?></td><td><?= nl2br(e((string)$a['item'])) ?></td><td><?= e(ics233_poc_label($a, $filled)) ?></td>
          <td><?= e((string)$a['start_date']) ?></td><td><?= e((string)$a['status_text']) ?></td><td><?= e((string)($a['target_date'] ?? '')) ?></td>
          <td><?= e((string)($a['actual_date'] ?? '')) ?></td>
          <td><?php if ($canManage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
            <button type="submit" name="ics233_action" value="reopen" class="btn btn-secondary btn-small">Reopen</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
</div>
<?php render_footer(); ?>
