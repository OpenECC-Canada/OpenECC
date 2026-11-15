<?php
declare(strict_types=1);
// Lookup lists (includes/lookups.php): local values forms offer as suggestions.
require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/lookups.php';

$categories = cb_lookup_categories();
$retired = cb_lookup_retired_categories();
$starters = cb_lookup_starter_lists();
$uid = (int)(current_user()['id'] ?? 0);
$contactCats = cb_lookup_contact_categories();

/** Contact fields from the form (organization contacts only). */
function lookups_contact_fields(): array
{
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('"' . $email . '" is not an email address.'); }
    $web = trim((string)($_POST['website'] ?? ''));
    if ($web !== '' && !preg_match('#^https?://#i', $web)) { $web = 'https://' . $web; }
    if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) { throw new RuntimeException('"' . $web . '" is not a web address.'); }
    return [
        'phone' => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 50) ?: null,
        'phone_24h' => mb_substr(trim((string)($_POST['phone_24h'] ?? '')), 0, 50) ?: null,
        'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
        'website' => $web !== '' ? mb_substr($web, 0, 255) : null,
        'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $category = trim((string)($_POST['category'] ?? ''));
            $value = mb_substr(trim((string)($_POST['value'] ?? '')), 0, 150);
            if (!isset($categories[$category]) || $value === '') { throw new RuntimeException('Choose a list and enter a value.'); }
            $dup = db()->prepare('SELECT id, is_active FROM lookup_list_items WHERE list_category = ? AND item_value = ?');
            $dup->execute([$category, $value]);
            if ($row = $dup->fetch()) {
                throw new RuntimeException('"' . $value . '" is already in ' . $category . ($row['is_active'] ? '.' : ' (disabled). Enable it instead.'));
            }
            $c = in_array($category, $contactCats, true) ? lookups_contact_fields() : ['phone' => null, 'phone_24h' => null, 'email' => null, 'website' => null, 'notes' => null];
            $verified = in_array($category, $contactCats, true) && !empty($_POST['verified']);
            db()->prepare('INSERT INTO lookup_list_items (list_category, item_value, item_description, phone, phone_24h, email, website, notes, verified_at, verified_by_user_id, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())')
                ->execute(array_merge([$category, $value, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null], array_values($c),
                    [$verified ? date('Y-m-d') : null, $verified ? $uid : null]));
            audit_log($uid, 'lookup_added', 'lookup_list', (int)db()->lastInsertId(), $category . ': ' . $value);
            flash('success', 'Added "' . $value . '" to ' . $category . '.');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT list_category, item_value, is_active FROM lookup_list_items WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) { throw new RuntimeException('That value could not be found.'); }
            db()->prepare('UPDATE lookup_list_items SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = ?')->execute([$id]);
            audit_log($uid, 'lookup_status_changed', 'lookup_list', $id, $row['list_category'] . ': ' . $row['item_value'] . ($row['is_active'] ? ' disabled' : ' enabled'));
            flash('success', '"' . $row['item_value'] . '" ' . ($row['is_active'] ? 'disabled.' : 'enabled.'));
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT * FROM lookup_list_items WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) { throw new RuntimeException('That value could not be found.'); }
            $isContact = in_array((string)$row['list_category'], $contactCats, true);
            $c = $isContact ? lookups_contact_fields() : [];
            $set = ['item_description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null] + $c;
            $sql = implode(', ', array_map(static fn($k) => $k . ' = ?', array_keys($set)));
            $args = array_values($set);
            if ($isContact && !empty($_POST['verified'])) { $sql .= ', verified_at = CURDATE(), verified_by_user_id = ?'; $args[] = $uid; }
            $args[] = $id;
            db()->prepare("UPDATE lookup_list_items SET {$sql}, updated_at = NOW() WHERE id = ?")->execute($args);
            audit_log($uid, 'lookup_changed', 'lookup_list', $id, $row['list_category'] . ': ' . $row['item_value'] . ' details changed'
                . ($isContact && !empty($_POST['verified']) ? ' (contacts verified)' : ''));
            flash('success', '"' . $row['item_value'] . '" saved.');
        } elseif ($action === 'starter') {
            $key = (string)($_POST['starter'] ?? '');
            $added = cb_lookup_load_starter($key);
            audit_log($uid, 'lookup_added', 'lookup_list', null, 'Loaded the ' . ($starters[$key]['label'] ?? $key) . ' starter list: ' . $added . ' value(s) added');
            flash('success', $added > 0 ? 'Added ' . $added . ' value' . ($added === 1 ? '' : 's') . ' from the ' . $starters[$key]['label'] . ' starter list. Review them below.'
                : 'Every value in the ' . $starters[$key]['label'] . ' starter list is already here.');
        }
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The change could not be saved.');
    }
    header('Location: ' . app_url('/admin/lookups.php'));
    exit;
}

