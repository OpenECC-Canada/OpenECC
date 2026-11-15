<?php
declare(strict_types=1);
// Agency directory: the Agencies and Jurisdictions lookup lists with their contact details (includes/lookups.php).
// Organization contacts only. Anyone signed in can read it; SysAdmins edit it in Settings > Lookups.
require_once __DIR__ . '/../../includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/lookups.php';
require_login();

$isAdmin = is_system_administrator();
$sections = [];
foreach (cb_lookup_contact_categories() as $cat) { $sections[$cat] = cb_lookup_items($cat); }
$total = array_sum(array_map('count', $sections));

/** A phone number as a tap-to-call link. */
$tel = static fn(string $n): string => '<a href="tel:' . e((string)preg_replace('/[^0-9+]/', '', $n)) . '">' . e($n) . '</a>';

render_header('Agency Directory');
?>
<div class="card">
  <h2>Agency Directory</h2>
  <p class="small">Contact details for the organizations and jurisdictions you work with. These are organization lines;
    contacts for a specific incident (people on duty) are on the <a href="<?= e(app_url('/forms/ics205a/index.php')) ?>">ICS 205A</a>.
    Details not checked in the last 12 months are marked <span class="priority-high">check</span>.
    <?= $isAdmin ? 'Edit them in <a href="' . e(app_url('/admin/lookups.php')) . '">Settings &gt; Lookups</a>.' : 'Ask your SysAdmin to correct anything out of date.' ?></p>
  <?php if ($total > 0): ?>
    <label for="dir-search" class="small">Search</label>
    <input id="dir-search" type="search" placeholder="Name, phone or note" autocomplete="off">
  <?php endif; ?>
</div>

<?php if ($total === 0): ?>
  <div class="card"><p>No agencies yet.<?= $isAdmin ? ' Add them in Settings &gt; Lookups, or load your province\'s starter list there.' : '' ?></p></div>
<?php endif; ?>

<?php foreach ($sections as $cat => $rows): if (!$rows) { continue; } ?>
<div class="card">
  <h3><?= e($cat) ?> (<?= count($rows) ?>)</h3>
  <table class="data-table cb-cards dir-table">
    <thead><tr><th>Name</th><th>24/7 line</th><th>Main phone</th><th>Email / website</th><th>Notes</th><th>Verified</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $stale = cb_lookup_contact_stale($r); ?>
      <tr>
        <td><strong><?= e((string)$r['item_value']) ?></strong><?= trim((string)$r['item_description']) !== '' ? '<br><span class="small">' . e((string)$r['item_description']) . '</span>' : '' ?></td>
        <td><?= trim((string)$r['phone_24h']) !== '' ? $tel((string)$r['phone_24h']) : '' ?></td>
        <td><?= trim((string)$r['phone']) !== '' ? $tel((string)$r['phone']) : '' ?></td>
        <td><?php if (!empty($r['email'])): ?><a href="mailto:<?= e((string)$r['email']) ?>"><?= e((string)$r['email']) ?></a><?php endif; ?>
          <?php if (!empty($r['website'])): ?><br><a href="<?= e((string)$r['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)preg_replace('#^https?://#', '', (string)$r['website'])) ?></a><?php endif; ?></td>
        <td class="small"><?= nl2br(e((string)($r['notes'] ?? ''))) ?></td>
        <td class="small"><?= !empty($r['verified_at']) ? e((string)$r['verified_at']) : '' ?><?= $stale ? ' <span class="priority-high">check</span>' : '' ?>
          <?php if ($isAdmin): ?><br><a href="<?= e(app_url('/admin/lookups.php?edit=' . (int)$r['id'] . '#edit')) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
<script>
(function () {
  var box = document.getElementById('dir-search');
  if (!box) { return; }
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    document.querySelectorAll('.dir-table tbody tr').forEach(function (tr) {
      tr.style.display = q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  });
}());
</script>
<?php render_footer(); ?>
