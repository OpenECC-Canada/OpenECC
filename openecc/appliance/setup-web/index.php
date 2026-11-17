<?php
declare(strict_types=1);
/**
 * OpenECC setup mode: the setup page.
 * 1. Asks for the setup code (not needed on the box's own screen).
 * 2. Asks the install questions and hands the answers to the root helper.
 * 3. Shows the install as it runs, then where to find OpenECC.
 */
require __DIR__ . '/lib.php';
setup_start_session();
header('Cache-Control: no-store');

$state   = setup_state();
$errors  = [];
$codeMsg = '';
$hotspot = setup_conf('HOTSPOT_IP', '10.42.0.1');
$tzConf  = setup_conf('TIMEZONE', 'America/Edmonton');
if (!in_array($tzConf, DateTimeZone::listIdentifiers(), true)) { $tzConf = 'America/Edmonton'; }

$in = [
    'timezone' => $tzConf, 'server_name' => $hotspot, 'admin_user' => 'admin', 'admin_name' => '', 'admin_email' => '',
    'admin_password' => '', 'admin_password2' => '', 'wifi_ssid' => setup_conf('WIFI_SSID', 'OpenECC'),
    'wifi_psk' => '', 'wifi_psk2' => '', 'set_epoch' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!setup_check_csrf()) {
        $errors[] = 'The page had expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'code') {
        $codeMsg = setup_try_code((string)($_POST['code'] ?? ''));
        if ($codeMsg === '') { header('Location: ./'); exit; }
    } elseif (($_POST['action'] ?? '') === 'install' && setup_authorized() && in_array($state, ['waiting', 'failed'], true)) {
        foreach (array_keys($in) as $k) { $in[$k] = trim((string)($_POST[$k] ?? '')); }
        $in['admin_password']  = (string)($_POST['admin_password'] ?? '');
        $in['admin_password2'] = (string)($_POST['admin_password2'] ?? '');
        $in['wifi_psk']  = (string)($_POST['wifi_psk'] ?? '');
        $in['wifi_psk2'] = (string)($_POST['wifi_psk2'] ?? '');
        if (empty($_POST['set_clock'])) { $in['set_epoch'] = ''; }
        $errors = setup_validate($in);
        if (!$errors) {
            if (setup_submit($in)) {
                $_SESSION['submitted_at'] = time();
                $_SESSION['admin_user'] = $in['admin_user'];
                header('Location: ./'); exit;
            }
            $errors[] = 'The answers could not be saved on the box (folder /var/lib/openecc-setup). Restart the box and try again.';
        }
        $in['admin_password'] = $in['admin_password2'] = $in['wifi_psk'] = $in['wifi_psk2'] = '';
    }
}

$authorized = setup_authorized();
$justSent   = !empty($_SESSION['submitted_at']) && time() - (int)$_SESSION['submitted_at'] < 120;
$showProgress = $authorized && (in_array($state, ['running', 'installed', 'done'], true) || ($state === 'waiting' && $justSent));
$failed = $authorized && $state === 'failed' && !$errors;
$failLog = $failed ? (string)@file_get_contents(SETUP_STATE . '/progress.log') : '';

