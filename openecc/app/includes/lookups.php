<?php
declare(strict_types=1);

/**
 * Lookup lists (Settings > Lookups, table lookup_list_items): local values that forms offer as suggestions.
 * People can still type anything; changing a list never changes forms already filled in.
 *
 * Starter lists (includes/lookup_starters/<province>.php) are optional, province-specific values a SysAdmin can load
 * from the Lookups page. They are not in seed.sql because OpenECC is used across Canada.
 */

/** The lists, and where each is offered. Order = the Lookups page order. */
function cb_lookup_categories(): array
{
    return [
        'Agencies' => 'Suggested in ICS 211 "Home Unit / Agency", position assignments (Agency), and the ICS 213RR / Resources "Supplier" boxes.',
        'Jurisdictions' => 'Suggested in ICS 209 block 20 (Incident Jurisdiction) and 21 (Incident Location Ownership).',
        'Incident Types' => 'Suggested in "Incident Type" when setting up an incident.',
        'Facility Types' => 'The types offered on the Facilities page.',
        'Contact Categories' => 'For grouping contacts (planned for the Liaison agency register).',
    ];
}

/** Lists that used to be on the Lookups page but are now kept elsewhere. Their old values are shown, not offered. */
function cb_lookup_retired_categories(): array
{
    return [
        'Facilities' => 'Facilities are now kept on the Facilities page.',
        'Resource Kinds' => 'Resource kinds are kept in the Resource Catalogue.',
        'Resource Types' => 'Resource types are kept in the Resource Catalogue.',
        'Positions' => 'Positions are kept in the Role Catalogue.',
    ];
}

/** Lists that keep contact details (organization contacts: no named individuals' personal numbers). */
function cb_lookup_contact_categories(): array
{
    return ['Agencies', 'Jurisdictions'];
}

/** Rows of a list (with contact details), sorted. */
function cb_lookup_items(string $category, bool $activeOnly = true): array
{
    try {
        $st = db()->prepare('SELECT * FROM lookup_list_items WHERE list_category = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY item_value');
        $st->execute([$category]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** Contact details not checked in the last 12 months (or never). */
function cb_lookup_contact_stale(array $row): bool
{
    $v = (string)($row['verified_at'] ?? '');
    return $v === '' || strtotime($v) < strtotime('-12 months');
}

/** "24/7: 780-555-0100 · 780-555-0199" for a suggestion's label; '' when there are no numbers. */
function cb_lookup_phone_summary(array $row): string
{
    $parts = [];
    if (trim((string)($row['phone_24h'] ?? '')) !== '') { $parts[] = '24/7: ' . trim((string)$row['phone_24h']); }
    if (trim((string)($row['phone'] ?? '')) !== '') { $parts[] = trim((string)$row['phone']); }
    return implode(' · ', $parts);
}

/** Active values of a list, sorted. */
function cb_lookup_values(string $category): array
{
    static $cache = [];
    if (!isset($cache[$category])) {
        try {
            $st = db()->prepare('SELECT item_value FROM lookup_list_items WHERE list_category = ? AND is_active = 1 ORDER BY item_value');
            $st->execute([$category]);
            $cache[$category] = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { $cache[$category] = []; }
    }
    return $cache[$category];
}

/** id for a list's <datalist> (e.g. "cb-lookup-agencies"). */
function cb_lookup_datalist_id(string $category): string
{
    return 'cb-lookup-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($category)), '-');
}

/**
 * A <datalist> of a list's values, once per page; use list="<?= cb_lookup_datalist_id(...) ?>" on the input.
 * Empty when the list has no values (the input then works as a plain text box).
 */
function cb_lookup_datalist_html(string $category): string
{
    static $done = [];
    if (isset($done[$category])) { return ''; }
    $done[$category] = true;
    $rows = cb_lookup_items($category);
    if (!$rows) { return ''; }
    // For agencies and jurisdictions the suggestion shows their phone numbers next to the name.
    $withPhones = in_array($category, cb_lookup_contact_categories(), true);
    $out = '<datalist id="' . e(cb_lookup_datalist_id($category)) . '">';
    foreach ($rows as $r) {
        $label = $withPhones ? cb_lookup_phone_summary($r) : '';
        $out .= '<option value="' . e((string)$r['item_value']) . '">' . e($label) . '</option>';
    }
    return $out . '</datalist>';
}

/** Optional starter lists by province: key => [label, checked (when the names were last checked), file]. */
function cb_lookup_starter_lists(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/lookup_starters/*.php') ?: [] as $file) {
        $data = include $file;
        if (is_array($data) && !empty($data['label']) && !empty($data['lists'])) {
            $out[basename($file, '.php')] = $data;
        }
    }
    ksort($out);
    return $out;
}

/** Adds a starter list's values that aren't there yet (existing values, even disabled ones, are left alone). */
function cb_lookup_load_starter(string $key): int
{
    $lists = cb_lookup_starter_lists();
    if (!isset($lists[$key])) { throw new RuntimeException('Unknown starter list.'); }
    $categories = cb_lookup_categories();
    $st = db()->prepare('INSERT IGNORE INTO lookup_list_items (list_category, item_value, item_description, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
    $added = 0;
    foreach ($lists[$key]['lists'] as $category => $items) {
        if (!isset($categories[$category])) { continue; }
        foreach ($items as $item) {
            [$value, $desc] = array_pad((array)$item, 2, null);
            $value = trim((string)$value);
            if ($value === '') { continue; }
            $st->execute([$category, mb_substr($value, 0, 150), $desc !== null ? mb_substr((string)$desc, 0, 255) : null]);
            $added += $st->rowCount();
        }
    }
    return $added;
}
