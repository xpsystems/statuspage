<?php
declare(strict_types=1);

/**
 * database.php — xpsystems statuspage
 *
 * Thin PDO wrapper supporting SQLite and MySQL.
 * Data is kept forever — no pruning.
 *
 * Driver selected via $config['db']['driver']:
 *   'sqlite' → requires pdo_sqlite  (php -m | grep pdo_sqlite)
 *   'mysql'  → requires pdo_mysql   (php -m | grep pdo_mysql)
 *   'none'   → JSON file fallback
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
            "Install pdo_{$db['driver']} or set db.driver = 'none' in config.php."
        );
    }

    if ($db['driver'] === 'sqlite') {
        $dir = dirname($db['sqlite_path']);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $db['sqlite_path']);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['mysql_host'], $db['mysql_port'], $db['mysql_dbname']
        );
        $pdo = new PDO($dsn, $db['mysql_user'], $db['mysql_password'], [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    db_migrate($pdo, $db['driver']);

    return $pdo;
}

function db_migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS checks (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                checked_at INT         NOT NULL,
                overall    VARCHAR(32) NOT NULL,
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
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cr_slug_check ON check_results (slug, check_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_checks_ts ON checks (checked_at)");
    }
}

// ── Write ─────────────────────────────────────────────────────────────────────

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

// ── Read: recent checks (for sparkline) ──────────────────────────────────────

/**
 * Last $limit individual check rows for a slug, chronological.
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
 * Last $limit full check entries (all slugs), chronological.
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

    if (empty($checks)) return [];

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

// ── Read: day-aggregated (for 90-day uptime bars) ────────────────────────────

/**
 * Returns one entry per calendar day (UTC) for the last $days days.
 *
 * Each entry:
 *   date          string  'YYYY-MM-DD'
 *   total_checks  int
 *   up_checks     int
 *   down_checks   int
 *   degraded_checks int
 *   uptime_pct    float   percentage of checks with status='up'
 *   outage_pct    float   percentage of checks with status='down'
 *   avg_latency_ms int|null  average of non-down latencies
 *   had_outage    bool    true if any check was 'down' that day
 *   had_degraded  bool    true if any check was 'degraded' that day
 */
function db_days_for_slug(PDO $pdo, string $slug, int $days = 90): array
{
    $since = mktime(0, 0, 0) - (($days - 1) * 86400); // start of day, $days ago

    $stmt = $pdo->prepare("
        SELECT c.checked_at,
               r.status,
               r.latency_ms
        FROM   check_results r
        JOIN   checks c ON c.id = r.check_id
        WHERE  r.slug = ?
          AND  c.checked_at >= ?
        ORDER  BY c.checked_at ASC
    ");
    $stmt->execute([$slug, $since]);
    $rows = $stmt->fetchAll();

    // Group by UTC date
    $by_day = [];
    foreach ($rows as $row) {
        $date = gmdate('Y-m-d', (int) $row['checked_at']);
        $by_day[$date][] = $row;
    }

    // Build one entry per day in the window (fill gaps with null)
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date    = gmdate('Y-m-d', time() - $i * 86400);
        $checks  = $by_day[$date] ?? [];

        if (empty($checks)) {
            $out[] = [
                'date'            => $date,
                'total_checks'    => 0,
                'up_checks'       => 0,
                'down_checks'     => 0,
                'degraded_checks' => 0,
                'uptime_pct'      => null,
                'outage_pct'      => null,
                'avg_latency_ms'  => null,
                'had_outage'      => false,
                'had_degraded'    => false,
            ];
            continue;
        }

        $total    = count($checks);
        $up       = count(array_filter($checks, fn($r) => $r['status'] === 'up'));
        $down     = count(array_filter($checks, fn($r) => $r['status'] === 'down'));
        $degraded = count(array_filter($checks, fn($r) => $r['status'] === 'degraded'));

        // Latency: exclude down checks (connection failures skew the average)
        $latencies = array_filter(
            array_map(fn($r) => $r['status'] !== 'down' ? (int) $r['latency_ms'] : null, $checks),
            fn($v) => $v !== null
        );

        $out[] = [
            'date'            => $date,
            'total_checks'    => $total,
            'up_checks'       => $up,
            'down_checks'     => $down,
            'degraded_checks' => $degraded,
            'uptime_pct'      => round($up   / $total * 100, 2),
            'outage_pct'      => round($down / $total * 100, 2),
            'avg_latency_ms'  => $latencies ? (int) round(array_sum($latencies) / count($latencies)) : null,
            'had_outage'      => $down > 0,
            'had_degraded'    => $degraded > 0,
        ];
    }

    return $out;
}

/**
 * Overall uptime stats for a slug over the last $days days.
 *
 * Returns:
 *   uptime_pct      float   overall % of checks that were 'up'
 *   outage_pct      float   overall % of checks that were 'down'
 *   avg_latency_ms  int|null
 *   total_checks    int
 *   days_with_outage int    number of calendar days that had at least one 'down'
 */
function db_uptime_stats(PDO $pdo, string $slug, int $days = 90): array
{
    $since = time() - ($days * 86400);

    $stmt = $pdo->prepare("
        SELECT r.status, r.latency_ms, c.checked_at
        FROM   check_results r
        JOIN   checks c ON c.id = r.check_id
        WHERE  r.slug = ?
          AND  c.checked_at >= ?
    ");
    $stmt->execute([$slug, $since]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return [
            'uptime_pct'      => null,
            'outage_pct'      => null,
            'avg_latency_ms'  => null,
            'total_checks'    => 0,
            'days_with_outage' => 0,
        ];
    }

    $total    = count($rows);
    $up       = count(array_filter($rows, fn($r) => $r['status'] === 'up'));
    $down     = count(array_filter($rows, fn($r) => $r['status'] === 'down'));

    $latencies = array_filter(
        array_map(fn($r) => $r['status'] !== 'down' ? (int) $r['latency_ms'] : null, $rows),
        fn($v) => $v !== null
    );

    $outage_days = count(array_unique(
        array_map(
            fn($r) => gmdate('Y-m-d', (int) $r['checked_at']),
            array_filter($rows, fn($r) => $r['status'] === 'down')
        )
    ));

    return [
        'uptime_pct'       => round($up   / $total * 100, 3),
        'outage_pct'       => round($down / $total * 100, 3),
        'avg_latency_ms'   => $latencies ? (int) round(array_sum($latencies) / count($latencies)) : null,
        'total_checks'     => $total,
        'days_with_outage' => $outage_days,
    ];
}
