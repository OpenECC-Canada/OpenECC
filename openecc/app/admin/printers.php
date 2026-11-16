<?php
declare(strict_types=1);
/*
 * Printers (includes/printing.php). The printers themselves are added on the server with tools/setup_printer.sh;
 * this page names them, chooses which ones people can pick, sets who may print and sends a test page.
 */
require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/printing.php';
require_once dirname(__DIR__) . '/includes/pdf/SimpleTextPdf.php';
require_csrf_for_post();

$uid = (int)(current_user()['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $access = (string)($_POST['print_access'] ?? 'positions');
            if (!in_array($access, ['positions', 'signed_in', 'off'], true)) { $access = 'positions'; }
            $labels = [];
            foreach (cb_printers(true) as $p) {
                $q = $p['queue'];
                $labels[$q] = [
                    'label' => mb_substr(trim((string)($_POST['label'][$q] ?? '')), 0, 80),
                    'enabled' => !empty($_POST['enabled'][$q]) ? 1 : 0,
                ];
            }
            save_app_setting('print_access', $access);
            save_app_setting('printer_labels', json_encode($labels, JSON_UNESCAPED_UNICODE));
            audit_log($uid, 'printer_settings', 'app_settings', null, 'Printers saved: access ' . $access . '; '
                . mb_substr(implode(', ', array_map(static fn($q, $l) => $q . ($l['label'] !== '' ? ' "' . $l['label'] . '"' : '') . ($l['enabled'] ? '' : ' (hidden)'),
                    array_keys($labels), $labels)), 0, 1000));
            flash('success', 'Printer settings saved.');
        } elseif ($action === 'test') {
            $queue = (string)($_POST['queue'] ?? '');
            if (!in_array($queue, array_column(cb_printers(true), 'queue'), true)) { throw new RuntimeException('Unknown printer.'); }
            $file = tempnam(sys_get_temp_dir(), 'cbtest_');
            if ($file === false) { throw new RuntimeException('Could not make the test page.'); }
            file_put_contents($file, SimpleTextPdf::render('OpenECC test page', ['Printer: ' . $queue, 'Sent: ' . date('Y-m-d H:i')],
                [['heading' => 'It works', 'text' => 'This page was sent from the OpenECC server. If you can read it, people can print forms on this printer.']]));
            [$job, $err] = cb_print_send($queue, $file, 1, 'OpenECC test page');
            @unlink($file);
            audit_log($uid, $job ? 'print' : 'print_failed', 'printer_test', null, 'Test page on ' . $queue . ($job ? ', job ' . $job : ': ' . $err));
            if ($job) { flash('success', 'Test page sent to ' . $queue . ' (job ' . $job . ').'); }
            else { flash('error', 'The test page was not accepted: ' . $err); }
        }
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The change could not be saved.');
    }
    header('Location: ' . app_url('/admin/printers.php'));
    exit;
}

$installed = cb_print_installed();
$printers = cb_printers(true);
$access = cb_print_access();
render_header('Printers');
?>
<div class="card">
  <h2>Printers</h2>
  <p>People can send a form straight to a printer on your network with the <strong>Print</strong> button next to
    <strong>Download PDF</strong>. Nothing has to be installed on their computers. The printers are added on the
    server (see below); here you give them a friendly name, choose which ones people can pick and send a test page.</p>
  <?php if (!$installed): ?>
    <div class="flash warning">Printing is not set up on this server yet. Add a printer with <code>tools/setup_printer.sh</code> (below).</div>
  <?php endif; ?>
</div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <h3>Who can print</h3>
  <label><select name="print_access">
    <option value="positions"<?= $access === 'positions' ? ' selected' : '' ?>>Anyone holding a position in the incident</option>
    <option value="signed_in"<?= $access === 'signed_in' ? ' selected' : '' ?>>Any signed-in user</option>
    <option value="off"<?= $access === 'off' ? ' selected' : '' ?>>Nobody (printing turned off)</option>
  </select></label>
  <p class="small">People can only print forms they can open. SysAdmins can always print.</p>

  <h3>Printers on this server</h3>
  <?php if (!$printers): ?>
    <p><?= $installed ? 'No printers yet.' : 'None.' ?> Add one with <code>tools/setup_printer.sh</code>.</p>
  <?php else: ?>
    <table class="data-table cb-cards">
      <thead><tr><th>Printer (server name)</th><th>Name people see</th><th>People can pick it</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($printers as $p): $q = $p['queue']; $labels = cb_printer_labels(); ?>
        <tr>
          <td><code><?= e($q) ?></code></td>
          <td><input name="label[<?= e($q) ?>]" maxlength="80" value="<?= e((string)($labels[$q]['label'] ?? '')) ?>" placeholder="e.g. ECC copier, 2nd floor"></td>
          <td><label class="checkbox-line"><input type="checkbox" name="enabled[<?= e($q) ?>]" value="1"<?= $p['enabled'] ? ' checked' : '' ?>> Yes</label></td>
          <td><?= e(ucfirst($p['state'])) ?><br><span class="small"><?= e($p['detail']) ?></span></td>
          <td><button type="submit" form="printer-test-<?= e($q) ?>" class="btn btn-secondary btn-small">Test page</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="form-actions"><button type="submit" class="btn btn-primary">Save</button></p>
</form>
<?php foreach ($printers as $p): ?>
  <form method="post" id="printer-test-<?= e($p['queue']) ?>" style="display:none"><?= csrf_field() ?>
    <input type="hidden" name="action" value="test"><input type="hidden" name="queue" value="<?= e($p['queue']) ?>"></form>
<?php endforeach; ?>

<div class="card">
  <details<?= ($installed && !$printers) ? ' open' : '' ?>>
    <summary><strong>Server check</strong> (what OpenECC sees)</summary>
    <?php
      $t0 = microtime(true);
      [$diagRows, $diagErr] = cb_printers_query();
      $diagSecs = microtime(true) - $t0;
      $who = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
    ?>
    <table class="data-table">
      <tr><th>Web server user</th><td><?= e($who !== '' ? $who : 'unknown') ?></td></tr>
      <tr><th>Print system (CUPS)</th><td><?= e(cb_print_installed() ? 'installed' : 'not found') ?>; connects to <?= e(implode(' or ', cb_cups_endpoints())) ?></td></tr>
      <tr><th>Asking it for printers (<?= e(number_format($diagSecs, 1)) ?> s)</th><td><?php if ($diagRows === null): ?><span class="priority-high"><?= e((string)$diagErr) ?></span>
        <?php elseif (!$diagRows): ?>Answered: no printers.
        <?php else: ?>Answered: <?= e(implode('; ', array_map(static fn($r) => $r['queue'] . ' (' . $r['detail'] . ')', $diagRows))) ?><?php endif; ?></td></tr>
    </table>
  </details>
</div>

<div class="card">
  <h3>Adding a printer</h3>
  <p>On the OpenECC server, with the printer's IP address (from its network settings page or a configuration printout), run:</p>
  <pre>sudo <?= e(dirname(__DIR__, 2)) ?>/tools/setup_printer.sh</pre>
  <p class="small">It checks for the print system and offers to install it, then a menu lets you add, list, test or
    remove printers. It connects driverless first (most office printers and copiers from the last ten years) and falls
    back to generic PostScript. Then come back here, give the printer a name and press <strong>Save</strong>.</p>
</div>
<?php render_footer(); ?>
