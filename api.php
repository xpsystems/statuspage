<?php
declare(strict_types=1);

/**
 * api.php — JSON API route handlers
 *
 * Included by index.php when the request URI starts with /api.
 * Requires: $config, $uri — set by index.php before inclusion.
 * Requires: helpers.php, database.php, stats.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// GET /api/ping
if ($uri === '/api/ping') {
    echo json_encode([
        'pong'      => true,
        'timestamp' => time(),
        'server'    => 'status.xpsystems.eu',
    ], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/status
if ($uri === '/api/status') {
    $data     = build_full_status($config);
    $deployed = array_filter($data['services'], fn($s) => $s['is_deployed']);
    echo json_encode([
        'overall'    => $data['overall'],
        'checked_at' => $data['checked_at'],
        'summary'    => [
            'total'        => count($deployed),
            'up'           => count(array_filter($deployed, fn($s) => $s['status'] === 'up')),
            'degraded'     => count(array_filter($deployed, fn($s) => $s['status'] === 'degraded')),
            'down'         => count(array_filter($deployed, fn($s) => $s['status'] === 'down')),
            'unknown'      => count(array_filter($deployed, fn($s) => $s['status'] === 'unknown')),
            'not_deployed' => count(array_filter($data['services'], fn($s) => !$s['is_deployed'])),
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/services
if ($uri === '/api/services') {
    $data = build_full_status($config);
    echo json_encode([
        'checked_at' => $data['checked_at'],
        'services'   => $data['services'],
    ], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/service/{slug}
if (preg_match('#^/api/service/([a-z0-9\-]+)$#', $uri, $m)) {
    $slug = $m[1];
    $data = build_full_status($config);
    foreach ($data['services'] as $svc) {
        if ($svc['slug'] === $slug) {
            echo json_encode([
                'checked_at' => $data['checked_at'],
                'service'    => $svc,
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'Service not found', 'slug' => $slug], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/history[?limit=N]
if ($uri === '/api/history') {
    $limit  = min((int) ($_GET['limit'] ?? 90), 1440);
    $driver = $config['db']['driver'] ?? 'none';
    if ($driver !== 'none') {
        try {
            $pdo = db_connect($config);
            $out = db_history_full($pdo, $limit);
        } catch (\Throwable $ex) {
            error_log('[xps-api] DB error: ' . $ex->getMessage());
            $out = array_slice(load_history($config), -$limit);
        }
    } else {
        $out = array_slice(load_history($config), -$limit);
    }
    echo json_encode(['count' => count($out), 'entries' => $out], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/history/{slug}[?days=N]
if (preg_match('#^/api/history/([a-z0-9\-]+)$#', $uri, $m)) {
    $slug  = $m[1];
    $days  = min((int) ($_GET['days'] ?? 90), 3650);
    $rows  = history_for_slug_db($config, $slug, 99999);
    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(['error' => 'No history found for slug', 'slug' => $slug], JSON_PRETTY_PRINT);
        exit;
    }
    $since = time() - ($days * 86400);
    $rows  = array_values(array_filter($rows, fn($r) => $r['ts'] >= $since));
    echo json_encode([
        'slug'   => $slug,
        'days'   => $days,
        'stats'  => stats_summary($rows),
        'by_day' => stats_days($rows, $days),
    ], JSON_PRETTY_PRINT);
    exit;
}

// GET /api/day/{slug}/{YYYY-MM-DD}
if (preg_match('#^/api/day/([a-z0-9\-]+)/(\d{4}-\d{2}-\d{2})$#', $uri, $m)) {
    $slug   = $m[1];
    $date   = $m[2];
    $driver = $config['db']['driver'] ?? 'none';
    if ($driver === 'none') {
        http_response_code(501);
        echo json_encode(['error' => 'Day detail requires a database driver (sqlite or mysql)'], JSON_PRETTY_PRINT);
        exit;
    }
    try {
        $pdo  = db_connect($config);
        $rows = db_day_detail_for_slug($pdo, $slug, $date);
    } catch (\Throwable $ex) {
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()], JSON_PRETTY_PRINT);
        exit;
    }
    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(['error' => 'No data for this slug/date', 'slug' => $slug, 'date' => $date], JSON_PRETTY_PRINT);
        exit;
    }
    $dt = db_calc_downtime($rows);
    echo json_encode([
        'slug'          => $slug,
        'date'          => $date,
        'total_checks'  => count($rows),
        'down_secs'     => $dt['down_secs'],
        'degraded_secs' => $dt['degraded_secs'],
        'spans'         => $dt['spans'],
        'checks'        => array_map(fn($r) => [
            'ts'         => (int) $r['ts'],
            'time'       => gmdate('H:i:s', (int) $r['ts']),
            'status'     => $r['status'],
            'latency_ms' => $r['latency_ms'],
            'http_code'  => $r['http_code'],
        ], $rows),
    ], JSON_PRETTY_PRINT);
    exit;
}

// 404 fallback
http_response_code(404);
echo json_encode([
    'error'     => 'Unknown API endpoint',
    'available' => array_map(
        fn($ep) => $ep['method'] . ' ' . $ep['path'],
        $config['api_endpoints']
    ),
], JSON_PRETTY_PRINT);
exit;
