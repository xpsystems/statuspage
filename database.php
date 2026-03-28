<?php
declare(strict_types=1);

/**
 * database.php — xpsystems statuspage
 *
 * Thin PDO wrapper supporting SQLite and MySQL.
 * Driver is selected via $config['db']['driver'].
 *
 * SQLite  → requires pdo_sqlite PHP extension.
 *           Check with: php -m | grep -i sqlite
 * MySQL   → requires pdo_mysql PHP extension.
 *           Check with: php -m | grep -i mysql
 *
 * If neither extension is available, set driver = 'none' in config.php
 * to use the JSON file fallback instead.
 */

/**
 * Returns true if the requested driver's PDO extension is loaded.
 */
function db_driver_available(string $driver): bool
{
    return match($driver) {
        'sqlite' => extension_loaded('pdo_sqlite'),
        'mysql'  => extension_loaded('pdo_mysql'),
        default  => false,
    };
}

function db_connect(array $config): PDO
{
    $db = $config['db'];

    if (!db_driver_available($db['driver'])) {
        throw new \RuntimeException(
            "PDO driver for '{$db['driver']}' is not available. " .
            "Install the pdo_{$db['driver']} PHP extension, or set db.driver = 'none' in config.php."
        );
    }

    if ($db['driver'] === 'sqlite') {
        $dir = dirname($db['sqlite_path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $db['sqlite_path']);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

    } elseif ($db['driver'] === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['mysql_host'],
            $db['mysql_port'],
            $db['mysql_dbname']
        );
        $pdo = new PDO($dsn, $db['mysql_user'], $db['mysql_password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    } else {
        throw new \InvalidArgumentException(
            "Unknown DB driver: '{$db['driver']}'. Use 'sqlite', 'mysql', or 'none'."
        );
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    db_migrate($pdo, $db['driver']);

    return $pdo;
}

function db_migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        // MySQL: use VARCHAR for indexed columns (TEXT columns can't be indexed
        // without a prefix, and prefix indexes are unreliable for FK cascades).
        // overall max length: 'partial_outage' = 14 chars → VARCHAR(32) is safe.
        // slug max length: longest realistic slug ~64 chars → VARCHAR(128).
        // status max length: 'not_deployed' = 12 chars → VARCHAR(32).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS checks (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                checked_at INT          NOT NULL,
                overall    VARCHAR(32)  NOT NULL,
                INDEX idx_checks_ts (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS check_results (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                check_id   INT          NOT NULL,
                slug       VARCHAR(128) NOT NULL,
                status     VARCHAR(32)  NOT NULL,
                http_code  SMALLINT,
                latency_ms INT,
                INDEX idx_cr_slug_check (slug, check_id),
                CONSTRAINT fk_cr_check FOREIGN KEY (check_id)
                    REFERENCES checks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

    } else {
        // SQLite
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS checks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                checked_at INTEGER NOT NULL,
                overall    TEXT    NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS check_results (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                check_id   INTEGER NOT NULL,
                slug       TEXT    NOT NULL,
                status     TEXT    NOT NULL,
                http_code  INTEGER,
                latency_ms INTEGER,
                FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
            )
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_cr_slug_check
            ON check_results (slug, check_id)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_checks_ts
            ON checks (checked_at)
        ");
    }
}

/**
 * Persist one full check run to the database.
 */
function db_insert_check(PDO $pdo, int $checked_at, string $overall, array $results): int
{
    $stmt = $pdo->prepare('INSERT INTO checks (checked_at, overall) VALUES (?, ?)');
    $stmt->execute([$checked_at, $overall]);
    $check_id = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO check_results (check_id, slug, status, http_code, latency_ms)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($results as $slug => $r) {
        $stmt->execute([
            $check_id,
            $slug,
            $r['status']     ?? 'unknown',
            $r['code']       ?? null,
            $r['latency_ms'] ?? null,
        ]);
    }

    return $check_id;
}

/**
 * Load the last $limit check rows for a given slug (chronological order).
 */
function db_history_for_slug(PDO $pdo, string $slug, int $limit = 90): array
{
    $stmt = $pdo->prepare("
        SELECT c.checked_at AS ts,
               r.status,
               r.latency_ms,
               r.http_code
        FROM   check_results r
        JOIN   checks c ON c.id = r.check_id
        WHERE  r.slug = ?
        ORDER  BY c.checked_at DESC
        LIMIT  ?
    ");
    $stmt->execute([$slug, $limit]);
    return array_reverse($stmt->fetchAll());
}

/**
 * Load the last $limit full check entries (all slugs per check).
 */
function db_history_full(PDO $pdo, int $limit = 90): array
{
    $stmt = $pdo->prepare("
        SELECT id, checked_at, overall
        FROM   checks
        ORDER  BY checked_at DESC
        LIMIT  ?
    ");
    $stmt->execute([$limit]);
    $checks = array_reverse($stmt->fetchAll());

    if (empty($checks)) {
        return [];
    }

    $ids          = array_column($checks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT check_id, slug, status, latency_ms, http_code
        FROM   check_results
        WHERE  check_id IN ($placeholders)
    ");
    $stmt->execute($ids);

    $by_check = [];
    foreach ($stmt->fetchAll() as $r) {
        $by_check[$r['check_id']][$r['slug']] = [
            'status'     => $r['status'],
            'latency_ms' => $r['latency_ms'],
            'http_code'  => $r['http_code'],
        ];
    }

    $out = [];
    foreach ($checks as $c) {
        $out[] = [
            'ts'       => (int) $c['checked_at'],
            'overall'  => $c['overall'],
            'services' => $by_check[$c['id']] ?? [],
        ];
    }

    return $out;
}

/**
 * Prune checks older than $keep_days days (cascades to check_results via FK).
 */
function db_prune(PDO $pdo, int $keep_days = 30): int
{
    $cutoff = time() - ($keep_days * 86400);
    $stmt   = $pdo->prepare('DELETE FROM checks WHERE checked_at < ?');
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}
