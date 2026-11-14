<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/ics213_messages.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();

$opId = (int)($_GET['operational_period_id'] ?? 0);
if ($opId <= 0 && $incidentId > 0) {
    $stmt = $pdo->prepare("SELECT id FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL ORDER BY op_start DESC LIMIT 1");
    $stmt->execute([$incidentId]);
    $opId = (int)($stmt->fetch()['id'] ?? 0);
}

$messageId = (int)($_GET['message_id'] ?? 0);
$isNewMessage = isset($_GET['new']) && $_GET['new'] === '1';

$perms = $incidentId > 0 ? get_current_user_form_permissions('ICS213', $incidentId) : get_form_permission_defaults('ICS213');
$canView = !empty($perms['can_view']);
$canEdit = !empty($perms['can_edit']) || !empty($perms['can_create']);

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 213');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

$formRecordId = $incidentId > 0 ? get_or_create_form_record($incidentId, 'ICS213', '213', (int)($user['id'] ?? 0), $opId ?: null) : 0;
$error = null;

function ics213_op_label(PDO $pdo, int $opId): string {
    if ($opId <= 0) return 'No operational period selected';
    try {
        $st = $pdo->prepare("SELECT * FROM operational_periods WHERE id=?");
        $st->execute([$opId]);
        $op = $st->fetch();
        if (!$op) return 'No operational period selected';
        $parts = [];
        if (!empty($op['period_number'])) $parts[] = 'OP ' . (int)$op['period_number'];
        if (!empty($op['period_name'])) $parts[] = (string)$op['period_name'];
        $range = trim((string)($op['op_start'] ?? $op['start_time'] ?? '') . ' to ' . (string)($op['op_end'] ?? $op['end_time'] ?? ''));
        return trim(($parts ? implode(' - ', $parts) . ' | ' : '') . $range);
    } catch (Throwable $e) {
        return 'Operational Period #' . $opId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $formRecordId > 0) {
    $back = app_url('/forms/ics213/index.php?operational_period_id=' . $opId);
    try {
        $opLabel = ics213_op_label($pdo, $opId);
        $tellEmailResult = static function (array $r, array $problems): void {
            if ($r['sent'] > 0) { flash('success', 'Emailed to ' . $r['sent'] . ' recipient' . ($r['sent'] === 1 ? '' : 's') . '.'); }
            if ($r['failed'] > 0) { flash('error', 'Not sent to ' . $r['failed'] . ': ' . implode('; ', $r['errors']) . ' Use Resend once the problem is fixed.'); }
            foreach ($problems as $p) { flash('warning', $p); }
        };
        if ($messageId > 0 && !$isNewMessage) {
            $before = $pdo->prepare("SELECT * FROM form_213_messages WHERE id=? AND form_record_id=?");
            $before->execute([$messageId, $formRecordId]);
            $before = $before->fetch() ?: [];
            if (!$before) { throw new RuntimeException('That message could not be found.'); }
            $back = app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&message_id=' . $messageId);
            $action = (string)($_POST['ics213_action'] ?? 'reply');

            if ($action === 'email') {
                // Send (or send again) to more people.
                [$recipients, $problems] = ics213_recipients_from_post($incidentId, $opId, (string)($before['to_role_code'] ?? ''), !empty($_POST['email_holders']));
                if (!$recipients) { throw new RuntimeException('Choose at least one contact or type an email address.'); }
                $tellEmailResult(ics213_send_email($incidentId, $before, $recipients, $opLabel), $problems);
            } elseif ($action === 'resend') {
                $sid = (int)($_POST['send_id'] ?? 0);
                $st = $pdo->prepare('SELECT * FROM form_213_sends WHERE id = ? AND message_id = ?');
                $st->execute([$sid, $messageId]);
                $row = $st->fetch();
                if (!$row) { throw new RuntimeException('That email could not be found.'); }
                $tellEmailResult(ics213_send_email($incidentId, $before,
                    [['type' => (string)$row['recipient_type'], 'label' => (string)$row['recipient_label'], 'email' => (string)$row['email']]], $opLabel), []);
            } else {
                // The original message is locked; only the reply can be added or changed.
                $via = (string)($_POST['reply_received_via'] ?? '') === 'email' ? 'email' : 'app';
                $replyEmail = trim((string)($_POST['reply_email'] ?? ''));
                if ($via === 'email' && $replyEmail !== '' && !filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('"Reply came from" must be an email address.');
                }
                $newReply = trim((string)($_POST['reply'] ?? ''));
                $stmt = $pdo->prepare("UPDATE form_213_messages SET reply=?, reply_by=?, reply_position=?, reply_date_time=?, reply_received_via=?, reply_email=?, updated_at=NOW() WHERE id=? AND form_record_id=?");
                $stmt->execute([
                    $newReply,
                    trim($_POST['reply_by'] ?? ''),
                    trim($_POST['reply_position'] ?? ''),
                    ($_POST['reply_date_time'] ?? '') ?: null,
                    $newReply !== '' ? $via : null,
                    $newReply !== '' && $via === 'email' && $replyEmail !== '' ? $replyEmail : null,
                    $messageId,
                    $formRecordId
                ]);
                if (trim((string)($before['reply'] ?? '')) === '' && $newReply !== '') {
                    ics213_notify_reply($incidentId, $opId, $before, trim((string)($_POST['reply_by'] ?? '')), $newReply);
                    audit_log((int)($user['id'] ?? 0), 'reply', 'ICS213', $formRecordId, 'Reply recorded on ICS 213 '
                        . ((string)($before['ref_code'] ?? '') ?: '#' . $messageId) . ($via === 'email' ? ' (received by email' . ($replyEmail !== '' ? ' from ' . $replyEmail : '') . ')' : ''));
                }
                ics213_mark_read($messageId);
                flash('success', 'Reply saved.');
            }
        } else {
            // New message: save the originating message fields, tell the "To" position, and email it if asked.
            $filled = ics213_filled_positions($incidentId);
            $toRole = strtoupper(trim((string)($_POST['to_role_code'] ?? '')));
            if ($toRole !== '' && !isset($filled[$toRole])) { $toRole = ''; }
            $toName = trim((string)($_POST['to_name'] ?? ''));
            if ($toName === '' && $toRole !== '') { $toName = implode(' / ', $filled[$toRole]['names']) . ' (' . $filled[$toRole]['title'] . ')'; }
            if ($toName === '') { throw new RuntimeException('Enter who the message is for (2. To).'); }
            if (trim((string)($_POST['message'] ?? '')) === '') { throw new RuntimeException('Write the message (6. Message).'); }
            $ref = strtoupper(trim((string)($_POST['ref_code'] ?? '')));
            if (!ics213_valid_ref_code($ref)) { $ref = ics213_new_ref_code(); }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM form_213_messages WHERE ref_code = ?');
            $dup->execute([$ref]);
            if ((int)$dup->fetchColumn() > 0) { $ref = ics213_new_ref_code(); }
            $stmt = $pdo->prepare("INSERT INTO form_213_messages (form_record_id, to_name, to_role_code, ref_code, from_name, from_user_id, subject, date_time, message, approved_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([
                $formRecordId,
                $toName,
                $toRole !== '' ? $toRole : null,
                $ref,
                trim($_POST['from_name'] ?? ''),
                (int)($user['id'] ?? 0) ?: null,
                trim($_POST['subject'] ?? ''),
                ($_POST['date_time'] ?? '') ?: null,
                trim($_POST['message'] ?? ''),
                trim($_POST['approved_by'] ?? '')
            ]);
            $newId = (int)$pdo->lastInsertId();
            ics213_notify_new_message($incidentId, $opId, $newId, $toRole, trim((string)($_POST['from_name'] ?? '')),
                trim((string)($_POST['subject'] ?? '')), trim((string)($_POST['message'] ?? '')));
            audit_log((int)($user['id'] ?? 0), 'create', 'ICS213', $formRecordId, 'ICS 213 ' . $ref . ' to ' . $toName
                . ($toRole !== '' ? ' (' . $toRole . ', notified on the dashboard)' : ''));
            flash('success', 'ICS 213 ' . $ref . ' saved.');
            $emailHolders = $toRole !== '' && (ics213_email_holders_mode() === 'always' || !empty($_POST['email_holders']));
            [$recipients, $problems] = ics213_recipients_from_post($incidentId, $opId, $toRole, $emailHolders);
            if ($recipients) {
                $st = $pdo->prepare('SELECT * FROM form_213_messages WHERE id = ?');
                $st->execute([$newId]);
                $tellEmailResult(ics213_send_email($incidentId, $st->fetch(), $recipients, $opLabel), $problems);
            } else {
                foreach ($problems as $p) { flash('warning', $p); }
            }
            $back = app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&message_id=' . $newId);
        }
        header('Location: ' . $back);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('ICS 213 - General Message');
$record = get_form_record($incidentId, 'ICS213', $opId ?: null);
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS213') : null;
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
render_ics_form_status('ICS213', $record, $preparer, $signature, [
    'official_file' => '/assets/pdf/official/ICS213.pdf',
    'show_pdf' => false,
    'note' => 'ICS 213 General Message may be created by any ICS position. Once saved, the original message is locked and only the reply information can be updated.'
]);

if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php
if ($messageId <= 0 && !$isNewMessage):
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        // Search by reference code (from a reply email's subject), subject, To or From - in every operational period.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $stmt = $pdo->prepare("SELECT m.*, fr.operational_period_id AS msg_op_id FROM form_213_messages m
            INNER JOIN form_records fr ON fr.id = m.form_record_id
            WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND (m.ref_code LIKE ? OR m.subject LIKE ? OR m.to_name LIKE ? OR m.from_name LIKE ?)
            ORDER BY m.created_at DESC, m.id DESC LIMIT 200");
        $stmt->execute([$incidentId, $like, $like, $like, $like]);
    } else {
        $stmt = $pdo->prepare("SELECT m.*, ? AS msg_op_id FROM form_213_messages m WHERE m.form_record_id=? ORDER BY m.created_at DESC, m.id DESC");
        $stmt->execute([$opId, $formRecordId]);
    }
    $msgs = $stmt->fetchAll();
?>
<div class="card">
  <h2>ICS213 - General Message</h2>
  <p>Use this register to create and track general messages for the selected incident and operational period.</p>
  <div class="grid-2">
    <label>Incident Name<input value="<?= e($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
    <label>Operational Period<input value="<?= e(ics213_op_label($pdo, $opId)) ?>" readonly></label>
  </div>
  <?php if ($canEdit): ?>
    <p><a class="btn btn-primary" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&new=1')) ?>">Create New Message</a></p>
  <?php endif; ?>
  <form method="get" class="inline-form" style="margin:0 0 12px">
    <input type="hidden" name="operational_period_id" value="<?= (int)$opId ?>">
    <label for="ics213_q" class="small">Find a message (reference code from a reply email, subject, to or from)</label>
    <input id="ics213_q" name="q" value="<?= e($q) ?>" placeholder="e.g. CB-213-7K2QX">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId)) ?>">Clear</a><?php endif; ?>
  </form>
  <table class="data-table cb-cards">
    <thead>
      <tr>
        <th>Date/Time</th>
        <th>Reference</th>
        <th>To</th>
        <th>From</th>
        <th>Subject</th>
        <th>Reply Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$msgs): ?>
        <tr><td colspan="7"><?= $q !== '' ? 'No message matches that search.' : 'No ICS 213 messages have been created for this operational period.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($msgs as $m): ?>
        <tr>
          <td><?= e($m['date_time'] ?? '') ?></td>
          <td><?= e($m['ref_code'] ?? '') ?></td>
          <td><?= e($m['to_name'] ?? '') ?></td>
          <td><?= e($m['from_name'] ?? '') ?></td>
          <td><?= e($m['subject'] ?? '') ?></td>
          <td><?= trim((string)($m['reply'] ?? '')) !== '' ? 'Replied' : 'Pending' ?></td>
          <td><a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . (int)($m['msg_op_id'] ?: $opId) . '&message_id=' . (int)$m['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else:
    $msg = [];
    $locked = false;
    if ($messageId > 0 && !$isNewMessage) {
        $stmt = $pdo->prepare("SELECT * FROM form_213_messages WHERE id=? AND form_record_id=?");
        $stmt->execute([$messageId, $formRecordId]);
        $msg = $stmt->fetch() ?: [];
        $locked = true;
        if ($msg) { ics213_mark_read((int)$msg['id']); }
    }
    $dateValue = isset($msg['date_time']) && $msg['date_time'] !== null && $msg['date_time'] !== ''
        ? str_replace(' ', 'T', substr((string)$msg['date_time'], 0, 16))
        : date('Y-m-d\TH:i');
    $refCode = $locked ? (string)($msg['ref_code'] ?? '') : ics213_new_ref_code();
    $contacts205a = ics213_205a_contacts($incidentId, $opId);
    $holdersMode = ics213_email_holders_mode();
    $replyDateValue = isset($msg['reply_date_time']) && $msg['reply_date_time'] !== null && $msg['reply_date_time'] !== ''
        ? str_replace(' ', 'T', substr((string)$msg['reply_date_time'], 0, 16))
        : '';
?>
<div class="card">
  <h2><?= $locked ? 'ICS 213 Message Details' : 'Create ICS 213 General Message' ?></h2>
  <p><?= $locked ? 'The original message fields are locked. Update the reply information as needed.' : 'Complete the original message fields and save. Reply information can be added later from the message details screen.' ?></p>
  <form method="post" class="cb-lock-exempt">
    <?= csrf_field() ?>
    <div class="grid-2">
      <label>1. Incident Name<input value="<?= e($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>Operational Period<input value="<?= e(ics213_op_label($pdo, $opId)) ?>" readonly></label>
    </div>

    <h3>Original Message</h3>
    <?php if ($refCode !== ''): ?>
      <p><strong>Reference:</strong> <?= e($refCode) ?><?php if (!$locked): ?> <input type="hidden" name="ref_code" value="<?= e($refCode) ?>"><span class="small">(added to the email subject; use it to find this message when a reply comes back by email)</span><?php endif; ?></p>
    <?php endif; ?>
    <div class="grid-2">
      <?php if ($locked): ?>
        <label>2. To<input name="to_name" value="<?= e($msg['to_name'] ?? '') ?>" readonly></label>
      <?php else: $filled = ics213_filled_positions($incidentId); ?>
        <div>
          <label>2. To (position)
            <select name="to_role_code" id="ics213_to_role">
              <option value="">-- Not in the incident organization (type the name below) --</option>
              <?php foreach ($filled as $code => $p): ?>
                <option value="<?= e($code) ?>" data-to="<?= e(implode(' / ', $p['names']) . ' (' . $p['title'] . ')') ?>"><?= e($code . ' - ' . $p['title'] . ': ' . implode(', ', $p['names'])) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Name and position<input name="to_name" id="ics213_to_name" value="" placeholder="Filled in from the position, or type a name"></label>
          <p class="small">Picking a position sends the message to the dashboard of everyone holding it.</p>
        </div>
      <?php endif; ?>
      <label>3. From<input name="from_name" value="<?= e($msg['from_name'] ?? ($user['real_name'] ?? $user['display_name'] ?? $user['username'] ?? '')) ?>" <?= $locked ? 'readonly' : '' ?>></label>
    </div>
    <div class="grid-2">
      <label>4. Subject<input name="subject" value="<?= e($msg['subject'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>
      <label>5. Date/Time<input type="datetime-local" name="date_time" value="<?= e($dateValue) ?>" <?= $locked ? 'readonly' : '' ?>></label>
    </div>
    <?php if (!$locked): ?>
    <div class="notice ics213-guidance">
      <p><strong>Who will see this message:</strong> the position you pick in "2. To" will see it on their Command Bridge
      dashboard. Anyone you choose to email below will get it as an email, with the ICS 213 attached as a PDF.</p>
      <p><strong>If you need a reply, say how in your message.</strong> People outside the ECC may not reach the right
      person just by pressing Reply, so tell them where to send their answer. For example:</p>
      <ul>
        <li><em>Reply requested:</em> "Please reply by 15:30 to ecc-comms@example.ca or call the ECC at 780-555-0100. Include the reference <?= e($refCode) ?>."</li>
        <li><em>No reply needed:</em> "For your information only. No reply needed."</li>
      </ul>
    </div>
    <?php endif; ?>
    <label>6. Message<textarea name="message" rows="6" <?= $locked ? 'readonly' : '' ?>><?= e($msg['message'] ?? '') ?></textarea></label>
    <label>7. Approved By / Signature<input name="approved_by" value="<?= e($msg['approved_by'] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></label>

    <?php if (!$locked): ?>
      <h3>Send by email (optional)</h3>
      <?php if ($holdersMode === 'always'): ?>
        <p class="small">The people holding the "To" position are also emailed, if their account has an email address.</p>
      <?php else: ?>
        <label class="checkbox-line"><input type="checkbox" name="email_holders" value="1"> Also email the people holding the "To" position</label>
      <?php endif; ?>
      <?= ics213_recipient_picker_html($contacts205a) ?>
    <?php endif; ?>

    <?php if ($locked): ?>
      <h3>Reply</h3>
      <label>8. Reply<textarea name="reply" rows="6"><?= e($msg['reply'] ?? '') ?></textarea></label>
      <div class="grid-2">
        <label>Reply By<input name="reply_by" value="<?= e($msg['reply_by'] ?? '') ?>"></label>
        <label>ICS Position<input name="reply_position" value="<?= e($msg['reply_position'] ?? '') ?>"></label>
      </div>
      <label>Reply Date/Time<input type="datetime-local" name="reply_date_time" value="<?= e($replyDateValue) ?>"></label>
      <div class="grid-2">
        <label>Reply received
          <select name="reply_received_via">
            <option value="app"<?= ($msg['reply_received_via'] ?? '') !== 'email' ? ' selected' : '' ?>>Entered here in Command Bridge</option>
            <option value="email"<?= ($msg['reply_received_via'] ?? '') === 'email' ? ' selected' : '' ?>>By email (typed in from the email)</option>
          </select>
        </label>
        <label>Reply came from (email)<input type="email" name="reply_email" value="<?= e($msg['reply_email'] ?? '') ?>" placeholder="only for a reply received by email"></label>
      </div>
    <?php endif; ?>

    <p>
      <a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . $opId)) ?>">Back to List</a>
    </p>
    <?php if (!$locked): ?>
    <script>
    (function () {
      var sel = document.getElementById('ics213_to_role'), name = document.getElementById('ics213_to_name'), auto = '';
      if (!sel || !name) { return; }
      sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex], v = opt ? (opt.getAttribute('data-to') || '') : '';
        if (name.value === '' || name.value === auto) { name.value = v; auto = v; }
      });
    }());
    </script>
    <?php endif; ?>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'extra_query' => ['message_id' => $messageId ?? 0],
        'form_code' => 'ICS213',
        'form_title' => 'ICS213',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<?php if ($locked && $msg): $sends = ics213_sends((int)$msg['id']); ?>
