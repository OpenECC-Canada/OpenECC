<?php
declare(strict_types=1);

/**
 * Printing forms straight from the server to a network printer.
 *
 * The server's print system (CUPS) does the printer-specific work: printers are added on the server with
 * tools/setup_printer.sh (driverless IPP first, generic PostScript as a fallback). Command Bridge talks to CUPS
 * directly over its local socket in IPP (the printing protocol) to list the printers and send a PDF: no lp/lpstat,
 * which hung when run from inside Apache. Settings (app_settings):
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
    // The web server's own environment (as bin/form_snapshot.php gets it), with English messages so the status can
    // be read. A cut-down environment made lpstat hang under Apache.
    $env = getenv();
    $env = is_array($env) ? $env : [];
    $env['LANG'] = 'C';
    $env['LC_ALL'] = 'C';
    if (empty($env['PATH'])) { $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'; }
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

/** Where CUPS listens on this server: its local socket, or localhost:631. */
function cb_cups_endpoints(): array
{
    $list = [];
    foreach (['/run/cups/cups.sock', '/var/run/cups/cups.sock'] as $sock) {
        if (file_exists($sock)) { $list[] = 'unix://' . $sock; break; }
    }
    $list[] = 'tcp://127.0.0.1:631';
    return $list;
}

/** Is the server set up to print (CUPS installed)? */
function cb_print_installed(): bool
{
    return cb_print_binary('lpadmin') !== '' || file_exists('/run/cups/cups.sock') || file_exists('/etc/cups/cupsd.conf');
}

// ---- A small IPP client (RFC 8010/8011), enough for CUPS-Get-Printers and Print-Job. ----

function cb_ipp_attr(int $tag, string $name, $value): string
{
    if ($tag === 0x21 || $tag === 0x23) { $v = pack('N', (int)$value); }
    elseif ($tag === 0x22) { $v = $value ? "\x01" : "\x00"; }
    else { $v = (string)$value; }
    return chr($tag) . pack('n', strlen($name)) . $name . pack('n', strlen($v)) . $v;
}

/** An IPP request: operation attributes, optional job attributes, then the document data. */
function cb_ipp_build(int $operation, array $opAttrs, array $jobAttrs = [], string $data = ''): string
{
    $out = "\x01\x01" . pack('n', $operation) . pack('N', random_int(1, 0x7fffffff)) . "\x01";
    foreach ($opAttrs as [$tag, $name, $value]) {
        foreach ((array)$value as $i => $v) { $out .= cb_ipp_attr($tag, $i === 0 ? $name : '', $v); }
    }
    if ($jobAttrs) {
        $out .= "\x02";
        foreach ($jobAttrs as [$tag, $name, $value]) { $out .= cb_ipp_attr($tag, $name, $value); }
    }
    return $out . "\x03" . $data;
}

/**
 * Parses an IPP response. Returns ['status' => int, 'groups' => [[tag, [name => [values]]], ...]].
 */
function cb_ipp_parse(string $b): array
{
    $len = strlen($b);
    if ($len < 9) { return ['status' => -1, 'groups' => []]; }
    $status = unpack('n', substr($b, 2, 2))[1];
    $pos = 8;
    $groups = [];
    $g = -1;
    $last = '';
    while ($pos < $len) {
        $tag = ord($b[$pos]); $pos++;
        if ($tag === 0x03) { break; }
        if ($tag <= 0x0F) { $groups[] = [$tag, []]; $g = count($groups) - 1; continue; }
        if ($pos + 2 > $len) { break; }
        $nl = unpack('n', substr($b, $pos, 2))[1]; $pos += 2;
        $name = substr($b, $pos, $nl); $pos += $nl;
        if ($pos + 2 > $len) { break; }
        $vl = unpack('n', substr($b, $pos, 2))[1]; $pos += 2;
        $raw = substr($b, $pos, $vl); $pos += $vl;
        if ($tag === 0x21 || $tag === 0x23) { $val = $vl === 4 ? unpack('N', $raw)[1] : 0; if ($val >= 0x80000000) { $val -= 0x100000000; } }
        elseif ($tag === 0x22) { $val = $raw !== "\x00"; }
        else { $val = $raw; }
        if ($name === '') { $name = $last; } else { $last = $name; }
        if ($g < 0) { $groups[] = [0x01, []]; $g = 0; }
        $groups[$g][1][$name][] = $val;
    }
    return ['status' => $status, 'groups' => $groups];
}

