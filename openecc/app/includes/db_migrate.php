<?php
declare(strict_types=1);

/**
 * Database setup and migrations, shared by install.sh, update.sh and the Windows installer.
 * Used only from the command line (bin/install_db.php and bin/migrate.php). It does not load db.php, so it works
 * before any settings file exists.
 *
 * SQL files are run one statement at a time through PDO. They must not use the mariadb client's own commands
 * (DELIMITER, SOURCE, \G): cb_sql_split() refuses them, so a migration that needs them fails loudly instead of
 * running half-way.
 */

/** Connects to MariaDB. $c: host, port, socket, user, password, dbname (all optional except user). */
function cb_dbsetup_connect(array $c): PDO
{
    $parts = [];
    if (($c['socket'] ?? '') !== '') {
        $parts[] = 'unix_socket=' . $c['socket'];
    } else {
        $parts[] = 'host=' . (($c['host'] ?? '') !== '' ? $c['host'] : '127.0.0.1');
        if ((int)($c['port'] ?? 0) > 0) { $parts[] = 'port=' . (int)$c['port']; }
    }
    if (($c['dbname'] ?? '') !== '') { $parts[] = 'dbname=' . $c['dbname']; }
    $parts[] = 'charset=utf8mb4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) { $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false; }
    return new PDO('mysql:' . implode(';', $parts), (string)($c['user'] ?? ''), (string)($c['password'] ?? ''), $options);
}

/** The local MariaDB socket on Linux (for connecting as root without a password), or '' if none is found. */
function cb_dbsetup_find_socket(): string
{
    $candidates = [(string)ini_get('pdo_mysql.default_socket'), '/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'];
    foreach ($candidates as $s) {
        if ($s !== '' && @file_exists($s)) { return $s; }
    }
    return '';
}

/** Reads KEY=value lines (same rules as load_db_env() in db.php). */
function cb_dbsetup_read_env(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Settings file not found or not readable: $path");
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim($v);
    }
    return $vars;
}

/**
 * Splits an SQL script into statements. Understands '...', "...", `...`, backslash escapes, -- and # comments and
 * /* *\/ comments (including /*! ... *\/ version comments, which are kept and run by the server).
 * Returns the statements without the trailing semicolon; pieces that are only comments are dropped.
 */
function cb_sql_split(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;    // UTF-8 byte-order mark
    $statements = [];
    $current = '';
    $hasCode = false;   // the current piece has something other than comments and spaces
    $len = strlen($sql);
    $i = 0;
    $lineStart = true;
    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($lineStart && !$hasCode) {
            $rest = ltrim(substr($sql, $i, 20));
            if (preg_match('/^(DELIMITER|SOURCE|\\\\\.)(\s|$)/i', $rest)) {
                throw new RuntimeException('Client-only command found (' . strtok($rest, " \t\r\n") . '); write the file as plain SQL statements.');
            }
        }
        $lineStart = false;

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $current .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) { $current .= $c . $sql[$i + 1]; $i += 2; continue; }
                $current .= $c;
                $i++;
                if ($c === $quote) {
                    if ($i < $len && $sql[$i] === $quote) { $current .= $sql[$i]; $i++; continue; }   // doubled quote
                    break;
                }
            }
            if ($i >= $len && substr($current, -1) !== $quote) {
                throw new RuntimeException('Unterminated quoted text near: ' . substr(trim($current), 0, 80));
            }
            $hasCode = true;
            continue;
        }
        if (($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $ch === '#') {
            $end = strpos($sql, "\n", $i);
            $end = $end === false ? $len : $end;
            $current .= substr($sql, $i, $end - $i);
            $i = $end;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { throw new RuntimeException('Unterminated /* comment.'); }
            $comment = substr($sql, $i, $end + 2 - $i);
            $current .= $comment;
            if (str_starts_with($comment, '/*!') || str_starts_with($comment, '/*M!')) { $hasCode = true; }
            $i = $end + 2;
            continue;
        }
        if ($ch === ';') {
            if ($hasCode) { $statements[] = trim($current); }
            $current = '';
            $hasCode = false;
            $i++;
            continue;
        }
        if ($ch === "\n") { $lineStart = true; }
        if (!ctype_space($ch)) { $hasCode = true; }
        $current .= $ch;
        $i++;
    }
    if ($hasCode && trim($current) !== '') { $statements[] = trim($current); }
    return $statements;
}

/**
 * Runs every statement in an SQL file. Stops at the first error (like the mariadb client) and says which statement.
 * Foreign key checks are switched back on afterwards, as a new client session would have them.
 */
function cb_sql_run_file(PDO $pdo, string $path): int
{
    $sql = @file_get_contents($path);
    if ($sql === false) { throw new RuntimeException("Cannot read $path"); }
    try {
        $statements = cb_sql_split($sql);
    } catch (RuntimeException $e) {
        throw new RuntimeException(basename($path) . ': ' . $e->getMessage(), 0, $e);
    }
    $n = 0;
    try {
        foreach ($statements as $n0 => $statement) {
            $n = $n0 + 1;
            $pdo->exec($statement);
        }
    } catch (PDOException $e) {
        $preview = preg_replace('/\s+/', ' ', substr($statements[$n - 1] ?? '', 0, 160));
        throw new RuntimeException(basename($path) . ", statement $n: " . $e->getMessage() . "\n  SQL: " . $preview, 0, $e);
    } finally {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $ignored) { /* connection lost; the error above says why */ }
    }
    return count($statements);
}

/** Migration files in a folder, sorted, as [version => path]. */
function cb_migration_files(string $migrationsDir): array
{
    $files = glob(rtrim($migrationsDir, '/\\') . '/[0-9]*.sql') ?: [];
    sort($files, SORT_STRING);
    $out = [];
    foreach ($files as $f) { $out[basename($f, '.sql')] = $f; }
    return $out;
}

/** Makes sure schema_migrations exists and has the baseline row (older installs did not). */
function cb_migrations_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version varchar(64) NOT NULL, applied_at datetime NOT NULL DEFAULT current_timestamp(), '
        . 'PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT IGNORE INTO schema_migrations (version) VALUES ('0000_baseline')");
}

/** Versions already recorded. */
function cb_migrations_applied(PDO $pdo): array
{
    return array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
}

/** Pending migrations as [version => path], in order. */
function cb_migrations_pending(PDO $pdo, string $migrationsDir): array
{
    $applied = array_flip(cb_migrations_applied($pdo));
    return array_filter(cb_migration_files($migrationsDir), static fn($v) => !isset($applied[$v]), ARRAY_FILTER_USE_KEY);
}

/**
 * Applies pending migrations in order, recording each one only after it ran without error.
 * $progress is called with each version before it runs. Returns the versions applied.
 */
function cb_migrations_apply(PDO $pdo, string $migrationsDir, ?callable $progress = null): array
{
    cb_migrations_ensure_table($pdo);
    $done = [];
    foreach (cb_migrations_pending($pdo, $migrationsDir) as $version => $path) {
        if ($progress) { $progress($version); }
        cb_sql_run_file($pdo, $path);
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $done[] = $version;
    }
    return $done;
}

/** Fresh installs: schema.sql already contains every migration, so they are all recorded as applied. */
function cb_migrations_record_all(PDO $pdo, string $migrationsDir): int
{
    cb_migrations_ensure_table($pdo);
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
    $n = 0;
    foreach (array_keys(cb_migration_files($migrationsDir)) as $version) { $stmt->execute([$version]); $n++; }
    return $n;
}