<div class="card">
  <h2>Email</h2>
  <?php if (!$sends): ?>
    <p class="muted">This message has not been emailed.</p>
  <?php else: ?>
    <table class="cb-cards"><thead><tr><th>Sent to</th><th>Status</th><th>When</th><th>By</th><th></th></tr></thead><tbody>
    <?php foreach ($sends as $snd): ?>
      <tr>
        <td><?= e((string)$snd['recipient_label']) ?><?= (string)$snd['recipient_label'] !== (string)$snd['email'] ? '<br><span class="small">' . e((string)$snd['email']) . '</span>' : '' ?></td>
        <td><?= $snd['status'] === 'sent' ? 'Sent' : '<span class="priority-high">Failed</span><br><span class="small">' . e((string)$snd['error']) . '</span>' ?></td>
        <td><?= e((string)$snd['sent_at']) ?></td>
        <td><?= e((string)($snd['sent_by'] ?? '')) ?></td>
        <td><?php if ($canEdit): ?>
          <form method="post" class="cb-lock-exempt"><?= csrf_field() ?><input type="hidden" name="ics213_action" value="resend"><input type="hidden" name="send_id" value="<?= (int)$snd['id'] ?>">
            <button type="submit" class="btn btn-secondary btn-small">Resend</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <h3>Send to more people</h3>
    <form method="post" class="cb-lock-exempt">
      <?= csrf_field() ?>
      <input type="hidden" name="ics213_action" value="email">
      <?php if (!empty($msg['to_role_code'])): ?>
        <label class="checkbox-line"><input type="checkbox" name="email_holders" value="1"> Email the people holding the "To" position (<?= e((string)$msg['to_role_code']) ?>)</label>
      <?php endif; ?>
      <?= ics213_recipient_picker_html($contacts205a) ?>
      <p><button type="submit" class="btn btn-primary">Send email</button></p>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
