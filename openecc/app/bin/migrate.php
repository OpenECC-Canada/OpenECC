<?php
declare(strict_types=1);

/*
 * Applies pending database migrations (database/migrations/NNNN_*.sql) in order and records each one in
 * schema_migrations. Used by update.sh and the Windows installer; safe to run when nothing is pending.
 *
 *   php bin/migrate.php [--env-file=PATH] [--status]                 connect with the app account from db.env
 *   php bin/migrate.php --admin [--env-file=PATH]                    Linux, as root: MariaDB root over the local socket
 *   php bin/migrate.php --admin-user=root --admin-password-stdin     any system: an admin account over TCP
 *
 * --env-file   the settings file (default: the same one the app uses, normally /etc/openecc/db.env)
 * --status     list applied and pending migrations, change nothing
 * --migrations folder with the migration files (default: database/migrations next to app/)
 *
 * The database name and host always come from the settings file. Exit code 0 = OK, 1 = a migration failed (the
 * failed one is not recorded, so fixing the problem and running again picks up where it stopped).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/db_migrate.php';

$opts = getopt('', ['env-file:', 'status', 'admin', 'admin-user:', 'admin-password-stdin', 'migrations:', 'help']);
if (isset($opts['help'])) {
    echo "See the comment at the top of bin/migrate.php\n";
    exit(0);
}

$envFile = (string)($opts['env-file'] ?? '');
if ($envFile === '') {
    $envFile = (string)getenv('DB_ENV_PATH');
    if ($envFile === '') { $envFile = is_file('/etc/openecc/db.env') ? '/etc/openecc/db.env' : dirname(__DIR__) . '/db.env'; }
}
$migrationsDir = (string)($opts['migrations'] ?? (dirname(__DIR__, 2) . '/database/migrations'));

try {
    if (!is_dir($migrationsDir)) { throw new RuntimeException("Migrations folder not found: $migrationsDir"); }
    $env = cb_dbsetup_read_env($envFile);
    $dbName = (string)($env['DB_NAME'] ?? '');
    if ($dbName === '') { throw new RuntimeException("DB_NAME is missing from $envFile"); }

    $conn = ['host' => (string)($env['DB_HOST'] ?? '127.0.0.1'), 'port' => (int)($env['DB_PORT'] ?? 0), 'dbname' => $dbName];
    if (isset($opts['admin'])) {
        $socket = cb_dbsetup_find_socket();
        if ($socket === '') { throw new RuntimeException('--admin needs the local MariaDB socket, which was not found. Use --admin-user instead.'); }
        $conn = ['socket' => $socket, 'user' => 'root', 'password' => '', 'dbname' => $dbName];
        $who = 'root (local socket)';
    } elseif (isset($opts['admin-user'])) {
        $conn['user'] = (string)$opts['admin-user'];
        $conn['password'] = isset($opts['admin-password-stdin']) ? rtrim((string)stream_get_contents(STDIN), "\r\n") : '';
        $who = $conn['user'];
    } else {
        $conn['user'] = (string)($env['DB_APP_USER'] ?? '');
        $conn['password'] = (string)($env['DB_APP_PASSWORD'] ?? '');
        if ($conn['user'] === '') { throw new RuntimeException("DB_APP_USER is missing from $envFile"); }
        $who = $conn['user'];
    }

    $pdo = cb_dbsetup_connect($conn);

    if (isset($opts['status'])) {
        $hasTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn() > 0;
        $applied = $hasTable ? array_flip(cb_migrations_applied($pdo)) : [];
        $pending = 0;
        foreach (cb_migration_files($migrationsDir) as $version => $path) {
            $isApplied = isset($applied[$version]);
            if (!$isApplied) { $pending++; }
            echo ($isApplied ? '  applied  ' : '  PENDING  ') . $version . "\n";
        }
        echo $hasTable ? '' : "  (schema_migrations table missing: it is created on the next run)\n";
        echo "Pending migrations: $pending\n";
        exit(0);
    }

    echo "Database $dbName, connected as $who\n";
    $done = cb_migrations_apply($pdo, $migrationsDir, static function (string $v): void { echo "  Migration $v\n"; });
    echo 'Migrations applied: ' . count($done) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
