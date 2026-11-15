<?php
declare(strict_types=1);
// Lookup lists (includes/lookups.php): local values forms offer as suggestions.
require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/lookups.php';

$categories = cb_lookup_categories();
$retired = cb_lookup_retired_categories();
$starters = cb_lookup_starter_lists();
$uid = (int)(current_user()['id'] ?? 0);

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
            db()->prepare('INSERT INTO lookup_list_items (list_category, item_value, item_description, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())')
                ->execute([$category, $value, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null]);
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
    <p class="form-actions span-2"><button class="btn btn-primary">Add value</button></p>
  </form>
</div>

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
  <table class="cb-cards"><thead><tr><th>Value</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $item): ?>
    <tr><td><?= e((string)$item['item_value']) ?></td><td><?= e((string)$item['item_description']) ?></td><td><?= $item['is_active'] ? 'Active' : 'Disabled' ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
        <button class="btn btn-secondary btn-small"><?= $item['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
