<?php
declare(strict_types=1);

/**
 * Incident facilities (#44): the ECC, reception centres, staging areas, camps and the like.
 *
 * Facilities live in external_checkin_locations, the table that already held the check-in locations, extended by
 * migration 0017 with facility details. A facility marked as a check-in point is what Companion Check-In, the
 * Check-In Gateway and the ICS 213RR "expected check-in / receiving location" use; other facilities stay on this
 * server. Contact names, phone numbers and notes are never sent to the internet-facing gateway
 * (external_checkin_enqueue_location_snapshot() sends only name, type, address, token and status).
 *
 * Permissions: form code FACILITIES in Form Permissions (view = see the list, create/edit = manage it).
 */

require_once __DIR__ . '/external_checkin_sync.php';

/** Services a facility can offer (stored comma-separated in `services`). */
function cb_facility_services(): array
{
    return ['power' => 'Power', 'generator' => 'Generator', 'water' => 'Potable water', 'washrooms' => 'Washrooms', 'showers' => 'Showers',
        'kitchen' => 'Kitchen', 'internet' => 'Internet', 'parking' => 'Parking', 'fuel' => 'Fuel', 'first_aid' => 'First aid'];
}

/** Facility types: the admin-editable "Facility Types" list (Settings > Lookups), with built-in defaults. */
function cb_facility_types(): array
{
    $types = [];
    try {
        $st = db()->prepare("SELECT item_value FROM lookup_list_items WHERE list_category = 'Facility Types' AND is_active = 1 ORDER BY item_value");
        $st->execute();
        $types = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { $types = []; }
    return $types ?: ['Base', 'Camp', 'ECC', 'ICP', 'Medical Aid Station', 'Reception Centre', 'Staging Area', 'Warming Centre', 'Other'];
}

function cb_facility_statuses(): array
{
    return ['draft' => 'Planned', 'active' => 'Open', 'closed' => 'Closed', 'archived' => 'Archived'];
}

function current_user_facility_permissions(?int $incidentId = null): array
{
    $user = current_user();
    if (!$user) { return []; }
    if (!empty($user['is_sysadmin'])) { return ['can_view' => 1, 'can_create' => 1, 'can_edit' => 1]; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    return $incidentId > 0 ? get_current_user_form_permissions('FACILITIES', (int)$incidentId) : [];
}

function current_user_can_facilities_view(?int $incidentId = null): bool
{
    $p = current_user_facility_permissions($incidentId);
    return !empty($p['can_view']) || !empty($p['can_edit']) || !empty($p['can_create']);
}

function current_user_can_facilities_manage(?int $incidentId = null): bool
{
    $p = current_user_facility_permissions($incidentId);
    return !empty($p['can_edit']) || !empty($p['can_create']);
}

/** Facilities of an incident. $open: only open (active) ones. $type: only that type (case-insensitive). */
function cb_facilities(int $incidentId, bool $open = false, string $type = ''): array
{
    if ($incidentId <= 0) { return []; }
    $sql = 'SELECT * FROM external_checkin_locations WHERE incident_id = ?';
    $args = [$incidentId];
    if ($open) { $sql .= ' AND lifecycle_status = "active"'; }
    if ($type !== '') { $sql .= ' AND LOWER(location_type) = LOWER(?)'; $args[] = $type; }
    $st = db()->prepare($sql . ' ORDER BY FIELD(lifecycle_status, "active", "draft", "closed", "archived"), location_type, location_name');
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function cb_facility(int $incidentId, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM external_checkin_locations WHERE id = ? AND incident_id = ?');
    $st->execute([$id, $incidentId]);
    return $st->fetch() ?: null;
}

/** "Name (Type) - address" for a facility. */
function cb_facility_label(array $f): string
{
    $addr = trim((string)($f['street_address'] ?? ''));
    return (string)$f['location_name'] . ' (' . (string)$f['location_type'] . ')' . ($addr !== '' ? ' - ' . $addr : '');
}

/**
 * A <datalist id="cb-facilities"> of the open facilities, for location fields on forms (ICS 204, 215, 230):
 * people can pick a facility or still type anything. Empty when there are none or the user can't see them.
 */
function cb_facility_datalist_html(int $incidentId): string
{
    static $done = false;
    if ($done || $incidentId <= 0) { return ''; }
    $done = true;
    try { $list = cb_facilities($incidentId, true); } catch (Throwable $e) { return ''; }
    if (!$list) { return ''; }
    $out = '<datalist id="cb-facilities">';
    foreach ($list as $f) {
        $addr = trim((string)($f['street_address'] ?? ''));
        $out .= '<option value="' . e((string)$f['location_name'] . ($addr !== '' ? ', ' . $addr : '')) . '">' . e((string)$f['location_type']) . '</option>';
    }
    return $out . '</datalist>';
}

/** Public check-in page link for a check-in point (from /etc/openecc/external_checkin_sync.env). */
function cb_facility_public_url(string $locationToken): string
{
    $baseUrl = trim((string)(external_checkin_sync_config()['public_entry_base_url'] ?? ''));
    if ($baseUrl === '') { $baseUrl = trim((string)getenv('EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL')); }
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) { return ''; }
    if (str_contains($baseUrl, '{location_token}')) { return str_replace('{location_token}', rawurlencode($locationToken), $baseUrl); }
    return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'location=' . rawurlencode($locationToken);
}

/**
 * Queues the facility for the Check-In Gateway (after the change is saved). Returns a warning to show, or null.
 * Sync problems never undo the saved change.
 */
function cb_facility_queue_sync(int $locationId): ?string
{
    try {
        external_checkin_enqueue_location_snapshot($locationId);
        $st = db()->prepare('SELECT incident_id FROM external_checkin_locations WHERE id = ? LIMIT 1');
        $st->execute([$locationId]);
        $incidentId = (int)$st->fetchColumn();
        if ($incidentId > 0) { external_checkin_enqueue_incident_companion_users($incidentId); }
        return null;
    } catch (Throwable $e) {
        error_log('OpenECC: facility ' . $locationId . ' could not be queued for check-in sync.');
        return ' It was saved, but could not be queued for the Check-In Gateway.';
    }
}

function cb_facility_event(int $incidentId, string $type, array $data): void
{
    try {
        db()->prepare('INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by) VALUES (?,?,?,?)')
            ->execute([$incidentId, $type, json_encode($data, JSON_UNESCAPED_SLASHES), (int)(current_user()['id'] ?? 0) ?: null]);
    } catch (Throwable $e) { /* the audit log has it too */ }
}
