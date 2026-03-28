<?php
declare(strict_types=1);

/**
 * database.php — xpsystems statuspage
 *
 * Thin PDO wrapper that supports both SQLite and MySQL.
 * The driver is selected via $config['db']['driver'].
 *
 * SQLite  → file-based, zero config, good for single-server setups.
 * MySQL   → use when you need multi-node access or external dashboards.
 *
 * Schema (auto-created on first connect):
 *
 *   checks
 *     id          INTEGER PK AUTOINCREMENT
 *     checked_at  INTEGER  (unix timestamp)
 *     overall     TEXT     (operational | partial_outage | major_outage)
 *
 *   check_results
 *     id          INTEGER PK AUTOINCREMENT
 *     check_id    INTEGER  FK → checks.id
 *     slug        TEXT
 *     status      TEXT     (up | degraded | down | unknown)
 *     http_code   INTEGER  nullable
 *     latency_ms  INTEGER  nullable
 */

function db_connect(array $config): PDO
{
    $db = $config['db'];

    if ($db['driver'] === 'sqlite') {
        $dir = dirname($db['sqlite_path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $db['sqlite_path']);
        $pdo->exec('PRAGMA journal_mode=WAL');   // safe for concurrent reads
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
        throw new \InvalidArgumentException("Unknown DB driver: {$db['driver']}. Use 'sqlite' or 'mysql'.");
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    db_migrate($pdo, $db['driver']);

    return $pdo;
}

function db_migrate(PDO $pdo, string $driver): void
{
    $ai = $driver === 'mysql' ? 'INT AUTO_INCREMENT' : 'INTEGER';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS checks (
            id         $ai PRIMARY KEY,
            checked_at INTEGER  NOT NULL,
            overall    TEXT     NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS check_results (
            id         $ai PRIMARY KEY,
            check_id   INTEGER  NOT NULL,
            slug       TEXT     NOT NULL,
            status     TEXT     NOT NULL,
            http_code  INTEGER,
            latency_ms INTEGER,
            FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
        )
    ");

    // Index for fast per-slug history queries
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_check_results_slug
        ON check_results (slug, check_id)
    ");
}

/**
 * Persist one full check run to the database.
 * $results = [ slug => ['status'=>..., 'code'=>..., 'latency_ms'=>...], ... ]
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
 * Load the last $limit check rows for a given slug.
 * Returns [ ['ts', 'status', 'latency_ms', 'http_code'], ... ]
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
    $rows = $stmt->fetchAll();
    // Return in chronological order (oldest first)
    return array_reverse($rows);
}

/**
 * Load the last $limit full check entries (all slugs per check).
 * Returns [ ['ts', 'overall', 'services' => [...]], ... ]
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

    $ids       = array_column($checks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT check_id, slug, status, latency_ms, http_code
        FROM   check_results
        WHERE  check_id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $results = $stmt->fetchAll();

    // Group results by check_id
    $by_check = [];
    foreach ($results as $r) {
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
 * Prune checks older than $keep_days days.
 * Cascades to check_results via FK.
 */
function db_prune(PDO $pdo, int $keep_days = 30): int
{
    $cutoff = time() - ($keep_days * 86400);
    $stmt   = $pdo->prepare('DELETE FROM checks WHERE checked_at < ?');
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}
