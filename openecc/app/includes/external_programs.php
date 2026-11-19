<?php
declare(strict_types=1);

/**
 * Finding and running helper programs (pdftocairo, pdfinfo, Ghostscript, PHP) on Linux and Windows.
 * Programs are run without a shell (proc_open with an argument list), so file names never need shell quoting.
 *
 * Extra folders to search can be given in the OPENECC_TOOLS_DIR setting (Apache SetEnv or the environment),
 * separated like PATH (":" on Linux, ";" on Windows). The Windows installer points it at its bundled tools.
 */

function cb_is_windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

/** Full path of a program, or '' if it is not found. 'gs' also finds gswin64c/gswin32c on Windows. */
function cb_find_program(string $name): string
{
    static $cache = [];
    if (isset($cache[$name])) { return $cache[$name]; }

    $names = [$name];
    if (cb_is_windows()) {
        $names = $name === 'gs' ? ['gswin64c.exe', 'gswin32c.exe', 'gs.exe'] : [$name . '.exe', $name];
    }
    $dirs = [];
    foreach (['OPENECC_TOOLS_DIR', 'PATH'] as $var) {
        $value = getenv($var);
        if (!is_string($value) || $value === '') { $value = (string)($_SERVER[$var] ?? ''); }
        foreach (explode(PATH_SEPARATOR, $value) as $d) {
            $d = trim($d, " \t\"");
            if ($d !== '') { $dirs[] = rtrim($d, '/\\'); }
        }
    }
    if (!cb_is_windows()) {
        // Apache's environment can have a short PATH.
        array_push($dirs, '/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/usr/sbin');
    }
    foreach (array_unique($dirs) as $d) {
        foreach ($names as $n) {
            $path = $d . DIRECTORY_SEPARATOR . $n;
            if (@is_file($path) && (cb_is_windows() || @is_executable($path))) { return $cache[$name] = $path; }
        }
    }
    return $cache[$name] = '';
}

/** True when PHP may start other programs (proc_open not disabled). */
function cb_can_run_programs(): bool
{
    if (!function_exists('proc_open')) { return false; }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

/**
 * Runs a program (first element: a full path from cb_find_program()) with arguments and no shell.
 * Error output is discarded. Returns [exit code, standard output lines]; exit code 127 if it could not start.
 */
function cb_run_program(array $argv): array
{
    if (!$argv || !cb_can_run_programs()) { return [127, []]; }
    $null = cb_is_windows() ? 'NUL' : '/dev/null';
    $proc = @proc_open(array_map('strval', $argv), [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
    if (!is_resource($proc)) { return [127, []]; }
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($proc);
    $lines = $out === '' ? [] : preg_split('/\r\n|\n|\r/', rtrim($out, "\r\n"));
    return [$code, $lines ?: []];
}
