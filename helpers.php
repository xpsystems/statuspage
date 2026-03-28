<?php
declare(strict_types=1);

/**
 * helpers.php — shared page helpers for index.php
 *
 * Functions for cache, history, and status aggregation.
 * Requires: config.php, database.php, stats.php
 */

function deployed_services(array $config): array
{
    return array_values(
        array_filter($config['services'], fn($s) => !empty($s['is_deployed']))
    );
}

/**
 * Returns current status from cache immediately (never blocks).
 * If the cache is stale, triggers a background check via check.php.
 */
function get_cached_status(array $config): array
{
    $cache_file = $config['cache']['path'];
    $cache_dir  = $config['cache']['dir'];

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    $data  = null;
    $stale = true;

    if (file_exists($cache_file)) {
        $raw = json_decode((string) file_get_contents($cache_file), true);
        if (is_array($raw)) {
            $data  = $raw;
            $age   = time() - (int) filemtime($cache_file);
            $stale = $age >= $config['cache']['ttl'];
        }
    }

    if ($stale) {
        trigger_background_check($config);
    }

    return $data ?? ['checked_at' => time(), 'services' => [], 'overall' => 'unknown'];
}

/**
 * Fires check.php in the background without blocking the current request.
 */
function trigger_background_check(array $config): void
{
    $lock = $config['cache']['dir'] . '/check.lock';
    if (file_exists($lock) && (time() - filemtime($lock)) < 60) {
        return;
    }
    touch($lock);

    if (function_exists('fastcgi_finish_request')) {
        register_shutdown_function(function () use ($config, $lock) {
            fastcgi_finish_request();
            require_once __DIR__ . '/check.php';
            @unlink($lock);
        });
    } else {
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/check.php');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$cmd}", 'r'));
        } else {
            exec("{$cmd} > /dev/null 2>&1 & echo \$!", $out);
        }
        register_shutdown_function(fn() => @unlink($lock));
    }
}

// ── History helpers ───────────────────────────────────────────────────────────

function load_history(array $config): array
{
    $driver = $config['db']['driver'] ?? 'none';
    if ($driver !== 'none') {
        try {
            $pdo = db_connect($config);
            return db_history_full($pdo, 1440);
        } catch (\Throwable $e) {
            error_log('[xps-index] DB error: ' . $e->getMessage());
        }
    }
    $path = $config['history']['path'];
    if (!file_exists($path)) return [];
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function history_for_slug(array $history, string $slug, int $limit = 90): array
{
    $out = [];
    foreach ($history as $entry) {
        $svc = $entry['services'][$slug] ?? null;
        if ($svc === null) continue;
        $out[] = [
            'ts'         => (int) $entry['ts'],
            'status'     => $svc['status']     ?? 'unknown',
            'latency_ms' => $svc['latency_ms'] ?? null,
            'http_code'  => $svc['http_code']  ?? $svc['code'] ?? null,
        ];
    }
    return array_slice($out, -$limit);
}

function history_for_slug_db(array $config, string $slug, int $limit = 90): array
{
    $driver = $config['db']['driver'] ?? 'none';
    if ($driver !== 'none') {
        try {
            $pdo = db_connect($config);
            return db_history_for_slug($pdo, $slug, $limit);
        } catch (\Throwable $e) {
            error_log('[xps-index] DB error: ' . $e->getMessage());
        }
    }
    return history_for_slug(load_history($config), $slug, $limit);
}

function days_for_slug_db(array $config, string $slug, int $days = 90): array
{
    $driver = $config['db']['driver'] ?? 'none';
    if ($driver !== 'none') {
        try {
            $pdo = db_connect($config);
            return db_days_for_slug($pdo, $slug, $days);
        } catch (\Throwable $e) {
            error_log('[xps-index] DB error: ' . $e->getMessage());
        }
    }
    $rows = history_for_slug(load_history($config), $slug, 99999);
    return stats_days($rows, $days);
}

// ── Status aggregation ────────────────────────────────────────────────────────

function build_full_status(array $config): array
{
    $cached     = get_cached_status($config);
    $raw        = $cached['services'] ?? [];
    $checked_at = $cached['checked_at'] ?? time();

    $services     = [];
    $not_deployed = [];

    foreach (deployed_services($config) as $svc) {
        $result     = $raw[$svc['slug']] ?? ['status' => 'unknown', 'code' => null, 'latency_ms' => null];
        $services[] = [
            'slug'        => $svc['slug'],
            'name'        => $svc['name'],
            'group'       => $svc['group'],
            'url'         => $svc['url'],
            'is_deployed' => true,
            'status'      => $result['status'],
            'http_code'   => $result['code'] ?? null,
            'latency_ms'  => $result['latency_ms'],
        ];
    }

    foreach ($config['services'] as $svc) {
        if (!empty($svc['is_deployed'])) continue;
        $not_deployed[] = [
            'slug'        => $svc['slug'],
            'name'        => $svc['name'],
            'group'       => $svc['group'],
            'url'         => $svc['url'],
            'is_deployed' => false,
            'status'      => 'not_deployed',
            'http_code'   => null,
            'latency_ms'  => null,
        ];
    }

    $statuses       = array_column($services, 'status');
    $down_count     = count(array_filter($statuses, fn($s) => $s === 'down'));
    $degraded_count = count(array_filter($statuses, fn($s) => $s === 'degraded'));

    if (empty($services)) {
        $overall = $cached['overall'] ?? 'unknown';
    } else {
        $overall = match(true) {
            $down_count > 0     => 'major_outage',
            $degraded_count > 0 => 'partial_outage',
            default             => 'operational',
        };
    }

    return [
        'overall'    => $overall,
        'checked_at' => $checked_at,
        'services'   => [...$services, ...$not_deployed],
    ];
}
