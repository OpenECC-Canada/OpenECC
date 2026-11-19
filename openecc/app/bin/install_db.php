<?php
declare(strict_types=1);

/*
 * Creates the OpenECC database for a fresh install: database, application account (random password), schema.sql,
 * seed.sql, every migration recorded as already applied, then writes the settings file (db.env).
 * Used by install.sh and the Windows installer. Refuses to touch an existing database or settings file.
 *
 *   php bin/install_db.php --env-file=/etc/openecc/db.env --admin [--timezone=America/Edmonton]
 *   php bin/install_db.php --env-file=PATH --admin-user=root --admin-password-stdin [--db-port=3306]
 *
 * --admin                 Linux, run as root: MariaDB root over the local socket (no password)
 * --admin-user / --admin-password-stdin   an admin account over TCP (Windows)
 * --db-name, --db-user    default openecc / openecc
 * --db-host, --db-port    where the app connects (default 127.0.0.1, default port)
 * --timezone              written to db.env as APP_TIMEZONE (default UTC)
 * --database-dir          folder with schema.sql, seed.sql and migrations/ (default: database next to app/)
 *
 * The settings file is created readable by its owner only; the caller sets the final owner and permissions.
 * If anything fails after the database was created, the new database and account are removed again so the install
 * can simply be run again.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/db_migrate.php';

$opts = getopt('', ['env-file:', 'admin', 'admin-user:', 'admin-password-stdin', 'db-name:', 'db-user:', 'db-host:', 'db-port:',
    'timezone:', 'database-dir:', 'help']);
if (isset($opts['help'])) {
    echo "See the comment at the top of bin/install_db.php\n";
    exit(0);
}

$envFile = (string)($opts['env-file'] ?? '');
$dbName = (string)($opts['db-name'] ?? 'openecc');
$dbUser = (string)($opts['db-user'] ?? 'openecc');
$dbHost = (string)($opts['db-host'] ?? '127.0.0.1');
$dbPort = (int)($opts['db-port'] ?? 0);
$timezone = (string)($opts['timezone'] ?? 'UTC');
$databaseDir = rtrim((string)($opts['database-dir'] ?? (dirname(__DIR__, 2) . '/database')), '/\\');

$fail = static function (string $msg): never { fwrite(STDERR, 'ERROR: ' . $msg . "\n"); exit(1); };

if ($envFile === '') { $fail('--env-file is required.'); }
if (file_exists($envFile)) { $fail("The settings file $envFile already exists. This is for fresh installs only; use the updater."); }
if (!is_dir(dirname($envFile))) { $fail('The folder for the settings file does not exist: ' . dirname($envFile)); }
foreach (['--db-name' => $dbName, '--db-user' => $dbUser] as $label => $value) {
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $value)) { $fail("$label may only contain letters, digits and _ (1-32 characters)."); }
}
if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $dbHost)) { $fail('--db-host is not valid.'); }
try { new DateTimeZone($timezone); } catch (Throwable $e) { $fail("Unknown time zone '$timezone' (example: America/Edmonton)."); }
foreach (['schema.sql', 'seed.sql'] as $f) {
    if (!is_file("$databaseDir/$f")) { $fail("$databaseDir/$f not found."); }
}

if (isset($opts['admin'])) {
    $socket = cb_dbsetup_find_socket();
    if ($socket === '') { $fail('--admin needs the local MariaDB socket, which was not found. Is MariaDB running?'); }
    $admin = ['socket' => $socket, 'user' => 'root', 'password' => ''];
} elseif (isset($opts['admin-user'])) {
    $admin = ['host' => $dbHost, 'port' => $dbPort, 'user' => (string)$opts['admin-user'],
        'password' => isset($opts['admin-password-stdin']) ? rtrim((string)stream_get_contents(STDIN), "\r\n") : ''];
} else {
    $fail('Give --admin (Linux, as root) or --admin-user with --admin-password-stdin.');
}

$created = false;
$createdUsers = [];
$pdo = null;
try {
    $pdo = cb_dbsetup_connect($admin);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$dbName]);
    if ((int)$exists->fetchColumn() > 0) { $fail("A database named '$dbName' already exists. Remove it or use the updater."); }

    $password = bin2hex(random_bytes(20));
    $pw = $pdo->quote($password);
    $u = $pdo->quote($dbUser);
    $grant = 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, LOCK TABLES';

    $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    foreach (['localhost', '127.0.0.1'] as $h) {
        $pdo->exec("CREATE USER $u@'$h' IDENTIFIED BY $pw");
        $createdUsers[] = $h;
        $pdo->exec("GRANT $grant ON `$dbName`.* TO $u@'$h'");
    }
    $pdo->exec('FLUSH PRIVILEGES');
    $pdo->exec("USE `$dbName`");

    echo "Importing schema.sql\n";
    cb_sql_run_file($pdo, "$databaseDir/schema.sql");
    echo "Importing seed.sql\n";
    cb_sql_run_file($pdo, "$databaseDir/seed.sql");
    $pdo->exec("INSERT IGNORE INTO schema_migrations (version) VALUES ('0000_baseline')");
    $recorded = cb_migrations_record_all($pdo, "$databaseDir/migrations");
    echo "Recorded $recorded migration(s) as included in the schema\n";

    $count = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $count->execute([$dbName]);
    $tables = (int)$count->fetchColumn();
    if ($tables <= 50) { throw new RuntimeException("Database import looks incomplete ($tables tables)."); }

    // The app connects with these settings; check they work before writing them down.
    cb_dbsetup_connect(['host' => $dbHost, 'port' => $dbPort, 'user' => $dbUser, 'password' => $password, 'dbname' => $dbName])->query('SELECT 1');

    $lines = [
        '# OpenECC database settings - written by the installer. Keep private.',
        'DB_HOST=' . $dbHost,
    ];
    if ($dbPort > 0) { $lines[] = 'DB_PORT=' . $dbPort; }
    $lines[] = 'DB_NAME=' . $dbName;
    $lines[] = 'DB_APP_USER=' . $dbUser;
    $lines[] = 'DB_APP_PASSWORD=' . $password;
    $lines[] = 'APP_TIMEZONE=' . $timezone;
    $old = umask(0077);
    $ok = file_put_contents($envFile, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    umask($old);
    if ($ok === false) { throw new RuntimeException("Could not write $envFile"); }
    @chmod($envFile, 0600);

    echo "Database ready ($tables tables).\n";
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($created && $pdo instanceof PDO) {
        try {
            $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
            foreach ($createdUsers as $h) { $pdo->exec('DROP USER IF EXISTS ' . $pdo->quote($dbUser) . "@'$h'"); }
            $msg .= "\n(The half-created database '$dbName' and its account were removed; fix the problem and run the installer again.)";
        } catch (Throwable $ignored) {
            $msg .= "\n(Could not remove the half-created database '$dbName'; drop it by hand before trying again.)";
        }
    }
    if (is_file($envFile) && $created) { @unlink($envFile); }
    $fail($msg);
}
