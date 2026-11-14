<?php
declare(strict_types=1);
/*
 * ICS 213 General Message: addressing and notifications.
 *  - "To" can be picked from the positions filled in the incident. Everyone holding that position gets a dashboard
 *    notification (one each, so each person's read/unread is their own).
 *  - When the message is answered, the sender is notified.
 *  - Opening the message marks your own notification for it as read.
 * Columns form_213_messages.to_role_code and from_user_id come from migration 0007.
 */

/** Positions currently filled in the incident, for the "To" list: [role_code => ['title' => ..., 'names' => [...]]]. */
function ics213_filled_positions(int $incidentId): array
{
    if ($incidentId <= 0) { return []; }
    try {
        $st = db()->prepare("SELECT r.role_code, r.role_name, COALESCE(NULLIF(u.real_name, ''), u.username) AS person
            FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id
            INNER JOIN users u ON u.id = ip.user_id
            WHERE ip.incident_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND u.is_active = 1 AND u.deleted_at IS NULL AND r.role_code <> 'SYSADMIN'
            ORDER BY r.role_name, person");
        $st->execute([$incidentId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $code = strtoupper((string)$r['role_code']);
            $out[$code]['title'] = (string)$r['role_name'];
            $out[$code]['names'][] = (string)$r['person'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function ics213_message_url(int $opId, int $messageId): string
{
    return app_url('/forms/ics213/index.php') . '?' . http_build_query(['operational_period_id' => $opId, 'message_id' => $messageId]);
}

/** One dashboard notification per person. */
function ics213_notify_users(int $incidentId, array $userIds, int $messageId, string $title, string $body, string $link, string $type): void
{
    $me = (int)(current_user()['id'] ?? 0);
    $st = db()->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url,
            related_table, related_id, urgency, is_read, created_at)
        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, 'form_213_messages', ?, 'high', 0, NOW())");
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid <= 0 || $uid === $me) { continue; }
        try { $st->execute([$incidentId, $uid, $type, $title, $title, $body, $link, $messageId]); }
        catch (Throwable $e) { security_log($e, 'ics213_notify'); }
    }
}

/** New message: tell everyone holding the "To" position. */
function ics213_notify_new_message(int $incidentId, int $opId, int $messageId, string $toRoleCode, string $fromName, string $subject, string $message): void
{
    if ($toRoleCode === '') { return; }
    require_once __DIR__ . '/signoff.php';
    $holders = signoff_position_holders($incidentId, strtoupper($toRoleCode));
    $title = 'ICS 213 message for you' . ($subject !== '' ? ': ' . mb_substr($subject, 0, 120) : '');
    $body = 'From ' . ($fromName !== '' ? $fromName : 'someone on the incident') . ': ' . mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 300);
    ics213_notify_users($incidentId, $holders, $messageId, $title, $body, ics213_message_url($opId, $messageId), 'ics213_message');
}

/** First reply: tell the sender. */
function ics213_notify_reply(int $incidentId, int $opId, array $msg, string $replyBy, string $reply): void
{
    $sender = (int)($msg['from_user_id'] ?? 0);
    if ($sender <= 0) { return; }
    $title = 'Reply to your ICS 213' . (!empty($msg['subject']) ? ': ' . mb_substr((string)$msg['subject'], 0, 120) : '');
    $body = ($replyBy !== '' ? $replyBy : 'The addressee') . ' replied: ' . mb_substr(preg_replace('/\s+/', ' ', $reply) ?? '', 0, 300);
    ics213_notify_users($incidentId, [$sender], (int)$msg['id'], $title, $body, ics213_message_url($opId, (int)$msg['id']), 'ics213_reply');
}

/** Opening a message clears the signed-in person's own notifications about it. */
function ics213_mark_read(int $messageId): void
{
    $me = (int)(current_user()['id'] ?? 0);
    if ($messageId <= 0 || $me <= 0) { return; }
    try {
        db()->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE related_table = 'form_213_messages' AND related_id = ? AND user_id = ? AND is_read = 0")->execute([$messageId, $me]);
    } catch (Throwable $e) { /* not important */ }
}

// ================================================================================================ Sending by email
// The message goes as plain text in the email body with the official ICS 213 attached as a PDF. The subject carries
// the incident and a reference code (CB-213-XXXXX) so a reply that comes back by email can be found with the search
// on the ICS 213 list and typed in by hand. Replies go to the sender's own email (Reply-To).

/** A new, unused reference code such as CB-213-7K2QX (no 0/O, 1/I/L, so it is easy to read out). */
function ics213_new_ref_code(): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    for ($try = 0; $try < 20; $try++) {
        $code = 'CB-213-';
        for ($i = 0; $i < 5; $i++) { $code .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM form_213_messages WHERE ref_code = ?');
            $st->execute([$code]);
            if ((int)$st->fetchColumn() === 0) { return $code; }
        } catch (Throwable $e) { return $code; }
    }
    return 'CB-213-' . strtoupper(bin2hex(random_bytes(4)));
}

