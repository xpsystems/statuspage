<?php
declare(strict_types=1);

/**
 * check.php — xpsystems statuspage
 *
 * Pings all deployed services, writes results to:
 *   - cache/status.json   (current status, read by index.php)
 *   - cache/history.json  (rolling JSON log, when db.driver = 'none')
 *   - SQLite / MySQL DB   (when db.driver = 'sqlite' or 'mysql')
 *
 * Usage:
 *   CLI / cron:  php /path/to/statuspage/check.php
 *   Web cron:    curl -s https://status.xpsystems.eu/check.php?token=SECRET
 *   require:     require __DIR__ . '/check.php';  (from index.php)
 *
 * Cron example (every 5 minutes):
 *   *\/5 * * * * php /var/www/statuspage/check.php >> /var/log/xps-check.log 2>&1
 *
 * When called via HTTP, protect with a secret token set in config:
 *   $config['check']['token'] = 'your-secret-token';
 * Leave empty ('') to disable token protection (not recommended for public servers).
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$is_cli = PHP_SAPI === 'cli';

// Allow require from index.php (functions already loaded) or standalone
if (!function_exists('deployed_services')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/database.php';

// ── HTTP token guard (only when called directly via web) ──────────────────────

if (!$is_cli && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'check.php') {
    $token = $config['check']['token'] ?? '';
    if ($token !== '' && ($_GET['token'] ?? '') !== $token) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — invalid or missing token']);
        exit;
    }
}

// ── Core functions ────────────────────────────────────────────────────────────

function check_ping_url(string $url, int $timeout, string $ua): array
{
    $start = microtime(true);

    if (!function_exists('curl_init')) {
        return ['status' => 'unknown', 'code' => null, 'latency_ms' => null];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_NOBODY         => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER         => false,
    ]);

    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);

    $latency = (int) round((microtime(true) - $start) * 1000);

    if ($err !== 0 || $code === 0) {
        return ['status' => 'down', 'code' => null, 'latency_ms' => $latency];
    }
    if ($code >= 500) {
        return ['status' => 'degraded', 'code' => $code, 'latency_ms' => $latency];
    }

    return ['status' => 'up', 'code' => $code, 'latency_ms' => $latency];
}

function check_derive_overall(array $results): string
{
    $statuses = array_column($results, 'status');
    if (in_array('down', $statuses, true))     return 'major_outage';
    if (in_array('degraded', $statuses, true)) return 'partial_outage';
    return 'operational';
}

function check_write_cache(array $config, array $payload): void
{
    $dir = $config['cache']['dir'];
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        $config['cache']['path'],
        json_encode($payload, JSON_PRETTY_PRINT)
    );
}

function check_append_json_history(array $config, array $payload): void
{
    $path     = $config['history']['path'];
    $max      = $config['history']['max_entries'];
    $existing = [];

    if (file_exists($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw)) {
            $existing = $raw;
        }
    }

    $existing[] = [
        'ts'       => $payload['checked_at'],
        'services' => $payload['services'],
    ];

    if (count($existing) > $max) {
        $existing = array_slice($existing, -$max);
    }

    file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT));
}

// ── Run checks ────────────────────────────────────────────────────────────────

$timeout    = $config['ping']['timeout'];
$ua         = $config['ping']['useragent'];
$checked_at = time();
$results    = [];

$deployed = array_values(
    array_filter($config['services'], fn($s) => !empty($s['is_deployed']))
);

foreach ($deployed as $service) {
    $results[$service['slug']] = check_ping_url($service['ping_url'], $timeout, $ua);
}

$overall = check_derive_overall($results);

$payload = [
    'checked_at' => $checked_at,
    'overall'    => $overall,
    'services'   => $results,
];

// ── Persist ───────────────────────────────────────────────────────────────────

// 1. Always write the current-status cache (used by index.php for fast page loads)
check_write_cache($config, $payload);

// 2. Persist history — DB if configured, otherwise JSON fallback
$db_driver = $config['db']['driver'] ?? 'none';

if ($db_driver !== 'none') {
    try {
        $pdo = db_connect($config);
        db_insert_check($pdo, $checked_at, $overall, $results);
        db_prune($pdo, (int) ($config['db']['keep_days'] ?? 30));
    } catch (\Throwable $e) {
        // DB failed — fall back to JSON so we never lose a data point
        error_log('[xps-check] DB error: ' . $e->getMessage() . ' — falling back to JSON');
        check_append_json_history($config, $payload);
    }
} else {
    check_append_json_history($config, $payload);
}

// ── Output (CLI or HTTP) ──────────────────────────────────────────────────────

if ($is_cli) {
    $ts = date('Y-m-d H:i:s', $checked_at);
    echo "[{$ts}] overall={$overall}\n";
    foreach ($results as $slug => $r) {
        $lat = $r['latency_ms'] !== null ? $r['latency_ms'] . 'ms' : 'n/a';
        echo "  {$slug}: {$r['status']} ({$lat})\n";
    }
} elseif (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'check.php') {
    // Called directly via HTTP — return JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'checked_at' => $checked_at,
        'overall'    => $overall,
        'services'   => $results,
    ], JSON_PRETTY_PRINT);
    exit;
}

// When require'd from index.php: $payload is available to the caller