/** Sends an IPP request to CUPS. Returns [parsed response or null, error or null]. */
function cb_ipp_request(string $path, string $body, int $timeout = 10): array
{
    $err = 'The print system (CUPS) is not answering.';
    foreach (cb_cups_endpoints() as $endpoint) {
        $fp = @stream_socket_client($endpoint, $errno, $errstr, 3);
        if (!$fp) { $err = 'Cannot connect to the print system (' . $endpoint . '): ' . $errstr; continue; }
        stream_set_timeout($fp, $timeout);
        $req = "POST {$path} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/ipp\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n";
        if (@fwrite($fp, $req) === false) { fclose($fp); continue; }
        for ($off = 0, $n = strlen($body); $off < $n; $off += 65536) {
            if (@fwrite($fp, substr($body, $off, 65536)) === false) { break; }
        }
        $resp = '';
        $deadline = microtime(true) + $timeout;
        while (!feof($fp) && microtime(true) < $deadline) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) { break; }
            $resp .= $chunk;
            if (!empty(stream_get_meta_data($fp)['timed_out'])) { break; }
        }
        fclose($fp);
        $split = strpos($resp, "\r\n\r\n");
        if ($split === false) { $err = 'The print system did not answer in time.'; continue; }
        $head = substr($resp, 0, $split);
        $data = substr($resp, $split + 4);
        if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m)) { $err = 'Unexpected answer from the print system.'; continue; }
        if ($m[1] !== '200') { return [null, 'The print system refused the request (HTTP ' . $m[1] . ').']; }
        if (preg_match('/^Transfer-Encoding:\s*chunked/mi', $head)) {
            $plain = '';
            while (($eol = strpos($data, "\r\n")) !== false) {
                $size = hexdec(trim(explode(';', substr($data, 0, $eol))[0]));
                if ($size <= 0) { break; }
                $plain .= substr($data, $eol + 2, (int)$size);
                $data = (string)substr($data, $eol + 2 + (int)$size + 2);
            }
            $data = $plain;
        }
        return [cb_ipp_parse($data), null];
    }
    return [null, $err];
}

function cb_ipp_base_attrs(): array
{
    $user = preg_replace('/[^A-Za-z0-9._-]+/', '', (string)(current_user()['username'] ?? '')) ?: 'commandbridge';
    return [[0x47, 'attributes-charset', 'utf-8'], [0x48, 'attributes-natural-language', 'en'], [0x42, 'requesting-user-name', $user]];
}

/**
 * Print queues on the server. Each: queue, label, enabled (people may pick it), state (idle/printing/disabled/unknown),
 * detail (the printer's status message).
 *
 * @return array<int, array{queue: string, label: string, enabled: bool, state: string, detail: string}>
 */
function cb_printers(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) { return $cache; }
    $cache = [];
    if (!cb_print_installed()) { return $cache; }
    // The Print button checks this on every form page, so the answer is kept for a minute (the Printers and Print
    // pages always ask afresh).
    $file = storage_root_path() . '/tmp/printers_cache.json';
    $rows = null;
    if (!$fresh && is_file($file) && filemtime($file) > time() - 60) {
        $rows = json_decode((string)file_get_contents($file), true);
    }
    if (!is_array($rows)) {
        [$resp] = cb_printers_query();
        if ($resp === null) { return $cache; }
        $rows = $resp;
        try { ensure_directory(dirname($file)); @file_put_contents($file, json_encode($rows), LOCK_EX); } catch (Throwable $e) { /* optional */ }
    }
    $labels = cb_printer_labels();
    foreach ($rows as $r) {
        $q = (string)$r['queue'];
        $cache[] = [
            'queue' => $q,
            'label' => trim((string)($labels[$q]['label'] ?? '')) ?: $q,
            'enabled' => (int)($labels[$q]['enabled'] ?? 1) === 1,
            'state' => (string)$r['state'],
            'detail' => (string)$r['detail'],
        ];
    }
    return $cache;
}