$items = [];
foreach (db()->query('SELECT * FROM lookup_list_items ORDER BY list_category, item_value')->fetchAll() as $item) {
    $items[(string)$item['list_category']][] = $item;
}
/** Contact inputs for the add and edit forms. */
function lookups_contact_inputs(array $r): void
{
    ?>
    <p class="small">Organization contacts only: main lines, 24/7 lines and general mailboxes. Don't put a named person's
      own cell number here; it's personal information and belongs in the incident's ICS 205A.</p>
    <div class="form-grid">
      <label>Main phone<input name="phone" maxlength="50" value="<?= e((string)($r['phone'] ?? '')) ?>"></label>
      <label>24/7 emergency line<input name="phone_24h" maxlength="50" value="<?= e((string)($r['phone_24h'] ?? '')) ?>"></label>
      <label>Email<input type="email" name="email" maxlength="190" value="<?= e((string)($r['email'] ?? '')) ?>"></label>
      <label>Website<input name="website" maxlength="255" value="<?= e((string)($r['website'] ?? '')) ?>" placeholder="example.ca"></label>
      <label class="span-2">Notes<textarea name="notes" rows="2" placeholder="e.g. ask for the duty officer; after hours use the 24/7 line"><?= e((string)($r['notes'] ?? '')) ?></textarea></label>
      <label class="checkbox-line span-2"><input type="checkbox" name="verified" value="1"> I checked these contact details today</label>
    </div>
    <?php
}

$editRow = null;
if (($eid = (int)($_GET['edit'] ?? 0)) > 0) {
    $st = db()->prepare('SELECT * FROM lookup_list_items WHERE id = ?');
    $st->execute([$eid]);
    $editRow = $st->fetch() ?: null;
}

render_header('Lookup Lists'); settings_menu('lookups');
?>
<div class="card">
  <h2>Lookup Lists</h2>
  <p>Local values that forms suggest as you type. People can still type something else, and changing a list never
    changes forms already filled in. Disable a value to stop suggesting it.</p>
  <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>List<select name="category"><?php foreach ($categories as $c => $_): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
    <label>Value<input name="value" maxlength="150" required></label>
    <label class="span-2">Description (optional)<input name="description" maxlength="255"></label>
    <details class="span-2"><summary>Contact details (Agencies and Jurisdictions only)</summary><?php lookups_contact_inputs([]); ?></details>
    <p class="form-actions span-2"><button class="btn btn-primary">Add value</button></p>
  </form>
</div>

<?php if ($editRow): ?>
<div class="card" id="edit">
  <h3>Change "<?= e((string)$editRow['item_value']) ?>" (<?= e((string)$editRow['list_category']) ?>)</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
    <label>Description<input name="description" maxlength="255" value="<?= e((string)($editRow['item_description'] ?? '')) ?>"></label>
    <?php if (in_array((string)$editRow['list_category'], $contactCats, true)) { lookups_contact_inputs($editRow); } ?>
    <?php if (!empty($editRow['verified_at'])): ?><p class="small">Last verified <?= e((string)$editRow['verified_at']) ?>.</p><?php endif; ?>
    <p class="form-actions"><button class="btn btn-primary">Save</button> <a class="btn" href="<?= e(app_url('/admin/lookups.php')) ?>">Cancel</a></p>
  </form>
</div>
<?php endif; ?>

<?php if ($starters): ?>
<div class="card">
  <h3>Starter lists</h3>
  <p class="small">Common organizations for your province, to start the <strong>Agencies</strong> list. Loading one only
    adds values that aren't there yet; nothing you've added or disabled is changed. Review the result: names change
    over time.</p>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="starter">
    <label>Province<select name="starter"><?php foreach ($starters as $k => $s): ?><option value="<?= e($k) ?>"><?= e($s['label']) ?> (<?= count($s['lists']['Agencies'] ?? []) ?> agencies, checked <?= e((string)($s['checked'] ?? '')) ?>)</option><?php endforeach; ?></select></label>
    <button class="btn btn-secondary" onclick="return confirm('Add this starter list to your lookup lists?');">Load starter list</button>
  </form>
</div>
<?php endif; ?>

<?php foreach ($categories + $retired as $category => $about): $list = $items[$category] ?? []; $isRetired = isset($retired[$category]);
    if ($isRetired && !$list) { continue; } ?>
<div class="card">
  <h3><?= e($category) ?> <span class="small">(<?= count(array_filter($list, static fn($i) => (int)$i['is_active'] === 1)) ?> active)</span><?= $isRetired ? ' <span class="small">- no longer used</span>' : '' ?></h3>
  <p class="small"><?= e($about) ?></p>
  <?php if (!$list): ?>
    <p class="muted">No values yet.</p>
  <?php else: ?>
  <?php $hasContacts = in_array($category, $contactCats, true); ?>
  <table class="cb-cards"><thead><tr><th>Value</th><th>Description</th><?php if ($hasContacts): ?><th>Contact</th><th>Verified</th><?php endif; ?><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $item): ?>
    <tr><td><?= e((string)$item['item_value']) ?></td><td><?= e((string)$item['item_description']) ?></td>
      <?php if ($hasContacts): ?>
        <td class="small"><?= e(cb_lookup_phone_summary($item)) ?><?= !empty($item['email']) ? '<br>' . e((string)$item['email']) : '' ?></td>
        <td class="small"><?= !empty($item['verified_at']) ? e((string)$item['verified_at']) : '<span class="priority-high">never</span>' ?></td>
      <?php endif; ?>
      <td><?= $item['is_active'] ? 'Active' : 'Disabled' ?></td>
      <td><?php if (!$isRetired): ?><a class="btn btn-small" href="<?= e(app_url('/admin/lookups.php?edit=' . (int)$item['id'] . '#edit')) ?>">Edit</a><?php endif; ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
        <button class="btn btn-secondary btn-small"><?= $item['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
