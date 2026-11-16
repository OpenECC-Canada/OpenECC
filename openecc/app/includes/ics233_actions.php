<?php
declare(strict_types=1);

/**
 * ICS 233 Incident Open Action Tracker: tasks the IC/UC hands to the team that are not incident objectives.
 * One running list per incident (open actions carry over from one operational period to the next). The Planning
 * Section Chief keeps it (with the Documentation Unit); the person in the "For / POC" position can mark it briefed,
 * update its status and mark it done. Table form_233_actions (migration 0011).
 */

require_once __DIR__ . '/ics213_messages.php';   // ics213_filled_positions()
require_once __DIR__ . '/signoff.php';           // signoff_position_holders()

function ics233_actions(int $incidentId, bool $completed): array
{
    if ($incidentId <= 0) { return []; }
    $st = db()->prepare('SELECT a.*, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS briefed_by_name
        FROM form_233_actions a LEFT JOIN users u ON u.id = a.briefed_by_user_id
        WHERE a.incident_id = ? AND a.deleted_at IS NULL AND a.is_completed = ? ORDER BY a.item_no, a.id');
    $st->execute([$incidentId, $completed ? 1 : 0]);
    return $st->fetchAll() ?: [];
}

function ics233_action(int $incidentId, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM form_233_actions WHERE id = ? AND incident_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $incidentId]);
    return $st->fetch() ?: null;
}

function ics233_next_item_no(int $incidentId): int
{
    $st = db()->prepare('SELECT COALESCE(MAX(item_no), 0) + 1 FROM form_233_actions WHERE incident_id = ?');
    $st->execute([$incidentId]);
    return (int)$st->fetchColumn();
}

/** "For / POC" as shown on the form: the position (and who holds it) or the typed name. */
function ics233_poc_label(array $a, array $filled): string
{
    $code = strtoupper((string)($a['poc_role_code'] ?? ''));
    $text = trim((string)($a['poc_text'] ?? ''));
    if ($code === '') { return $text; }
    $who = isset($filled[$code]) ? implode(' / ', $filled[$code]['names']) : '';
    return $code . ($who !== '' ? ' (' . $who . ')' : '') . ($text !== '' ? ' - ' . $text : '');
}

/** Is the signed-in user the POC (holds the POC position)? */
function ics233_user_is_poc(int $incidentId, array $a): bool
{
    $code = strtoupper((string)($a['poc_role_code'] ?? ''));
    if ($code === '' || $incidentId <= 0) { return false; }
    $mine = array_map('strtoupper', get_user_role_codes($incidentId, (int)(current_user()['id'] ?? 0)));
    return in_array($code, $mine, true);
}

/** 'overdue', 'due' (today or tomorrow) or '' for an open action. */
function ics233_due_state(array $a, ?string $today = null): string
{
    if (!empty($a['is_completed'])) { return ''; }
    $target = (string)($a['target_date'] ?? '');
    if ($target === '') { return ''; }
    $today = $today ?? date('Y-m-d');
    if ($target < $today) { return 'overdue'; }
    return $target <= date('Y-m-d', strtotime($today . ' +1 day')) ? 'due' : '';
}

function ics233_url(int $id = 0): string
{
    return app_url('/forms/ics233/index.php') . ($id > 0 ? '?edit=' . $id . '#a' . $id : '');
}

/** Dashboard notice to everyone holding the POC position. */
function ics233_notify_poc(int $incidentId, array $a, string $title, string $body): void
{
    $code = strtoupper((string)($a['poc_role_code'] ?? ''));
    if ($code === '') { return; }
    ics233_notify_users($incidentId, signoff_position_holders($incidentId, $code), $a, $title, $body);
}

function ics233_notify_users(int $incidentId, array $userIds, array $a, string $title, string $body): void
{
    $me = (int)(current_user()['id'] ?? 0);
    $st = db()->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url,
            related_table, related_id, urgency, is_read, created_at)
        VALUES (?, ?, NULL, NULL, 'ics233_action', ?, ?, ?, ?, 'form_233_actions', ?, 'routine', 0, NOW())");
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid <= 0 || $uid === $me) { continue; }
        try { $st->execute([$incidentId, $uid, $title, $title, mb_substr($body, 0, 500), ics233_url((int)$a['id']), (int)$a['id']]); }
        catch (Throwable $e) { security_log($e, 'ics233_notify'); }
    }
}

/** Clears the signed-in user's notices about one action (when they act on it). */
function ics233_mark_read(int $actionId): void
{
    $me = (int)(current_user()['id'] ?? 0);
    if ($actionId <= 0 || $me <= 0) { return; }
    try {
        db()->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE related_table = 'form_233_actions' AND related_id = ? AND user_id = ? AND is_read = 0")->execute([$actionId, $me]);
    } catch (Throwable $e) { /* not important */ }
}

/** A date from a form field (YYYY-MM-DD) or null. */
function ics233_date_or_null(string $v): ?string
{
    $v = trim($v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
