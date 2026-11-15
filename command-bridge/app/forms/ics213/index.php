<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/ics213_messages.php';
require_once dirname(dirname(__DIR__)) . '/includes/signoff.php';
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

/** Loads one message of this incident (any operational period). */
function ics213_load_message(PDO $pdo, int $incidentId, int $messageId): ?array {
    if ($messageId <= 0) { return null; }
    $st = $pdo->prepare("SELECT m.*, fr.operational_period_id AS msg_op_id FROM form_213_messages m
        INNER JOIN form_records fr ON fr.id = m.form_record_id WHERE m.id = ? AND fr.incident_id = ? AND fr.is_deleted = 0");
    $st->execute([$messageId, $incidentId]);
    return $st->fetch() ?: null;
}

$me = (int)($user['id'] ?? 0);
$isSysadmin = !empty($user['is_sysadmin']);
$isIc = $incidentId > 0 && ics213_user_is_ic($incidentId, $me);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $formRecordId > 0) {
    $action = (string)($_POST['ics213_action'] ?? '');
    $back = app_url('/forms/ics213/index.php?operational_period_id=' . $opId . ($messageId > 0 ? '&message_id=' . $messageId : ''));
    try {
        $opLabel = ics213_op_label($pdo, $opId);
        $tellEmailResult = static function (array $r, array $problems = []): void {
            if ($r['sent'] > 0) { flash('success', 'Email accepted by the mail server for ' . $r['sent'] . ' recipient' . ($r['sent'] === 1 ? '' : 's') . '.'); }
            if ($r['failed'] > 0) { flash('error', 'Not sent to ' . $r['failed'] . ': ' . implode('; ', $r['errors']) . ' Use Resend once the problem is fixed.'); }
            foreach ($problems as $p) { flash('warning', $p); }
        };
        $msg = $messageId > 0 ? ics213_load_message($pdo, $incidentId, $messageId) : null;
        if ($messageId > 0 && !$msg) { throw new RuntimeException('That message could not be found.'); }
        $status = (string)($msg['status'] ?? '');
        $isSender = $msg && (int)($msg['from_user_id'] ?? 0) === $me;
        $ref = (string)($msg['ref_code'] ?? '');

        if ($action === 'send') {
            // Sign and send (dashboard only, or the IC), or sign and request approval (being emailed).
            if ($msg && !($status === 'returned' && ($isSender || $isSysadmin))) { throw new RuntimeException('This message has already been signed.'); }
            $filled = ics213_filled_positions($incidentId);
            $toRole = strtoupper(trim((string)($_POST['to_role_code'] ?? '')));
            if ($toRole !== '' && !isset($filled[$toRole])) { $toRole = ''; }
            $toName = trim((string)($_POST['to_name'] ?? ''));
            if ($toName === '' && $toRole !== '') { $toName = implode(' / ', $filled[$toRole]['names']) . ' (' . $filled[$toRole]['title'] . ')'; }
            if ($toName === '') { throw new RuntimeException('Enter who the message is for (2. To).'); }
            if (trim((string)($_POST['message'] ?? '')) === '') { throw new RuntimeException('Write the message (6. Message).'); }
            $emailHolders = $toRole !== '' && (ics213_email_holders_mode() === 'always' || !empty($_POST['email_holders']));
            [$recipients, $problems] = ics213_recipients_from_post($incidentId, $opId, $toRole, $emailHolders);
            $needsApproval = $recipients && !$isIc;
            $approverId = 0;
            if ($needsApproval) {
                $approverId = (int)($_POST['approver_user_id'] ?? 0);
                if ($approverId <= 0 || $approverId === $me) { [$approverId] = ics213_default_approver($incidentId, $opId, $me); }
                if ($approverId <= 0 || $approverId === $me) { throw new RuntimeException('No one is available to approve an emailed message. Choose an approver, or send it without email.'); }
            }
            $approvedBy = trim((string)($_POST['approved_by'] ?? ''));
            if ($recipients && $isIc) { $approvedBy = ics213_signature_text($incidentId, $me); }
            if ($needsApproval) { $approvedBy = ''; }   // filled in by the approver
            $fields = [$toName, $toRole !== '' ? $toRole : null, trim($_POST['from_name'] ?? ''), $me ?: null, trim($_POST['subject'] ?? ''),
                ($_POST['date_time'] ?? '') ?: null, trim($_POST['message'] ?? ''), $approvedBy];
            if ($msg) {
                // Returned message, signed again.
                $pdo->prepare("UPDATE form_213_messages SET to_name=?, to_role_code=?, from_name=?, from_user_id=?, subject=?, date_time=?, message=?, approved_by=?,
                        signed_at=NOW(), return_reason=NULL, updated_at=NOW() WHERE id=?")->execute(array_merge($fields, [(int)$msg['id']]));
                $pdo->prepare("DELETE FROM form_213_sends WHERE message_id = ? AND status = 'pending'")->execute([(int)$msg['id']]);
                $newId = (int)$msg['id'];
            } else {
                $ref = strtoupper(trim((string)($_POST['ref_code'] ?? '')));
                if (!ics213_valid_ref_code($ref)) { $ref = ics213_new_ref_code(); }
                $dup = $pdo->prepare('SELECT COUNT(*) FROM form_213_messages WHERE ref_code = ?');
                $dup->execute([$ref]);
                if ((int)$dup->fetchColumn() > 0) { $ref = ics213_new_ref_code(); }
                $pdo->prepare("INSERT INTO form_213_messages (form_record_id, to_name, to_role_code, from_name, from_user_id, subject, date_time, message, approved_by,
                        ref_code, status, signed_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?, 'awaiting_approval', NOW(), NOW(), NOW())")
                    ->execute(array_merge([$formRecordId], $fields, [$ref]));
                $newId = (int)$pdo->lastInsertId();
            }
            $msg = ics213_load_message($pdo, $incidentId, $newId);
            ics213_queue_emails($incidentId, $newId, $recipients, $needsApproval ? $approverId : $me);
            foreach ($problems as $p) { flash('warning', $p); }
            if ($needsApproval) {
                $pdo->prepare("UPDATE form_213_messages SET status='awaiting_approval', approver_user_id=?, approval_requested_at=NOW() WHERE id=?")->execute([$approverId, $newId]);
                ics213_notify_approver($incidentId, $opId, $msg, $approverId);
                audit_log($me, 'sign', 'ICS213', $formRecordId, 'Signed ICS 213 ' . $ref . ' to ' . $toName . '; approval requested from ' . ics213_user_label($approverId)
                    . ' before emailing ' . count($recipients) . ' recipient(s)');
                flash('success', 'ICS 213 ' . $ref . ' signed. It is waiting for ' . ics213_user_label($approverId) . ' to approve it; nothing is sent until then.');
            } else {
                $pdo->prepare("UPDATE form_213_messages SET approver_user_id=NULL, approved_by_user_id=?, approved_at=? WHERE id=?")
                    ->execute([$recipients ? $me : null, $recipients ? date('Y-m-d H:i:s') : null, $newId]);
                audit_log($me, 'send', 'ICS213', $formRecordId, 'Signed and sent ICS 213 ' . $ref . ' to ' . $toName . ($toRole !== '' ? ' (' . $toRole . ', dashboard notice)' : ''));
                flash('success', 'ICS 213 ' . $ref . ' signed and sent.');
                $tellEmailResult(ics213_deliver($incidentId, $opId, ics213_load_message($pdo, $incidentId, $newId), $opLabel));
            }
            $back = app_url('/forms/ics213/index.php?operational_period_id=' . $opId . '&message_id=' . $newId);

        } elseif ($action === 'approve') {
            if ($status !== 'awaiting_approval' || !ics213_can_approve($msg)) { throw new RuntimeException('You cannot approve this message.'); }
            $pdo->prepare("UPDATE form_213_messages SET approved_by=?, approved_by_user_id=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([ics213_signature_text($incidentId, $me), $me, (int)$msg['id']]);
            audit_log($me, 'approve', 'ICS213', $formRecordId, 'Approved and sent ICS 213 ' . $ref);
            $r = ics213_deliver($incidentId, $opId, ics213_load_message($pdo, $incidentId, (int)$msg['id']), $opLabel);
            ics213_notify_users($incidentId, [(int)$msg['from_user_id']], (int)$msg['id'], 'ICS 213 approved and sent: ' . mb_substr((string)$msg['subject'], 0, 120),
                ics213_user_label($me) . ' approved your message; it has been sent.' . ($r['failed'] ? ' Some emails failed; see the message.' : ''),
                ics213_message_url($opId, (int)$msg['id']), 'ics213_approved');
            flash('success', 'Approved and sent.');
            $tellEmailResult($r);

        } elseif ($action === 'return') {
            if ($status !== 'awaiting_approval' || !ics213_can_approve($msg)) { throw new RuntimeException('You cannot return this message.'); }
            $reason = trim((string)($_POST['return_reason'] ?? ''));
            if ($reason === '') { throw new RuntimeException('Say what needs changing.'); }
            $pdo->prepare("UPDATE form_213_messages SET status='returned', return_reason=?, updated_at=NOW() WHERE id=?")->execute([mb_substr($reason, 0, 300), (int)$msg['id']]);
            ics213_notify_users($incidentId, [(int)$msg['from_user_id']], (int)$msg['id'], 'ICS 213 returned for changes: ' . mb_substr((string)$msg['subject'], 0, 120),
                ics213_user_label($me) . ': ' . $reason, ics213_message_url($opId, (int)$msg['id']), 'ics213_returned');
            audit_log($me, 'return', 'ICS213', $formRecordId, 'Returned ICS 213 ' . $ref . ' for changes: ' . mb_substr($reason, 0, 300));
            flash('success', 'Returned to the sender.');

        } elseif ($action === 'cancel') {
            if (!in_array($status, ['awaiting_approval', 'returned'], true) || !($isSender || ics213_can_approve($msg))) { throw new RuntimeException('You cannot cancel this message.'); }
            $pdo->prepare("UPDATE form_213_messages SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([(int)$msg['id']]);
            $pdo->prepare("UPDATE form_213_sends SET status='cancelled' WHERE message_id=? AND status='pending'")->execute([(int)$msg['id']]);
            $other = $isSender ? (int)($msg['approver_user_id'] ?? 0) : (int)$msg['from_user_id'];
            ics213_notify_users($incidentId, [$other], (int)$msg['id'], 'ICS 213 cancelled: ' . mb_substr((string)$msg['subject'], 0, 120),
                ics213_user_label($me) . ' cancelled this message. It was not sent.', ics213_message_url($opId, (int)$msg['id']), 'ics213_cancelled');
            audit_log($me, 'cancel', 'ICS213', $formRecordId, 'Cancelled ICS 213 ' . $ref . ' (not sent)');
            flash('success', 'Message cancelled. It was not sent.');

        } elseif ($action === 'change_approver') {
            if ($status !== 'awaiting_approval' || !($isSender || $isSysadmin)) { throw new RuntimeException('You cannot change the approver.'); }
            $new = (int)($_POST['approver_user_id'] ?? 0);
            if ($new <= 0 || $new === (int)$msg['from_user_id']) { throw new RuntimeException('Choose someone else to approve it.'); }
            $pdo->prepare("UPDATE form_213_messages SET approver_user_id=?, approval_requested_at=NOW() WHERE id=?")->execute([$new, (int)$msg['id']]);
            $pdo->prepare("UPDATE form_213_sends SET approver_user_id=? WHERE message_id=? AND status='pending'")->execute([$new, (int)$msg['id']]);
            $pdo->prepare("UPDATE notifications SET is_read=1, read_at=COALESCE(read_at, NOW()) WHERE related_table='form_213_messages' AND related_id=? AND user_id=?
                AND notification_type='ics213_approval' AND is_read=0")->execute([(int)$msg['id'], (int)($msg['approver_user_id'] ?? 0)]);
            ics213_notify_approver($incidentId, $opId, $msg, $new);
            audit_log($me, 'request_approval', 'ICS213', $formRecordId, 'ICS 213 ' . $ref . ': approver changed to ' . ics213_user_label($new));
            flash('success', 'Sent to ' . ics213_user_label($new) . ' for approval.');

        } elseif ($action === 'reply') {
            if ($status !== 'sent') { throw new RuntimeException('A reply can only be added once the message has been sent.'); }
            $via = (string)($_POST['reply_received_via'] ?? '') === 'email' ? 'email' : 'app';
            $replyEmail = trim((string)($_POST['reply_email'] ?? ''));
            if ($via === 'email' && $replyEmail !== '' && !filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('"Reply came from" must be an email address.'); }
            $newReply = trim((string)($_POST['reply'] ?? ''));
            $pdo->prepare("UPDATE form_213_messages SET reply=?, reply_by=?, reply_position=?, reply_date_time=?, reply_received_via=?, reply_email=?, updated_at=NOW() WHERE id=?")
                ->execute([$newReply, trim($_POST['reply_by'] ?? ''), trim($_POST['reply_position'] ?? ''), ($_POST['reply_date_time'] ?? '') ?: null,
                    $newReply !== '' ? $via : null, $newReply !== '' && $via === 'email' && $replyEmail !== '' ? $replyEmail : null, (int)$msg['id']]);
            if (trim((string)($msg['reply'] ?? '')) === '' && $newReply !== '') {
                ics213_notify_reply($incidentId, $opId, $msg, trim((string)($_POST['reply_by'] ?? '')), $newReply);
            }
            audit_log($me, 'reply', 'ICS213', $formRecordId, 'Reply ' . (trim((string)($msg['reply'] ?? '')) === '' ? 'recorded' : 'changed') . ' on ICS 213 ' . $ref
                . ($via === 'email' ? ' (received by email' . ($replyEmail !== '' ? ' from ' . $replyEmail : '') . ')' : ''));
            ics213_mark_read((int)$msg['id']);
            flash('success', 'Reply saved.');

        } elseif ($action === 'email') {
            // Email a sent message to more people: approval first, unless the IC is sending it.
            if ($status !== 'sent') { throw new RuntimeException('The message has not been sent yet.'); }
            [$recipients, $problems] = ics213_recipients_from_post($incidentId, $opId, (string)($msg['to_role_code'] ?? ''), !empty($_POST['email_holders']));
            if (!$recipients) { throw new RuntimeException('Choose at least one contact or type an email address.'); }
            if ($isIc) {
                $tellEmailResult(ics213_send_email($incidentId, $msg, $recipients, $opLabel), $problems);
            } else {
                $approverId = (int)($_POST['approver_user_id'] ?? 0);
                if ($approverId <= 0 || $approverId === $me) { [$approverId] = ics213_default_approver($incidentId, $opId, $me); }
                if ($approverId <= 0 || $approverId === $me) { throw new RuntimeException('No one is available to approve the email.'); }
                $n = ics213_queue_emails($incidentId, (int)$msg['id'], $recipients, $approverId);
                ics213_notify_approver($incidentId, $opId, $msg, $approverId, 'more');
                audit_log($me, 'request_approval', 'ICS213', $formRecordId, 'ICS 213 ' . $ref . ': approval requested from ' . ics213_user_label($approverId) . ' to email ' . $n . ' more');
                flash('success', 'Waiting for ' . ics213_user_label($approverId) . ' to approve emailing ' . $n . ' more recipient' . ($n === 1 ? '' : 's') . '.');
                foreach ($problems as $p) { flash('warning', $p); }
            }

        } elseif ($action === 'approve_pending' || $action === 'cancel_pending') {
            $pending = array_values(array_filter(ics213_pending_sends((int)$msg['id']), static fn($r) => ics213_can_approve($msg, (int)$r['approver_user_id'])
                || ($action === 'cancel_pending' && (int)$r['sent_by_user_id'] === $me)));
            if ($status !== 'sent' || !$pending) { throw new RuntimeException('There is nothing waiting for you to approve.'); }
            if ($action === 'approve_pending') {
                $ids = array_map(static fn($r) => (int)$r['id'], $pending);
                // Only the approved rows: park any others while sending.
                $pdo->prepare("UPDATE form_213_sends SET status='held' WHERE message_id=? AND status='pending' AND id NOT IN (" . implode(',', $ids) . ")")->execute([(int)$msg['id']]);
                $r = ics213_deliver_pending($incidentId, $msg, $opLabel);
                $pdo->prepare("UPDATE form_213_sends SET status='pending' WHERE message_id=? AND status='held'")->execute([(int)$msg['id']]);
                audit_log($me, 'approve', 'ICS213', $formRecordId, 'Approved emailing ICS 213 ' . $ref . ' to ' . count($ids) . ' more');
                $tellEmailResult($r);
            } else {
                foreach ($pending as $r) { $pdo->prepare("UPDATE form_213_sends SET status='cancelled' WHERE id=?")->execute([(int)$r['id']]); }
                audit_log($me, 'cancel', 'ICS213', $formRecordId, 'Cancelled ' . count($pending) . ' pending email(s) of ICS 213 ' . $ref);
                flash('success', 'Pending emails cancelled.');
            }

        } elseif ($action === 'resend') {
            if ($status !== 'sent') { throw new RuntimeException('The message has not been sent yet.'); }
            $st = $pdo->prepare("SELECT * FROM form_213_sends WHERE id = ? AND message_id = ? AND status IN ('sent','failed')");
            $st->execute([(int)($_POST['send_id'] ?? 0), (int)$msg['id']]);
            $row = $st->fetch();
            if (!$row) { throw new RuntimeException('That email could not be found.'); }
            $tellEmailResult(ics213_send_email($incidentId, $msg, [['type' => (string)$row['recipient_type'], 'label' => (string)$row['recipient_label'], 'email' => (string)$row['email']]], $opLabel));
        } else {
            throw new RuntimeException('Unknown action.');
        }
        if ($msg && in_array($action, ['approve', 'return', 'cancel', 'approve_pending', 'cancel_pending'], true)) { ics213_mark_read((int)$msg['id'], true); }
        header('Location: ' . $back);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('ICS 213 - General Message');
$listUrl = app_url('/forms/ics213/index.php?operational_period_id=' . $opId);
?>
<div class="card">
  <h2>ICS 213 - General Message</h2>
  <p class="small"><strong>Operational period:</strong> <?= e(ics213_op_label($pdo, $opId)) ?></p>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($incidentId <= 0): ?><p>No active incident.</p><?php endif; ?>
</div>

<?php if ($incidentId > 0 && $messageId <= 0 && !$isNewMessage):
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
  <h2>Messages</h2>
  <?php if ($canEdit): ?>
    <p><a class="btn btn-primary" href="<?= e($listUrl . '&new=1') ?>">Create New Message</a></p>
  <?php endif; ?>
  <form method="get" class="inline-form" style="margin:0 0 12px">
    <input type="hidden" name="operational_period_id" value="<?= (int)$opId ?>">
    <label for="ics213_q" class="small">Find a message (reference code from a reply email, subject, to or from)</label>
    <input id="ics213_q" name="q" value="<?= e($q) ?>" placeholder="e.g. CB-213-7K2QX">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= e($listUrl) ?>">Clear</a><?php endif; ?>
  </form>
  <table class="data-table cb-cards">
    <thead><tr><th>Date/Time</th><th>Reference</th><th>To</th><th>From</th><th>Subject</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$msgs): ?>
        <tr><td colspan="7"><?= $q !== '' ? 'No message matches that search.' : 'No ICS 213 messages for this operational period yet.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($msgs as $m): $label = ics213_status_label($m); ?>
        <tr>
          <td><?= e($m['date_time'] ?? '') ?></td>
          <td><?= e($m['ref_code'] ?? '') ?></td>
          <td><?= e($m['to_name'] ?? '') ?></td>
          <td><?= e($m['from_name'] ?? '') ?></td>
          <td><?= e($m['subject'] ?? '') ?></td>
          <td><span class="<?= in_array($m['status'] ?? '', ['awaiting_approval', 'returned'], true) ? 'priority-high' : '' ?>"><?= e($label) ?></span></td>
          <td><a class="btn" href="<?= e(app_url('/forms/ics213/index.php?operational_period_id=' . (int)($m['msg_op_id'] ?: $opId) . '&message_id=' . (int)$m['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($incidentId > 0):
    $msg = $messageId > 0 && !$isNewMessage ? ics213_load_message($pdo, $incidentId, $messageId) : null;
    if ($messageId > 0 && !$isNewMessage && !$msg): ?>
      <div class="card"><p>That message could not be found.</p><p><a class="btn" href="<?= e($listUrl) ?>">Back to List</a></p></div>
<?php else:
    if ($msg) { ics213_mark_read((int)$msg['id']); }
    $status = (string)($msg['status'] ?? '');
    $isSender = $msg && (int)($msg['from_user_id'] ?? 0) === $me;
    // Editable: a new message, or a returned one opened by its sender.
    $editable = $canEdit && (!$msg || ($status === 'returned' && ($isSender || $isSysadmin)));
    $refCode = $msg ? (string)($msg['ref_code'] ?? '') : ics213_new_ref_code();
    $contacts205a = ics213_205a_contacts($incidentId, $opId);
    $holdersMode = ics213_email_holders_mode();
    $dt = static fn($v): string => $v ? str_replace(' ', 'T', substr((string)$v, 0, 16)) : '';
    $pendingRows = $msg ? ics213_pending_sends((int)$msg['id']) : [];
    $pdfUrls = $msg && function_exists('ics_form_pdf_urls')
        ? ics_form_pdf_urls('ICS213', ['incident_id' => $incidentId, 'op_id' => $opId, 'extra_query' => ['message_id' => (int)$msg['id']]]) : ['available' => false];
?>
<?php if ($msg): ?>
<div class="card ics213-status ics213-status-<?= e($status) ?>">
  <p class="cb-form-eyebrow">ICS 213 <?= e($refCode) ?></p>
  <h2><?= e(ics213_status_label($msg)) ?></h2>
  <?php if ($status === 'awaiting_approval'): ?>
    <p>Signed by <?= e((string)$msg['from_name']) ?><?= $msg['signed_at'] ? ' on ' . e((string)$msg['signed_at']) : '' ?>.
      <strong>Not sent yet.</strong> Waiting for <strong><?= e(ics213_user_label((int)$msg['approver_user_id'])) ?></strong> to approve emailing it.</p>
    <?php if (ics213_can_approve($msg)): ?>
      <form method="post" class="cb-lock-exempt"><?= csrf_field() ?>
        <p><button type="submit" name="ics213_action" value="approve" class="btn btn-success" onclick="return confirm('Approve this message and send it now? Emails cannot be recalled.');">Approve and Send</button></p>
        <div class="cb-form-reason"><label for="return_reason">Or return it for changes (reason required)</label>
          <input type="text" id="return_reason" name="return_reason" maxlength="300">
          <button type="submit" name="ics213_action" value="return" class="btn btn-secondary">Return for changes</button>
          <button type="submit" name="ics213_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this message? It will not be sent.');">Cancel message</button></div>
      </form>
    <?php endif; ?>
    <?php if ($isSender || $isSysadmin): $cands = signoff_approver_candidates($incidentId, (int)$msg['from_user_id']); ?>
      <form method="post" class="cb-lock-exempt cb-form-approver"><?= csrf_field() ?>
        <label for="approver_user_id">Change approver</label>
        <select id="approver_user_id" name="approver_user_id">
          <?php foreach ($cands as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$msg['approver_user_id'] ? ' selected' : '' ?>><?= e($c['name'] . ($c['roles'] ? ' (' . $c['roles'] . ')' : '')) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" name="ics213_action" value="change_approver" class="btn btn-primary">Send for approval</button>
        <?php if ($isSender): ?><button type="submit" name="ics213_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this message? It will not be sent.');">Cancel message</button><?php endif; ?>
      </form>
    <?php endif; ?>
  <?php elseif ($status === 'returned'): ?>
    <p><strong>Returned for changes:</strong> <?= e((string)$msg['return_reason']) ?></p>
    <p class="small">The sender can change the message below and sign it again, or cancel it. It has not been sent.</p>
  <?php elseif ($status === 'cancelled'): ?>
    <p>This message was cancelled and was not sent.</p>
  <?php else: ?>
    <p>Sent<?= $msg['sent_at'] ? ' on ' . e((string)$msg['sent_at']) : '' ?>.<?= trim((string)$msg['approved_by']) !== '' ? ' Approved by ' . e((string)$msg['approved_by']) . '.' : '' ?>
      The message is locked; only the reply can be added or changed.</p>
  <?php endif; ?>
  <?php if (!empty($pdfUrls['available'])): ?>
    <p><a class="btn" target="_blank" rel="noopener" href="<?= e((string)$pdfUrls['preview']) ?>">Preview PDF</a>
       <a class="btn" target="_blank" rel="noopener" href="<?= e((string)$pdfUrls['download']) ?>">Download PDF</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>Original Message</h3>
  <?php if ($refCode !== ''): ?><p><strong>Reference:</strong> <?= e($refCode) ?> <span class="small">(goes in the email subject; use it to find this message when a reply comes back by email)</span></p><?php endif; ?>
  <form method="post" class="cb-lock-exempt" id="ics213-form">
    <?= csrf_field() ?>
    <?php if (!$msg): ?><input type="hidden" name="ref_code" value="<?= e($refCode) ?>"><?php endif; ?>
    <div class="grid-2">
      <label>1. Incident Name<input value="<?= e($incident['incident_name'] ?? $incident['name'] ?? '') ?>" readonly></label>
      <label>Operational Period<input value="<?= e(ics213_op_label($pdo, $opId)) ?>" readonly></label>
    </div>
    <div class="grid-2">
      <?php if (!$editable): ?>
        <label>2. To<input value="<?= e($msg['to_name'] ?? '') ?>" readonly></label>
      <?php else: $filled = ics213_filled_positions($incidentId); $curRole = (string)($msg['to_role_code'] ?? ''); ?>
        <div>
          <label>2. To (position)
            <select name="to_role_code" id="ics213_to_role">
              <option value="">-- Not in the incident organization (type the name below) --</option>
              <?php foreach ($filled as $code => $p): ?>
                <option value="<?= e($code) ?>" data-to="<?= e(implode(' / ', $p['names']) . ' (' . $p['title'] . ')') ?>"<?= $code === $curRole ? ' selected' : '' ?>><?= e($code . ' - ' . $p['title'] . ': ' . implode(', ', $p['names'])) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Name and position<input name="to_name" id="ics213_to_name" value="<?= e($msg['to_name'] ?? '') ?>" placeholder="Filled in from the position, or type a name"></label>
          <p class="small">Picking a position puts the message on the dashboard of everyone holding it.</p>
        </div>
      <?php endif; ?>
      <label>3. From<input name="from_name" value="<?= e($msg['from_name'] ?? ($user['real_name'] ?? $user['display_name'] ?? $user['username'] ?? '')) ?>" <?= $editable ? '' : 'readonly' ?>></label>
    </div>
    <div class="grid-2">
      <label>4. Subject<input name="subject" value="<?= e($msg['subject'] ?? '') ?>" <?= $editable ? '' : 'readonly' ?>></label>
      <label>5. Date/Time<input type="datetime-local" name="date_time" value="<?= e($msg ? $dt($msg['date_time'] ?? '') : date('Y-m-d\TH:i')) ?>" <?= $editable ? '' : 'readonly' ?>></label>
    </div>
    <?php if ($editable): ?>
    <div class="ics213-guidance">
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
    <label>6. Message<textarea name="message" rows="6" <?= $editable ? '' : 'readonly' ?>><?= e($msg['message'] ?? '') ?></textarea></label>
    <label>7. Approved By / Signature<input name="approved_by" id="ics213_approved_by" value="<?= e($msg['approved_by'] ?? '') ?>" <?= $editable ? '' : 'readonly' ?>></label>
    <?php if ($editable): ?>
      <p class="small" id="ics213_block7_note">Optional for a message that is not emailed. When the message is emailed, Block 7 is filled in by whoever approves it<?= $isIc ? ' (as the IC, you)' : '' ?>.</p>

      <h3>Send by email (optional)</h3>
      <?php if ($holdersMode === 'always'): ?>
        <p class="small">The people holding the "To" position are also emailed, if their account has an email address.</p>
        <input type="hidden" id="ics213_holders_always" value="1">
      <?php else: ?>
        <label class="checkbox-line"><input type="checkbox" name="email_holders" value="1" id="ics213_email_holders"> Also email the people holding the "To" position</label>
      <?php endif; ?>
      <?= ics213_recipient_picker_html($contacts205a, $msg ? implode(', ', array_column($pendingRows, 'email')) : '') ?>
      <?php if (!$isIc): [$defApprover] = ics213_default_approver($incidentId, $opId, $me); $cands = signoff_approver_candidates($incidentId, $me); ?>
        <div id="ics213_approver_box">
          <label>Approver (only for a message that is emailed)
            <select name="approver_user_id">
              <?php foreach ($cands as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $defApprover ? ' selected' : '' ?>><?= e($c['name'] . ($c['roles'] ? ' (' . $c['roles'] . ')' : '')) ?></option><?php endforeach; ?>
            </select>
          </label>
          <p class="small">An email can't be recalled, so a message that is emailed is approved first. Nothing is sent until then.</p>
        </div>
      <?php endif; ?>
      <p class="form-actions">
        <button type="submit" name="ics213_action" value="send" class="btn btn-success" id="ics213_send_btn"
          onclick="return confirm(this.getAttribute('data-confirm') || 'Sign and send this message now?');">Sign and Send ICS 213</button>
        <?php if ($msg && $isSender): ?><button type="submit" name="ics213_action" value="cancel" class="btn btn-secondary" onclick="return confirm('Cancel this message? It will not be sent.');">Cancel message</button><?php endif; ?>
        <a class="btn" href="<?= e($listUrl) ?>">Back to List</a>
      </p>
    <?php elseif ($status === 'sent'): ?>
      <h3>Reply</h3>
      <label>8. Reply<textarea name="reply" rows="6" <?= $canEdit ? '' : 'readonly' ?>><?= e($msg['reply'] ?? '') ?></textarea></label>
      <div class="grid-2">
        <label>Reply By<input name="reply_by" value="<?= e($msg['reply_by'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
        <label>ICS Position<input name="reply_position" value="<?= e($msg['reply_position'] ?? '') ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
      </div>
      <div class="grid-2">
        <label>Reply Date/Time<input type="datetime-local" name="reply_date_time" value="<?= e($dt($msg['reply_date_time'] ?? '') ?: date('Y-m-d\TH:i')) ?>" <?= $canEdit ? '' : 'readonly' ?>></label>
        <label>Reply received
          <select name="reply_received_via" <?= $canEdit ? '' : 'disabled' ?>>
            <option value="app"<?= ($msg['reply_received_via'] ?? '') !== 'email' ? ' selected' : '' ?>>Entered here in Command Bridge</option>
            <option value="email"<?= ($msg['reply_received_via'] ?? '') === 'email' ? ' selected' : '' ?>>By email (typed in from the email)</option>
          </select>
        </label>
      </div>
      <label>Reply came from (email)<input type="email" name="reply_email" value="<?= e($msg['reply_email'] ?? '') ?>" placeholder="only for a reply received by email" <?= $canEdit ? '' : 'readonly' ?>></label>
      <p class="form-actions">
        <?php if ($canEdit): ?><button type="submit" name="ics213_action" value="reply" class="btn btn-primary">Save reply</button><?php endif; ?>
        <a class="btn" href="<?= e($listUrl) ?>">Back to List</a>
      </p>
    <?php else: ?>
      <p><a class="btn" href="<?= e($listUrl) ?>">Back to List</a></p>
    <?php endif; ?>
  </form>
  <?php if ($editable): ?>
  <script>
  (function () {
    var form = document.getElementById('ics213-form'), sel = document.getElementById('ics213_to_role'), name = document.getElementById('ics213_to_name'), auto = '';
    var btn = document.getElementById('ics213_send_btn'), approverBox = document.getElementById('ics213_approver_box');
    var isIc = <?= $isIc ? 'true' : 'false' ?>;
    if (sel && name) {
      sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex], v = opt ? (opt.getAttribute('data-to') || '') : '';
        if (name.value === '' || name.value === auto) { name.value = v; auto = v; }
        update();
      });
    }
    // The button says what will happen: emailed messages need approval (except the IC's).
    function emailing() {
      var holders = document.getElementById('ics213_email_holders'), always = document.getElementById('ics213_holders_always');
      var toPos = sel && sel.value !== '';
      if (toPos && ((holders && holders.checked) || always)) { return true; }
      if (form.querySelector('input[name="email_contacts[]"]:checked')) { return true; }
      var typed = form.querySelector('textarea[name="email_addresses"]');
      return !!(typed && /\S+@\S+/.test(typed.value));
    }
    function update() {
      var needs = emailing() && !isIc;
      btn.textContent = needs ? 'Sign and Request Approval' : 'Sign and Send ICS 213';
      btn.setAttribute('data-confirm', needs ? 'Sign this message and send it for approval? It is emailed only once it is approved.'
        : (emailing() ? 'Sign and send this message now? Emails cannot be recalled.' : 'Sign and send this message now?'));
      if (approverBox) { approverBox.style.display = needs ? '' : 'none'; }
    }
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
  }());
  </script>
  <?php endif; ?>
</div>

<?php if ($msg && $status === 'sent'): $sends = ics213_sends((int)$msg['id']); ?>
<div class="card">
  <h2>Email</h2>
  <p class="small">"Accepted" means the mail server took the email. A bounce (for example a mistyped address) comes back later
  to Command Bridge's sending mailbox, not to this page.</p>
  <?php if (!$sends): ?>
    <p class="muted">This message has not been emailed.</p>
  <?php else: ?>
    <table class="cb-cards"><thead><tr><th>Sent to</th><th>Status</th><th>When</th><th>By</th><th></th></tr></thead><tbody>
    <?php foreach ($sends as $snd): $st = (string)$snd['status']; ?>
      <tr>
        <td><?= e((string)$snd['recipient_label']) ?><?= (string)$snd['recipient_label'] !== (string)$snd['email'] ? '<br><span class="small">' . e((string)$snd['email']) . '</span>' : '' ?></td>
        <td><?php if ($st === 'sent'): ?>Accepted by mail server
            <?php elseif ($st === 'failed'): ?><span class="priority-high">Failed</span><br><span class="small"><?= e((string)$snd['error']) ?></span>
            <?php elseif ($st === 'pending'): ?><span class="priority-high">Waiting for approval</span><br><span class="small"><?= e(ics213_user_label((int)$snd['approver_user_id'])) ?></span>
            <?php else: ?><?= e(ucfirst($st)) ?><?php endif; ?></td>
        <td><?= e((string)$snd['sent_at']) ?></td>
        <td><?= e((string)($snd['sent_by'] ?? '')) ?></td>
        <td><?php if ($canEdit && in_array($st, ['sent', 'failed'], true)): ?>
          <form method="post" class="cb-lock-exempt"><?= csrf_field() ?><input type="hidden" name="send_id" value="<?= (int)$snd['id'] ?>">
            <button type="submit" name="ics213_action" value="resend" class="btn btn-secondary btn-small">Resend</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  <?php
    $mine = array_filter($pendingRows, static fn($r) => ics213_can_approve($msg, (int)$r['approver_user_id']));
    $myRequests = array_filter($pendingRows, static fn($r) => (int)$r['sent_by_user_id'] === $me);
  ?>
  <?php if ($mine || $myRequests): ?>
    <form method="post" class="cb-lock-exempt"><?= csrf_field() ?>
      <?php if ($mine): ?>
        <p><strong><?= count($mine) ?> email<?= count($mine) === 1 ? '' : 's' ?> waiting for your approval.</strong></p>
        <button type="submit" name="ics213_action" value="approve_pending" class="btn btn-success" onclick="return confirm('Approve and send these emails now?');">Approve and send</button>
      <?php endif; ?>
      <button type="submit" name="ics213_action" value="cancel_pending" class="btn btn-secondary">Cancel the waiting emails</button>
    </form>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <h3>Send to more people</h3>
    <form method="post" class="cb-lock-exempt">
      <?= csrf_field() ?>
      <?php if (!empty($msg['to_role_code'])): ?>
        <label class="checkbox-line"><input type="checkbox" name="email_holders" value="1"> Email the people holding the "To" position (<?= e((string)$msg['to_role_code']) ?>)</label>
      <?php endif; ?>
      <?= ics213_recipient_picker_html($contacts205a) ?>
      <?php if (!$isIc): ?><p class="small">These emails are sent once your approver approves them.</p><?php endif; ?>
      <p><button type="submit" name="ics213_action" value="email" class="btn btn-primary"><?= $isIc ? 'Send email' : 'Request approval to email' ?></button></p>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