/** Asks CUPS for its printers. Returns [rows or null, error or null]; rows: queue, state, detail. */
function cb_printers_query(): array
{
    $body = cb_ipp_build(0x4002, array_merge(cb_ipp_base_attrs(), [
        [0x44, 'requested-attributes', ['printer-name', 'printer-state', 'printer-state-message', 'printer-is-accepting-jobs', 'device-uri']],
    ]));
    [$resp, $err] = cb_ipp_request('/', $body, 8);
    if ($resp === null) { return [null, $err]; }
    // 0x0406 = no printers at all.
    if ($resp['status'] === 0x0406) { return [[], null]; }
    if ($resp['status'] < 0 || $resp['status'] > 0x00FF) { return [null, sprintf('The print system answered with status 0x%04x.', $resp['status'])]; }
    $rows = [];
    foreach ($resp['groups'] as [$tag, $attrs]) {
        if ($tag !== 0x04 || empty($attrs['printer-name'][0])) { continue; }
        $st = (int)($attrs['printer-state'][0] ?? 0);
        $accepting = (bool)($attrs['printer-is-accepting-jobs'][0] ?? true);
        $state = $st === 5 || !$accepting ? 'disabled' : ($st === 4 ? 'printing' : ($st === 3 ? 'idle' : 'unknown'));
        $msg = trim((string)($attrs['printer-state-message'][0] ?? ''));
        $rows[] = ['queue' => (string)$attrs['printer-name'][0], 'state' => $state,
            'detail' => trim(ucfirst($state) . ($msg !== '' ? ': ' . $msg : '') . (!$accepting ? ' (not accepting jobs)' : ''))];
    }
    return [$rows, null];
}

/** Printers people can pick (enabled in Settings and not disabled on the server). */
function cb_printers_for_users(bool $fresh = false): array
{
    return array_values(array_filter(cb_printers($fresh), static fn($p) => $p['enabled'] && $p['state'] !== 'disabled'));
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
    $pdf = @file_get_contents($pdfPath);
    if ($pdf === false || $pdf === '') { return [null, 'The PDF could not be read.']; }
    $copies = max(1, min(20, $copies));
    $title = mb_substr(preg_replace('/[^\p{L}\p{N} ._()#\/-]+/u', ' ', $title) ?? 'Command Bridge', 0, 80);
    $body = cb_ipp_build(0x0002, array_merge(cb_ipp_base_attrs(), [
        [0x45, 'printer-uri', 'ipp://localhost/printers/' . $queue],
        [0x42, 'job-name', $title],
        [0x49, 'document-format', 'application/pdf'],
    ]), [
        [0x21, 'copies', $copies],
        [0x44, 'media', 'na_letter_8.5x11in'],
        [0x22, 'fit-to-page', true],
    ], $pdf);
    [$resp, $err] = cb_ipp_request('/printers/' . rawurlencode($queue), $body, 60);
    if ($resp === null) { return [null, $err]; }
    if ($resp['status'] > 0x00FF) {
        $msg = '';
        foreach ($resp['groups'] as [, $attrs]) { $msg = $msg ?: (string)($attrs['status-message'][0] ?? ''); }
        return [null, sprintf('The printer did not accept the job (0x%04x)%s', $resp['status'], $msg !== '' ? ': ' . $msg : '.')];
    }
    foreach ($resp['groups'] as [$tag, $attrs]) {
        if (isset($attrs['job-id'][0])) { return [$queue . '-' . (int)$attrs['job-id'][0], null]; }
    }
    return [$queue . '-?', null];
}