function ics213_valid_ref_code(string $code): bool
{
    return (bool)preg_match('/^CB-213-[2-9A-HJKMNP-Z]{5}$/', $code);
}

/** 'always' = email the people holding the "To" position every time; 'ticked' = only when the sender ticks the box. */
function ics213_email_holders_mode(): string
{
    $v = strtolower(trim((string)get_app_setting('ics213_email_position_holders', 'ticked')));
    return $v === 'always' ? 'always' : 'ticked';
}

/** Contacts with an email on the current operational period's ICS 205A: [entry id => ['name','position','email']]. */
function ics213_205a_contacts(int $incidentId, int $opId): array
{
    if ($incidentId <= 0 || $opId <= 0) { return []; }
    try {
        $st = db()->prepare("SELECT e.id, e.person_name, e.incident_assigned_position, e.email, e.contact_methods
            FROM form_205a_entries e
            INNER JOIN form_205a_headers h ON h.id = e.header_id
            INNER JOIN form_records fr ON fr.id = h.form_record_id
            WHERE fr.incident_id = ? AND fr.operational_period_id = ? AND fr.is_deleted = 0 AND e.deleted_at IS NULL
              AND REPLACE(REPLACE(UPPER(fr.form_type), ' ', ''), '-', '') = 'ICS205A'
            ORDER BY e.incident_assigned_position, e.person_name");
        $st->execute([$incidentId, $opId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string)$r['contact_methods'], $m)) { $email = $m[0]; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
            $out[(int)$r['id']] = ['name' => (string)$r['person_name'], 'position' => (string)$r['incident_assigned_position'], 'email' => $email];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** People holding a position, with their account email: [['user_id', 'name', 'email'], ...]. */
function ics213_position_holder_emails(int $incidentId, string $roleCode): array
{
    if ($roleCode === '') { return []; }
    require_once __DIR__ . '/signoff.php';
    $ids = signoff_position_holders($incidentId, strtoupper($roleCode));
    if (!$ids) { return []; }
    $st = db()->prepare('SELECT id, COALESCE(NULLIF(real_name, \'\'), username) AS name, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    return array_map(static fn($r) => ['user_id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => trim((string)$r['email'])], $st->fetchAll());
}

/** Splits a typed list of addresses (commas, semicolons, spaces or new lines). Returns [valid[], invalid[]]. */
function ics213_parse_addresses(string $text): array
{
    $valid = []; $invalid = [];
    foreach (preg_split('/[\s,;]+/', $text) ?: [] as $a) {
        $a = trim($a, " <>\t\r\n");
        if ($a === '') { continue; }
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) { $valid[strtolower($a)] = $a; } else { $invalid[] = $a; }
    }
    return [array_values($valid), $invalid];
}

/** Subject and plain-text body of the email. */
function ics213_email_content(array $msg, array $incident, string $opLabel): array
{
    $incidentName = (string)($incident['incident_name'] ?? $incident['name'] ?? '');
    $ref = (string)($msg['ref_code'] ?? '');
    $subject = 'ICS 213' . ($ref !== '' ? ' [' . $ref . ']' : '') . ($incidentName !== '' ? ' ' . $incidentName : '')
        . (trim((string)$msg['subject']) !== '' ? ' - ' . trim((string)$msg['subject']) : '');
    $lines = [
        'ICS 213 GENERAL MESSAGE',
        $ref !== '' ? 'Reference: ' . $ref : null,
        'Incident: ' . $incidentName,
        $opLabel !== '' ? 'Operational period: ' . $opLabel : null,
        'To: ' . (string)$msg['to_name'],
        'From: ' . (string)$msg['from_name'],
        'Subject: ' . (string)$msg['subject'],
        'Date/Time: ' . (string)$msg['date_time'],
        '',
        'MESSAGE',
        (string)$msg['message'],
        '',
        trim((string)($msg['approved_by'] ?? '')) !== '' ? 'Approved by: ' . (string)$msg['approved_by'] : null,
        '',
        '--',
        'The official ICS 213 is attached as a PDF.',
        $ref !== '' ? 'If you reply by email, please keep the reference ' . $ref . ' in the subject.' : null,
    ];
    $lines = array_filter($lines, static fn($l) => $l !== null);
    return [mb_substr($subject, 0, 250), implode("\n", $lines) . "\n"];
}

/** The official ICS 213 PDF of one message, as bytes (or null with the reason in $error). */
function ics213_pdf_bytes(array $msg, array $incident, ?string &$error = null): ?string
{
    try {
        require_once __DIR__ . '/pdf/pdf_request.php';
        require_once __DIR__ . '/pdf/OfficialPdfTemplateExporter.php';
        require_once dirname(__DIR__) . '/forms/ics213/Ics213OfficialPdfFieldMapper.php';
        $exporter = new OfficialPdfTemplateExporter();
        $path = $exporter->create('ICS213', Ics213OfficialPdfFieldMapper::map($msg, $incident));
        $bytes = @file_get_contents($path);
        @unlink($path);
        if ($bytes === false || strncmp($bytes, '%PDF-', 5) !== 0) { $error = 'The PDF could not be made.'; return null; }
        return $bytes;
    } catch (Throwable $e) {
        security_log($e, 'ics213_pdf');
        $error = 'The PDF could not be made.';
        return null;
    }
}

/**
 * Emails one message to each recipient (['type' => position|contact|address, 'label' => ..., 'email' => ...]) and
 * logs every attempt in form_213_sends. Returns ['sent' => n, 'failed' => n, 'errors' => [...]].
 */
function ics213_send_email(int $incidentId, array $msg, array $recipients, string $opLabel): array
{
    require_once __DIR__ . '/mail.php';
    $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
    $recipients = array_values(array_filter($recipients, static fn($r) => filter_var((string)($r['email'] ?? ''), FILTER_VALIDATE_EMAIL)));
    if (!$recipients) { return $result; }
    $incident = active_incident() ?: [];
    [$subject, $body] = ics213_email_content($msg, $incident, $opLabel);
    $pdfError = null;
    $pdf = ics213_pdf_bytes($msg, $incident, $pdfError);
    $opt = [];
    if ($pdf !== null) {
        $opt['attachments'] = [['name' => 'ICS213_' . ((string)($msg['ref_code'] ?? '') ?: 'message_' . (int)$msg['id']) . '.pdf', 'type' => 'application/pdf', 'data' => $pdf]];
    } else {
        $body .= "\n(The PDF could not be attached; the full message is above.)\n";
    }
    $me = current_user() ?: [];
    $myEmail = trim((string)($me['email'] ?? ''));
    if ($myEmail === '' && !empty($me['id'])) {
        $st = db()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([(int)$me['id']]);
        $myEmail = trim((string)$st->fetchColumn());
    }
    if (filter_var($myEmail, FILTER_VALIDATE_EMAIL)) { $opt['reply_to'] = $myEmail; }
    $log = db()->prepare('INSERT INTO form_213_sends (message_id, incident_id, recipient_type, recipient_label, email, status, error, sent_by_user_id, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $seen = [];
    foreach ($recipients as $r) {
        $email = trim((string)$r['email']);
        if (isset($seen[strtolower($email)])) { continue; }
        $seen[strtolower($email)] = true;
        $res = app_send_mail_result($email, $subject, $body, $opt);
        $ok = !empty($res['ok']);
        $result[$ok ? 'sent' : 'failed']++;
        if (!$ok) { $result['errors'][] = $email . ': ' . (string)($res['message'] ?? 'not sent'); }
        try {
            $log->execute([(int)$msg['id'], $incidentId, (string)($r['type'] ?? 'address'), mb_substr((string)($r['label'] ?? $email), 0, 255),
                $email, $ok ? 'sent' : 'failed', $ok ? null : mb_substr((string)($res['message'] ?? ''), 0, 255), (int)($me['id'] ?? 0) ?: null]);
        } catch (Throwable $e) { security_log($e, 'ics213_send_log'); }
    }
    audit_log((int)($me['id'] ?? 0) ?: null, 'email', 'ICS213', (int)$msg['form_record_id'],
        'Emailed ICS 213 ' . ((string)($msg['ref_code'] ?? '') ?: '#' . (int)$msg['id']) . ': ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed ('
        . mb_substr(implode(', ', array_keys($seen)), 0, 600) . ')');
    return $result;
}

/** Email log of one message, newest first. */
function ics213_sends(int $messageId): array
{
    try {
        $st = db()->prepare('SELECT s.*, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS sent_by
            FROM form_213_sends s LEFT JOIN users u ON u.id = s.sent_by_user_id WHERE s.message_id = ? ORDER BY s.sent_at DESC, s.id DESC');
        $st->execute([$messageId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Recipients chosen on the form: the "To" position's holders (when emailing them), ticked ICS 205A contacts and
 * typed addresses. Returns [recipients[], problems[]].
 */
function ics213_recipients_from_post(int $incidentId, int $opId, string $toRole, bool $emailHolders): array
{
    $recipients = []; $problems = [];
    if ($toRole !== '' && $emailHolders) {
        foreach (ics213_position_holder_emails($incidentId, $toRole) as $h) {
            if (filter_var($h['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['type' => 'position', 'label' => $h['name'] . ' (' . $toRole . ')', 'email' => $h['email']];
            } else {
                $problems[] = $h['name'] . ' (' . $toRole . ') has no email address on their account, so they only get the dashboard notice.';
            }
        }
    }
    $contacts = ics213_205a_contacts($incidentId, $opId);
    foreach ((array)($_POST['email_contacts'] ?? []) as $cid) {
        $c = $contacts[(int)$cid] ?? null;
        if ($c) { $recipients[] = ['type' => 'contact', 'label' => $c['name'] . ($c['position'] !== '' ? ' (' . $c['position'] . ')' : ''), 'email' => $c['email']]; }
    }
    [$typed, $bad] = ics213_parse_addresses((string)($_POST['email_addresses'] ?? ''));
    foreach ($typed as $a) { $recipients[] = ['type' => 'address', 'label' => $a, 'email' => $a]; }
    foreach ($bad as $b) { $problems[] = '"' . $b . '" is not a valid email address and was skipped.'; }
    return [$recipients, $problems];
}

/** The "email to" part of the ICS 213 form: ICS 205A contacts to tick, plus a box for any other addresses. */
function ics213_recipient_picker_html(array $contacts): string
{
    $h = '';
    if ($contacts) {
        $h .= '<fieldset class="ics213-contacts"><legend>Contacts from this period&rsquo;s ICS 205A</legend>';
        foreach ($contacts as $id => $c) {
            $h .= '<label class="checkbox-line"><input type="checkbox" name="email_contacts[]" value="' . (int)$id . '"> '
                . e($c['name']) . ($c['position'] !== '' ? ' &middot; ' . e($c['position']) : '') . ' <span class="small">' . e($c['email']) . '</span></label>';
        }
        $h .= '</fieldset>';
    } else {
        $h .= '<p class="small">No contacts with an email address on this period&rsquo;s ICS 205A yet.</p>';
    }
    $h .= '<label>Other email addresses<textarea name="email_addresses" rows="2" placeholder="name@example.ca, another@example.ca"></textarea></label>'
        . '<p class="small">Separate addresses with commas or new lines. Replies go to your own email address.</p>';
    return $h;
}
