<?php
declare(strict_types=1);
// Print a form PDF on a network printer set up on the server (includes/printing.php).
// GET ?pdf=<the form's PDF link>&back=<page to return to> shows the printer choice; POST prints.
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/printing.php';
require_login();

$incidentId = (int)(active_incident_id() ?: 0);
$user = current_user();
$pdfUrl = (string)($_POST['pdf'] ?? $_GET['pdf'] ?? '');
$back = (string)($_POST['back'] ?? $_GET['back'] ?? '');
// Only return to a page of this app.
$base = rtrim((string)APP_BASE_URL, '/');
if ($back === '' || !str_starts_with($back, $base . '/') || str_starts_with($back, '//') || str_contains($back, '\\') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $back)) {
    $back = app_url('/dashboard/index.php');
}
$target = cb_print_parse_pdf_url($pdfUrl);
$denied = cb_print_denied_reason($incidentId);
$printers = $denied === '' ? cb_printers_for_users(true) : [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $denied === '' && $target) {
    $queue = (string)($_POST['printer'] ?? '');
    $copies = (int)($_POST['copies'] ?? 1);
    $ok = in_array($queue, array_column($printers, 'queue'), true);
    if (!$ok) {
        $error = 'Choose a printer.';
    } else {
        [$rel, $query] = $target;
        $title = strtoupper(basename(dirname($rel))) === 'RESOURCES' ? 'ICS 221' : strtoupper(basename(dirname($rel)));
        [$pdf, $err] = cb_print_make_pdf($rel, $query);
        if (!$pdf) {
            $error = 'The PDF could not be made: ' . $err;
        } else {
            [$job, $err] = cb_print_send($queue, $pdf, $copies, $title . ' - ' . (string)($user['real_name'] ?? $user['username'] ?? ''));
            @unlink($pdf);
            $label = (string)(array_values(array_filter($printers, static fn($p) => $p['queue'] === $queue))[0]['label'] ?? $queue);
            if ($job) {
                $_SESSION['cb_last_printer'] = $queue;
                audit_log((int)($user['id'] ?? 0) ?: null, 'print', $title, null,
                    'Printed ' . $title . ' (' . $rel . ($query ? '?' . http_build_query($query) : '') . ') on ' . $label . ', ' . max(1, min(20, $copies)) . ' cop' . ($copies === 1 ? 'y' : 'ies') . ', job ' . $job);
                flash('success', $title . ' sent to ' . $label . ' (job ' . $job . '). Collect it from the printer.');
                header('Location: ' . $back);
                exit;
            }
            audit_log((int)($user['id'] ?? 0) ?: null, 'print_failed', $title, null, 'Printing ' . $title . ' on ' . $label . ' failed: ' . (string)$err);
            $error = 'The printer did not accept it: ' . $err;
        }
    }
}

render_header('Print');
?>
<div class="card">
  <h2>Print</h2>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($denied !== ''): ?>
    <p><?= e($denied) ?></p>
  <?php elseif (!$target): ?>
    <p>That form can't be printed from here. Use <strong>Download PDF</strong> and print it yourself.</p>
  <?php elseif (!$printers): ?>
    <p>No printer is available. Ask your SysAdmin to add one (Settings &gt; Printers).</p>
  <?php else: $last = (string)($_SESSION['cb_last_printer'] ?? ''); ?>
    <p>The form is printed exactly as <strong>Download PDF</strong> makes it.
      <a target="_blank" rel="noopener" href="<?= e(app_url('/' . $target[0]) . '?' . http_build_query($target[1] + ['preview' => '1'])) ?>">Preview it first</a>.</p>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="pdf" value="<?= e($pdfUrl) ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <label>Printer
        <select name="printer" required>
          <?php foreach ($printers as $p): ?>
            <option value="<?= e($p['queue']) ?>"<?= $p['queue'] === $last ? ' selected' : '' ?>><?= e($p['label']) ?><?= $p['state'] === 'printing' ? ' (busy)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Copies<input type="number" name="copies" min="1" max="20" value="1" required></label>
      <p class="form-actions span-2">
        <button type="submit" class="btn btn-primary">Print</button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
      </p>
    </form>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
