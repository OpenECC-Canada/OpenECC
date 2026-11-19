<?php
declare(strict_types=1);
function command_bridge_find_tcpdf_path(): ?string
{
    // OPENECC_TCPDF_PATH (full path to tcpdf.php) wins, then a copy in app/vendor/tcpdf (the Windows package
    // ships one there), then Debian's php-tcpdf package.
    $configured = getenv('OPENECC_TCPDF_PATH');
    if (!is_string($configured) || $configured === '') { $configured = (string)($_SERVER['OPENECC_TCPDF_PATH'] ?? ''); }
    $candidates = [
        $configured,
        dirname(__DIR__, 2) . '/vendor/tcpdf/tcpdf.php',
        '/usr/share/php/tcpdf/tcpdf.php',
        '/usr/share/php/TCPDF/tcpdf.php',
        '/usr/share/tcpdf/tcpdf.php',
        '/usr/local/share/php/tcpdf/tcpdf.php',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function command_bridge_require_tcpdf(): bool
{
    if (class_exists('TCPDF', false)) {
        return true;
    }

    $path = command_bridge_find_tcpdf_path();
    if ($path === null) {
        return false;
    }

    require_once $path;
    return class_exists('TCPDF', false);
}
