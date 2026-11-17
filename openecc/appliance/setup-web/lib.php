<?php
declare(strict_types=1);
/**
 * OpenECC setup mode: shared helpers for the setup page.
 * This page runs as www-data and never runs anything as root. It writes the answers to
 * /var/lib/openecc-setup/request.json; the root helper (openecc-setup-run) does the install.
 */

const SETUP_CONF  = '/etc/openecc-setup/setup.conf';
const SETUP_STATE = '/var/lib/openecc-setup';
const MAX_TRIES   = 5;      // wrong setup codes before a pause
const LOCK_SECS   = 300;

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function setup_conf(string $key, string $default = ''): string
{
    static $conf = null;
    if ($conf === null) {
        $conf = [];
        foreach (@file(SETUP_CONF, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
            [$k, $v] = explode('=', $line, 2);
            $conf[trim($k)] = $v;
        }
    }
    return $conf[$key] ?? $default;
}

function setup_state(): string
{
    $s = trim((string)@file_get_contents(SETUP_STATE . '/state'));
    return in_array($s, ['waiting', 'running', 'failed', 'installed', 'done'], true) ? $s : 'waiting';
}

function setup_start_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('OPENECCSETUP');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}

/** On the box itself (the desktop browser) no setup code is needed: whoever is there has the box. */
function setup_is_local(): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '::1' || str_starts_with($ip, '127.');
}

function setup_authorized(): bool
{
    return setup_is_local() || !empty($_SESSION['setup_ok']);
}

function setup_check_csrf(): bool
{
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals((string)$_SESSION['csrf'], $t);
}

/** Returns '' when the code is right, otherwise a message. Wrong codes are counted for everyone. */
function setup_try_code(string $code): string
{
    $file = SETUP_STATE . '/attempts.json';
    $a = json_decode((string)@file_get_contents($file), true);
    $a = is_array($a) ? $a : ['count' => 0, 'until' => 0];
    if ((int)$a['until'] > time()) {
        return 'Too many wrong codes. Wait ' . (int)ceil(((int)$a['until'] - time()) / 60) . ' minute(s) and try again.';
    }
    $hash = setup_conf('SETUP_CODE_HASH');
    if ($hash !== '' && password_verify(trim($code), $hash)) {
        @unlink($file);
        session_regenerate_id(true);
        $_SESSION['setup_ok'] = true;
        return '';
    }
    usleep(800000);
    $a['count'] = (int)$a['count'] + 1;
    if ($a['count'] >= MAX_TRIES) { $a = ['count' => 0, 'until' => time() + LOCK_SECS]; }
    @file_put_contents($file, json_encode($a), LOCK_EX);
    return 'That setup code is not right. It is on the label on the box.';
}

/** Checks the answers. Returns a list of problems (empty when all is well). The root helper checks again. */
function setup_validate(array $in): array
{
    $err = [];
    if (!in_array($in['timezone'], DateTimeZone::listIdentifiers(), true)) { $err[] = 'Choose a time zone.'; }
    if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $in['server_name'])) { $err[] = 'The web address may only use letters, digits, dots and hyphens (for example 10.42.0.1).'; }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $in['admin_user'])) { $err[] = 'Username: 3 to 50 letters, digits, dots, dashes or underscores.'; }
    if ($in['admin_name'] === '' || mb_strlen($in['admin_name']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $in['admin_name'])) { $err[] = 'Enter the administrator\'s full name.'; }
    if ($in['admin_email'] !== '' && !filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) { $err[] = 'The email address is not valid (or leave it blank).'; }
    if (mb_strlen($in['admin_password']) < 12) { $err[] = 'The administrator password must be at least 12 characters.'; }
    elseif ($in['admin_password'] !== $in['admin_password2']) { $err[] = 'The two administrator passwords are different.'; }
    elseif (preg_match('/[\x00-\x1F\x7F]/', $in['admin_password'])) { $err[] = 'The administrator password has a character that can\'t be used.'; }
    if (!preg_match('/^[A-Za-z0-9._ -]{1,32}$/', $in['wifi_ssid'])) { $err[] = 'Wi-Fi name: 1 to 32 letters, digits, spaces, dots, dashes or underscores.'; }
    $l = strlen($in['wifi_psk']);
    if ($l < 8 || $l > 63 || !preg_match('/^[\x20-\x7E]+$/', $in['wifi_psk'])) { $err[] = 'Wi-Fi password: 8 to 63 characters (letters, digits and common symbols).'; }
    elseif ($in['wifi_psk'] !== $in['wifi_psk2']) { $err[] = 'The two Wi-Fi passwords are different.'; }
    if ($in['set_epoch'] !== '' && !preg_match('/^[0-9]{10}$/', $in['set_epoch'])) { $err[] = 'The clock time from your device could not be read.'; }
    return $err;
}

/** Hands the answers to the root helper (written to a temporary name, then renamed, so it is never half-written). */
function setup_submit(array $in): bool
{
    $data = json_encode([
        'timezone' => $in['timezone'], 'server_name' => $in['server_name'],
        'admin_user' => $in['admin_user'], 'admin_name' => $in['admin_name'], 'admin_email' => $in['admin_email'],
        'admin_password' => $in['admin_password'], 'wifi_ssid' => $in['wifi_ssid'], 'wifi_psk' => $in['wifi_psk'],
        'set_epoch' => $in['set_epoch'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($data === false) { return false; }
    $tmp = SETUP_STATE . '/.request.' . bin2hex(random_bytes(6));
    $old = umask(0077);
    $ok = file_put_contents($tmp, $data) !== false && rename($tmp, SETUP_STATE . '/request.json');
    umask($old);
    if (!$ok) { @unlink($tmp); }
    return $ok;
}
