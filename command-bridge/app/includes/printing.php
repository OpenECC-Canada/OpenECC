<?php
declare(strict_types=1);

/**
 * Printing forms straight from the server to a network printer.
 *
 * The server's print system (CUPS) does the printer-specific work: printers are added on the server with
 * tools/setup_printer.sh (driverless IPP first, generic PostScript as a fallback), and Command Bridge only hands a PDF
 * to a print queue with `lp`. Queues are listed with `lpstat`. Settings (app_settings):
 *   print_access    - who may print: 'positions' (anyone holding a position in the incident, default),
 *                     'signed_in' (any signed-in user) or 'off'
 *   printer_labels  - JSON {queue: {"label": "...", "enabled": 1}}: friendly name and whether people can pick it
 * The PDF is made exactly like "Download PDF", by running the form's pdf.php as the user (bin/form_snapshot.php), so
 * the same permissions apply.
 */

require_once __DIR__ . '/form_lock.php';   // cb_php_cli_binary()

/** Runs a program (no shell). Returns [exit code, output]. */
function cb_print_run(array $cmd, int $timeout = 30, ?string $cwd = null): array
{
    if (!function_exists('proc_open')) { return [127, 'The server cannot run programs (proc_open is disabled).']; }
    $env = ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) { return [127, 'Could not start ' . basename((string)$cmd[0]) . '.']; }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + $timeout;
    $code = -1;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = (int)$st['exitcode']; break; }
        if (microtime(true) > $deadline) { proc_terminate($proc); $out .= ' (timed out)'; $code = 124; break; }
        usleep(50000);
    }
    $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($proc);
    if ($code === -1) { $code = $closed; }
    return [$code, trim($out)];
}

function cb_print_binary(string $name): string
{
    foreach (['/usr/bin/', '/usr/local/bin/', '/usr/sbin/'] as $dir) {
        if (is_executable($dir . $name)) { return $dir . $name; }
    }
    return '';
}

/** Is the server set up to print (CUPS client installed)? */
function cb_print_installed(): bool
{
    return cb_print_binary('lp') !== '' && cb_print_binary('lpstat') !== '';
}

function cb_print_access(): string
{
    $v = (string)get_app_setting('print_access', 'positions');
    return in_array($v, ['positions', 'signed_in', 'off'], true) ? $v : 'positions';
}

/** @return array<string, array{label: string, enabled: int}> */
function cb_printer_labels(): array
{
    $v = json_decode((string)get_app_setting('printer_labels', '{}'), true);
    return is_array($v) ? $v : [];
}

/**
 * Print queues on the server. Each: queue, label, enabled (people may pick it), state (idle/printing/disabled/unknown),
 * detail (the status line from lpstat).
 *
 * @return array<int, array{queue: string, label: string, enabled: bool, state: string, detail: string}>
 */
function cb_printers(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    if (!cb_print_installed()) { return $cache; }
    [$code, $out] = cb_print_run([cb_print_binary('lpstat'), '-p'], 10);
    if ($code !== 0 && $out === '') { return $cache; }
    $labels = cb_printer_labels();
    foreach (preg_split('/\R/', $out) ?: [] as $line) {
        if (!preg_match('/^printer\s+(\S+)\s+(.*)$/', $line, $m)) { continue; }
        $q = $m[1];
        $rest = strtolower($m[2]);
        $state = str_contains($rest, 'disabled') ? 'disabled' : (str_contains($rest, 'now printing') ? 'printing' : (str_contains($rest, 'idle') ? 'idle' : 'unknown'));
        $cache[] = [
            'queue' => $q,
            'label' => trim((string)($labels[$q]['label'] ?? '')) ?: $q,
            'enabled' => (int)($labels[$q]['enabled'] ?? 1) === 1,
            'state' => $state,
            'detail' => trim($m[2]),
        ];
    }
    return $cache;
}

/** Printers people can pick (enabled in Settings and not disabled on the server). */
function cb_printers_for_users(): array
{
    return array_values(array_filter(cb_printers(), static fn($p) => $p['enabled'] && $p['state'] !== 'disabled'));
}

function cb_print_valid_queue(string $queue): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $queue);
}

/** May the signed-in user print? Returns '' when yes, otherwise the reason. */
function cb_print_denied_reason(int $incidentId): string
{
    $user = current_user();
    if (!$user) { return 'Sign in to print.'; }
    if (function_exists('cb_demo_mode_enabled') && cb_demo_mode_enabled()) { return 'Printing is turned off in the demo.'; }
    if (!cb_print_installed()) { return 'Printing is not set up on this server.'; }
    $access = cb_print_access();
    if ($access === 'off') { return 'Printing is turned off.'; }
    if (!empty($user['is_sysadmin']) || $access === 'signed_in') { return ''; }
    if ($incidentId > 0 && get_user_role_codes($incidentId, (int)$user['id'])) { return ''; }
    return 'Only people holding a position in the incident can print.';
}

