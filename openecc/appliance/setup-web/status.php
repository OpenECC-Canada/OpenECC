<?php
declare(strict_types=1);
/** OpenECC setup mode: install progress for the setup page (JSON). */
require __DIR__ . '/lib.php';
setup_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!setup_authorized()) { http_response_code(403); echo json_encode(['error' => 'not signed in']); exit; }
session_write_close();

$log = (string)@file_get_contents(SETUP_STATE . '/progress.log');
$lines = explode("\n", $log);
if (count($lines) > 300) { $lines = array_slice($lines, -300); }
$result = json_decode((string)@file_get_contents(SETUP_STATE . '/result.json'), true);

echo json_encode([
    'state'  => setup_state(),
    'log'    => implode("\n", $lines),
    'result' => is_array($result) ? $result : null,
    'time'   => time(),
], JSON_UNESCAPED_SLASHES);