$caZones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'CA');
$boxTz = new DateTimeZone($tzConf);
$boxNow = (new DateTimeImmutable('now', $boxTz))->format('D j M Y, H:i');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenECC setup</title>
<link rel="icon" href="openecc-icon.svg" type="image/svg+xml">
<style>
:root { --navy:#12324a; --amber:#f0a43a; --ink:#102a43; --muted:#486581; --line:#d9e2ec; --bg:#f4f6f8; --red:#b42318; --green:#1e7b34; }
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink); line-height:1.5; }
header { background:var(--navy); color:#fff; padding:14px 20px; display:flex; align-items:center; gap:12px; }
header img { width:40px; height:40px; }
header h1 { margin:0; font-size:20px; font-weight:700; }
header span { color:#bcccdc; font-size:14px; }
main { max-width:760px; margin:24px auto; padding:0 16px 48px; }
.card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:20px 22px; margin-bottom:18px; }
.card h2 { margin:0 0 6px; font-size:18px; }
.hint { color:var(--muted); font-size:14px; margin:2px 0 0; }
label { display:block; font-weight:600; margin:14px 0 4px; }
input[type=text], input[type=password], input[type=email], select { width:100%; padding:10px 12px; font-size:16px; border:1px solid #9fb3c8; border-radius:6px; background:#fff; }
input:focus, select:focus { outline:3px solid #f0a43a88; border-color:var(--navy); }
.row { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
@media (max-width:600px) { .row { grid-template-columns:1fr; } }
.check { display:flex; gap:10px; align-items:flex-start; font-weight:400; }
.check input { width:20px; height:20px; margin-top:3px; }
button { background:var(--navy); color:#fff; border:0; border-radius:6px; padding:12px 22px; font-size:16px; font-weight:600; cursor:pointer; min-height:44px; }
button:hover { background:#0b2233; }
.errors { background:#fde8e8; border:1px solid #f5b5b5; color:var(--red); border-radius:8px; padding:12px 16px; margin-bottom:18px; }
.errors ul { margin:6px 0 0 18px; padding:0; }
.ok { background:#e6f4ea; border:1px solid #a8d5b5; color:var(--green); border-radius:8px; padding:14px 16px; }
pre { background:#0b2233; color:#d9e2ec; padding:12px; border-radius:6px; max-height:360px; overflow:auto; font-size:13px; white-space:pre-wrap; word-break:break-word; }
.big { font-size:18px; }
a { color:var(--navy); }
.steps { margin:8px 0 0 18px; padding:0; }
</style>
</head>
<body>
<header>
  <img src="openecc-icon.svg" alt="">
  <div><h1>OpenECC setup</h1><span>Emergency Coordination Centre software</span></div>
</header>
<main>

<?php if ($state === 'done'): ?>
  <div class="card ok big">
    <strong>OpenECC is installed.</strong> Setup mode is off.
    Connect to the box's Wi-Fi with its new name and password and open
    <a href="https://<?= h($hotspot) ?>/">https://<?= h($hotspot) ?>/</a>.
  </div>

<?php elseif (!$authorized): ?>
  <form class="card" method="post" action="./" autocomplete="off">
    <h2>Enter the setup code</h2>
    <p class="hint">The setup code is on the label on the box. It stops anyone else who joins this Wi-Fi from setting up the box.</p>
    <?php if ($codeMsg !== '' || $errors): ?><div class="errors"><?= h($codeMsg !== '' ? $codeMsg : $errors[0]) ?></div><?php endif; ?>
    <label for="code">Setup code</label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="code">
    <p><button type="submit">Continue</button></p>
  </form>

<?php elseif ($showProgress): ?>
  <div class="card">
    <h2 id="p-title">Installing OpenECC…</h2>
    <p class="hint" id="p-hint">This takes a few minutes. Keep this page open.</p>
    <div id="p-done" class="ok" hidden></div>
    <pre id="p-log" aria-live="polite">Starting…</pre>
  </div>
  <script>
  (function () {
    var title = document.getElementById('p-title'), hint = document.getElementById('p-hint');
    var logEl = document.getElementById('p-log'), doneEl = document.getElementById('p-done');
    var adminUser = <?= json_encode((string)($_SESSION['admin_user'] ?? 'admin')) ?>;
    var stopped = false;
    function finish(r) {
      stopped = true;
      title.textContent = 'OpenECC is installed';
      hint.textContent = '';
      var url = (r && r.url) ? r.url : 'https://<?= h($hotspot) ?>/';
      var ssid = (r && r.wifi_ssid) ? r.wifi_ssid : '';
      doneEl.hidden = false;
      doneEl.textContent = '';
      var p1 = document.createElement('p');
      p1.textContent = 'In about 20 seconds this setup page closes and the Wi-Fi changes to "' + ssid + '" with the new password you chose. Your device will disconnect.';
      var p2 = document.createElement('p');
      p2.textContent = 'Then join "' + ssid + '" again with the new password, open ' + url + ' and sign in as ' + adminUser + '. Your browser will warn about the certificate once; that is expected for a box with no internet.';
      var a = document.createElement('a'); a.href = url; a.textContent = 'Open OpenECC (' + url + ')';
      doneEl.appendChild(p1); doneEl.appendChild(p2); doneEl.appendChild(a);
    }
    function poll() {
      if (stopped) { return; }
      fetch('status.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.log) { logEl.textContent = d.log; logEl.scrollTop = logEl.scrollHeight; }
          if (d.state === 'installed' || d.state === 'done') { finish(d.result); return; }
          if (d.state === 'failed') { stopped = true; title.textContent = 'Setup stopped'; hint.textContent = 'Read the last lines above, then reload this page to change your answers and try again.'; return; }
          setTimeout(poll, 2000);
        })
        .catch(function () { setTimeout(poll, 3000); });   // the web server restarts during the install
    }
    poll();
  })();
  </script>

<?php else: ?>
  <?php if ($errors): ?>
    <div class="errors"><strong>Please fix these:</strong><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($failed): ?>
    <div class="errors"><strong>The last attempt stopped.</strong> The end of its log is below. Check your answers and press Install again.
      <pre><?= h(implode("\n", array_slice(explode("\n", $failLog), -25))) ?></pre></div>
  <?php endif; ?>

  <form method="post" action="./" autocomplete="off" id="setup-form">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="install">
    <input type="hidden" name="set_epoch" id="set_epoch" value="">

    <div class="card">
      <h2>1. Time</h2>
      <p class="hint">Incident records depend on the right time.</p>
      <label for="timezone">Time zone</label>
      <select id="timezone" name="timezone">
        <optgroup label="Canada">
        <?php foreach ($caZones as $z): ?><option value="<?= h($z) ?>"<?= $z === $in['timezone'] ? ' selected' : '' ?>><?= h(str_replace('_', ' ', $z)) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Other">
        <?php foreach (DateTimeZone::listIdentifiers() as $z): if (in_array($z, $caZones, true)) { continue; } ?><option value="<?= h($z) ?>"<?= $z === $in['timezone'] ? ' selected' : '' ?>><?= h(str_replace('_', ' ', $z)) ?></option><?php endforeach; ?>
        </optgroup>
      </select>
      <p class="hint">The box's clock says: <strong><?= h($boxNow) ?></strong> (<?= h($tzConf) ?>). This device says: <strong id="dev-time">…</strong></p>
      <label class="check"><input type="checkbox" name="set_clock" id="set_clock" value="1"> <span>Set the box's clock from this device. <span class="hint" id="clock-note"></span></span></label>
    </div>

    <div class="card">
      <h2>2. Administrator</h2>
      <p class="hint">The first OpenECC account. It can add everyone else.</p>
      <div class="row">
        <div><label for="admin_user">Username</label><input type="text" id="admin_user" name="admin_user" value="<?= h($in['admin_user']) ?>" required autocapitalize="none"></div>
        <div><label for="admin_name">Full name</label><input type="text" id="admin_name" name="admin_name" value="<?= h($in['admin_name']) ?>" required></div>
      </div>
      <label for="admin_email">Email (optional)</label>
      <input type="email" id="admin_email" name="admin_email" value="<?= h($in['admin_email']) ?>">
      <div class="row">
        <div><label for="admin_password">Password (12 or more characters)</label><input type="password" id="admin_password" name="admin_password" minlength="12" required autocomplete="new-password"></div>
        <div><label for="admin_password2">Password again</label><input type="password" id="admin_password2" name="admin_password2" minlength="12" required autocomplete="new-password"></div>
      </div>
    </div>

    <div class="card">
      <h2>3. Wi-Fi</h2>
      <p class="hint">Choose the name and a <strong>new</strong> password for this box's Wi-Fi. Everyone in the ECC joins it to use OpenECC. The password on the label stops working.</p>
      <label for="wifi_ssid">Wi-Fi name</label>
      <input type="text" id="wifi_ssid" name="wifi_ssid" value="<?= h($in['wifi_ssid']) ?>" maxlength="32" required>
      <div class="row">
        <div><label for="wifi_psk">New Wi-Fi password (8 or more)</label><input type="password" id="wifi_psk" name="wifi_psk" minlength="8" maxlength="63" required autocomplete="new-password"></div>
        <div><label for="wifi_psk2">New Wi-Fi password again</label><input type="password" id="wifi_psk2" name="wifi_psk2" minlength="8" maxlength="63" required autocomplete="new-password"></div>
      </div>
    </div>

    <div class="card">
      <h2>4. Web address</h2>
      <label for="server_name">The address people type to reach OpenECC</label>
      <input type="text" id="server_name" name="server_name" value="<?= h($in['server_name']) ?>" required autocapitalize="none">
      <p class="hint">Keep <strong><?= h($hotspot) ?></strong> if people use OpenECC over this box's Wi-Fi. If the box is plugged into your own network, use its name or address there.</p>
    </div>

    <div class="card">
      <p><button type="submit">Install OpenECC</button></p>
      <p class="hint">Installing takes a few minutes. Nothing needs the internet.</p>
    </div>
  </form>
  <script>
  (function () {
    var now = new Date(), boxEpoch = <?= time() ?>, devEpoch = Math.floor(now.getTime() / 1000);
    document.getElementById('dev-time').textContent = now.toLocaleString();
    document.getElementById('set_epoch').value = String(devEpoch);
    var diff = Math.abs(devEpoch - boxEpoch), box = document.getElementById('set_clock');
    if (diff > 120) { box.checked = true; document.getElementById('clock-note').textContent = '(they differ by ' + Math.round(diff / 60) + ' minutes)'; }
    else { document.getElementById('clock-note').textContent = '(they agree, so this isn\'t needed)'; }
    document.getElementById('setup-form').addEventListener('submit', function () {
      document.getElementById('set_epoch').value = String(Math.floor(Date.now() / 1000));
    });
  })();
  </script>
<?php endif; ?>

</main>
</body>
</html>