/** Show the Print button? (Printing allowed and at least one printer to pick.) */
function cb_print_available(int $incidentId = 0): bool
{
    static $memo = [];
    if ($incidentId <= 0) { $incidentId = (int)(active_incident_id() ?: 0); }
    if (!array_key_exists($incidentId, $memo)) {
        $memo[$incidentId] = cb_print_denied_reason($incidentId) === '' && cb_printers_for_users() !== [];
    }
    return $memo[$incidentId];
}

/**
 * The form PDF a print link points at, checked: [relative script under app/, query array] or null.
 * Accepts the app's own PDF links: /forms/<form>/pdf.php?... and /forms/resources/ics221.php?id=...
 */
function cb_print_parse_pdf_url(string $url): ?array
{
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) { return null; }
    $path = (string)($parts['path'] ?? '');
    $base = rtrim((string)APP_BASE_URL, '/');
    if ($base !== '' && str_starts_with($path, $base . '/')) { $path = substr($path, strlen($base)); }
    if (!preg_match('#^/forms/([a-z0-9_]+)/(pdf|ics221)\.php$#', $path, $m)) { return null; }
    if ($m[2] === 'ics221' && $m[1] !== 'resources') { return null; }
    $rel = 'forms/' . $m[1] . '/' . $m[2] . '.php';
    if (!is_file(dirname(__DIR__) . '/' . $rel)) { return null; }
    parse_str((string)($parts['query'] ?? ''), $query);
    $clean = [];
    foreach ($query as $k => $v) {
        if (!is_string($k) || !is_scalar($v) || in_array($k, ['preview', 'feature'], true)) { continue; }
        if (!preg_match('/^[a-z_]{1,40}$/', $k)) { continue; }
        $clean[$k] = mb_substr((string)$v, 0, 100);
    }
    return [$rel, $clean];
}

/** The link to the print page for a PDF link (the same URL the Download PDF button uses). */
function cb_print_url(string $pdfUrl): string
{
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    return app_url('/forms/print.php') . '?' . http_build_query(['pdf' => $pdfUrl, 'back' => $back]);
}

/** A "Print" button for a PDF link, or '' when printing isn't available. */
function cb_print_button(string $pdfUrl, string $class = 'btn', string $label = 'Print'): string
{
    if ($pdfUrl === '' || !cb_print_available()) { return ''; }
    return '<a class="' . e($class) . '" href="' . e(cb_print_url($pdfUrl)) . '">' . e($label) . '</a>';
}

/** Makes the form's PDF as the signed-in user. Returns [path or null, error or null]. Caller deletes the file. */
function cb_print_make_pdf(string $relScript, array $query): array
{
    $dir = storage_root_path() . '/tmp';
    ensure_directory($dir);
    $out = $dir . '/print_' . bin2hex(random_bytes(8)) . '.pdf';
    $cmd = [cb_php_cli_binary(), dirname(__DIR__) . '/bin/form_snapshot.php', '--user=' . (int)(current_user()['id'] ?? 0),
        '--script=' . $relScript, '--query=' . http_build_query($query), '--out=' . $out];
    [, $output] = cb_print_run($cmd, 120, dirname(__DIR__));
    if (is_file($out) && filesize($out) > 100 && file_get_contents($out, false, null, 0, 5) === '%PDF-') {
        return [$out, null];
    }
    @unlink($out);
    $msg = trim(preg_replace('/\s+/', ' ', strip_tags($output)) ?? '');
    return [null, mb_substr($msg !== '' ? $msg : 'The PDF could not be made.', 0, 250)];
}

/**
 * Sends a PDF to a print queue. Returns [job id or null, error or null]. Pages are fitted to Letter paper, so
 * landscape forms come out the right way round.
 */
function cb_print_send(string $queue, string $pdfPath, int $copies, string $title): array
{
    if (!cb_print_valid_queue($queue)) { return [null, 'Unknown printer.']; }
    $copies = max(1, min(20, $copies));
    $title = mb_substr(preg_replace('/[^\p{L}\p{N} ._()#\/-]+/u', ' ', $title) ?? 'Command Bridge', 0, 80);
    [$code, $out] = cb_print_run([cb_print_binary('lp'), '-d', $queue, '-n', (string)$copies, '-t', $title,
        '-o', 'fit-to-page', '-o', 'media=Letter', $pdfPath], 30);
    if ($code === 0 && preg_match('/request id is (\S+)/', $out, $m)) { return [$m[1], null]; }
    return [null, $out !== '' ? mb_substr($out, 0, 250) : 'The printer did not accept the job.'];
}
