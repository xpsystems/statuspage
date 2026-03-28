<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/stats.php';

$e   = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$uri = '/' . trim($uri, '/');

// ── Helpers ───────────────────────────────────────────────────────────────────

function deployed_services(array $config): array
{
    return array_values(
        array_filter($config['services'], fn($s) => !empty($s['is_deployed']))
    );
}

/**
 * Returns current status from cache immediately (never blocks).
 * If the cache is stale, triggers a background check via check.php
 * so the next page load (or SSE push) will have fresh data.
 */
function get_cached_status(array $config): array
{
    $cache_file = $config['cache']['path'];
    $cache_dir  = $config['cache']['dir'];

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    $data = null;
    $stale = true;

    if (file_exists($cache_file)) {
        $raw = json_decode((string) file_get_contents($cache_file), true);
        if (is_array($raw)) {
            $data  = $raw;
            $age   = time() - (int) filemtime($cache_file);
            $stale = $age >= $config['cache']['ttl'];
        }
    }

    // If stale (or missing), kick off a background check so the NEXT
    // request gets fresh data — but don't wait for it now.
    if ($stale) {
        trigger_background_check($config);
    }

    // Return whatever we have — even stale data is better than blocking.
    return $data ?? ['checked_at' => time(), 'services' => [], 'overall' => 'unknown'];
}

/**
 * Fires check.php in the background without blocking the current request.
 * Uses fastcgi_finish_request() if available (FPM), otherwise a non-blocking
 * shell exec as fallback.
 */
function trigger_background_check(array $config): void
{
    // Lock file prevents multiple concurrent background checks
    $lock = $config['cache']['dir'] . '/check.lock';
    if (file_exists($lock) && (time() - filemtime($lock)) < 60) {
        return; // check already running
    }
    touch($lock);

    if (function_exists('fastcgi_finish_request')) {
        // FPM: finish the HTTP response, then run the check in the same process
        register_shutdown_function(function () use ($config, $lock) {
            fastcgi_finish_request();
            require_once __DIR__ . '/check.php';
            @unlink($lock);
        });
    } else {
        // Non-FPM: fire a detached background process
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/check.php');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$cmd}", 'r'));
        } else {
            exec("{$cmd} > /dev/null 2>&1 & echo \$!", $out);
        }
        // Remove lock after a moment (background process will overwrite cache)
        register_shutdown_function(fn() => @unlink($lock));
    }
}

// ── History helpers (DB-aware) ────────────────────────────────────────────────

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
    // JSON fallback
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

/**
 * Returns 90-day aggregated data for a slug.
 * Uses DB if available, falls back to JSON history via stats_days().
 */
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
    // JSON fallback — load all history and aggregate via stats.php
    $rows = history_for_slug(load_history($config), $slug, 99999);
    return stats_days($rows, $days);
}
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

    // If cache was empty (no services resolved), keep overall as 'unknown'
    // so the page doesn't falsely show "Major System Outage" on first load.
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

// ── SSE route (/events) ───────────────────────────────────────────────────────

if ($uri === '/events') {
    require __DIR__ . '/events.php';
    exit;
}

// ── API routes ────────────────────────────────────────────────────────────────

if (str_starts_with($uri, '/api')) {
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

    if ($uri === '/api/ping') {
        echo json_encode([
            'pong'      => true,
            'timestamp' => time(),
            'server'    => 'status.xpsystems.eu',
        ], JSON_PRETTY_PRINT);
        exit;
    }

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

    if ($uri === '/api/services') {
        $data = build_full_status($config);
        echo json_encode([
            'checked_at' => $data['checked_at'],
            'services'   => $data['services'],
        ], JSON_PRETTY_PRINT);
        exit;
    }

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

    if ($uri === '/api/history') {
        $limit = min((int) ($_GET['limit'] ?? 90), 1440);
        $driver = $config['db']['driver'] ?? 'none';
        if ($driver !== 'none') {
            try {
                $pdo = db_connect($config);
                $out = db_history_full($pdo, $limit);
            } catch (\Throwable $ex) {
                error_log('[xps-api] DB error: ' . $ex->getMessage());
                $history = load_history($config);
                $out     = array_slice($history, -$limit);
            }
        } else {
            $history = load_history($config);
            $out     = array_slice($history, -$limit);
        }
        echo json_encode(['count' => count($out), 'entries' => $out], JSON_PRETTY_PRINT);
        exit;
    }

    if (preg_match('#^/api/history/([a-z0-9\-]+)$#', $uri, $m)) {
        $slug  = $m[1];
        $days  = min((int) ($_GET['days'] ?? 90), 3650);
        $rows  = history_for_slug_db($config, $slug, 99999);
        if (empty($rows)) {
            http_response_code(404);
            echo json_encode(['error' => 'No history found for slug', 'slug' => $slug], JSON_PRETTY_PRINT);
            exit;
        }
        // Filter to requested window
        $since = time() - ($days * 86400);
        $rows  = array_values(array_filter($rows, fn($r) => $r['ts'] >= $since));
        echo json_encode([
            'slug'    => $slug,
            'days'    => $days,
            'stats'   => stats_summary($rows),
            'by_day'  => stats_days($rows, $days),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // /api/day/{slug}/{date}  — all checks for a slug on a specific UTC date
    if (preg_match('#^/api/day/([a-z0-9\-]+)/(\d{4}-\d{2}-\d{2})$#', $uri, $m)) {
        $slug = $m[1];
        $date = $m[2];
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
                'ts'         => (int)$r['ts'],
                'time'       => gmdate('H:i:s', (int)$r['ts']),
                'status'     => $r['status'],
                'latency_ms' => $r['latency_ms'],
                'http_code'  => $r['http_code'],
            ], $rows),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(404);
    echo json_encode([
        'error'     => 'Unknown API endpoint',
        'available' => array_map(
            fn($ep) => $ep['method'] . ' ' . $ep['path'],
            $config['api_endpoints']
        ),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── HTML page ─────────────────────────────────────────────────────────────────

$full_data  = build_full_status($config);
$overall    = $full_data['overall'];
$services   = $full_data['services'];
$checked_at = $full_data['checked_at'];

$services_by_group = [];
foreach ($config['groups'] as $group) {
    $services_by_group[$group] = array_values(
        array_filter($services, fn($s) => $s['group'] === $group)
    );
}

// Load 90-day aggregated history per deployed service (for uptime bars)
$svc_history = [];
foreach (deployed_services($config) as $svc) {
    $svc_history[$svc['slug']] = days_for_slug_db($config, $svc['slug'], 90);
}

$playground_base = $config['site']['playground'];
$api_base        = $config['site']['api_base'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Live infrastructure status for xpsystems services.">
<meta name="robots" content="index, follow">
<title>Status — <?= $e($config['site']['org']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<script>
// Inline theme init — prevents flash of wrong theme
(function(){
  try {
    var s = localStorage.getItem('xps-theme') || 'system';
    var r = s === 'system' ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : s;
    document.documentElement.setAttribute('data-theme', r);
  } catch(e) {}
})();
</script>
</head>
<body>

<header class="nav-header">
  <div class="nav-inner">
    <a href="<?= $e($config['site']['org_url']) ?>" class="nav-logo" target="_blank" rel="noopener noreferrer">
      <?= $e($config['site']['org']) ?>
    </a>
    <div class="nav-right">
      <div class="theme-toggle" role="group" aria-label="Color theme">
        <button class="theme-btn" data-theme="system" title="System theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
        </button>
        <button class="theme-btn" data-theme="dark" title="Dark theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
          </svg>
        </button>
        <button class="theme-btn" data-theme="light" title="Light theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
            <line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
          </svg>
        </button>
      </div>

      <a
        href="<?= $e($config['site']['mtex_status']) ?>"
        class="nav-ext-link"
        target="_blank"
        rel="noopener noreferrer"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="nav-icon">
          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
          <polyline points="15 3 21 3 21 9"/>
          <line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
        MTEX Status
      </a>
      <a
        href="<?= $e($config['site']['github_url']) ?>"
        class="nav-ext-link"
        target="_blank"
        rel="noopener noreferrer"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="nav-icon">
          <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
        </svg>
        GitHub
      </a>
    </div>
  </div>
</header>

<main>

  <section class="hero-bar hero-bar--<?= $e($overall) ?>">
    <div class="container hero-bar-inner">
      <div class="hero-bar-icon" aria-hidden="true">
        <?php if ($overall === 'operational'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
        <?php elseif ($overall === 'partial_outage'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <?php elseif ($overall === 'major_outage'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="15" y1="9" x2="9" y2="15"/>
          <line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?php endif; ?>
      </div>
      <div class="hero-bar-text">
        <h1 class="hero-bar-title">
          <?php if ($overall === 'operational'): ?>
            All Systems Operational
          <?php elseif ($overall === 'partial_outage'): ?>
            Partial System Outage
          <?php elseif ($overall === 'major_outage'): ?>
            Major System Outage
          <?php else: ?>
            Checking status&hellip;
          <?php endif; ?>
        </h1>
        <p class="hero-bar-sub">
          Last checked
          <time datetime="<?= date('c', $checked_at) ?>" id="checked-time">
            <?= $e(date('Y-m-d H:i', $checked_at)) ?> UTC
          </time>
          &mdash;
          <button class="refresh-btn" id="refresh-btn" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="icon-refresh" id="icon-refresh">
              <polyline points="23 4 23 10 17 10"/>
              <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            Refresh
          </button>
        </p>
      </div>
    </div>
  </section>

  <section class="services-section">
    <div class="container" id="services-container">
      <?php foreach ($config['groups'] as $group): ?>
      <?php $group_services = $services_by_group[$group] ?? []; ?>
      <?php if (empty($group_services)) continue; ?>
      <div class="service-group reveal" id="group-<?= $e(strtolower($group)) ?>">
        <h2 class="group-title"><?= $e($group) ?></h2>
        <div class="service-list">
          <?php foreach ($group_services as $svc): ?>
          <?php
            $days_hist = $svc['is_deployed'] ? ($svc_history[$svc['slug']] ?? []) : [];
            $summary   = !empty($days_hist) ? stats_summary_from_days($days_hist) : null;
            $uptime    = $summary['uptime_pct']     ?? null;
            $avg_lat   = $summary['avg_latency_ms'] ?? null;
          ?>
          <div
            class="service-row<?= !$svc['is_deployed'] ? ' service-row--not-deployed' : '' ?>"
            data-slug="<?= $e($svc['slug']) ?>"
            data-status="<?= $e($svc['status']) ?>"
          >
            <div class="service-row-main">
              <div class="service-row-left">
                <span class="status-indicator status-indicator--<?= $e($svc['status']) ?>" aria-hidden="true"></span>
                <a href="<?= $e($svc['url']) ?>" class="service-name" target="_blank" rel="noopener noreferrer"><?= $e($svc['name']) ?></a>
              </div>
              <div class="service-row-right">
                <?php if ($svc['latency_ms'] !== null): ?>
                <span class="service-latency"><?= (int) $svc['latency_ms'] ?>ms</span>
                <?php endif; ?>
                <span class="service-status-label service-status-label--<?= $e($svc['status']) ?>">
                  <?= match($svc['status']) {
                      'up'           => 'Operational',
                      'degraded'     => 'Degraded',
                      'down'         => 'Outage',
                      'not_deployed' => 'Not Deployed',
                      default        => 'Unknown',
                  } ?>
                </span>
                <?php if ($svc['http_code'] !== null): ?>
                <span class="service-code">HTTP <?= (int) $svc['http_code'] ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($svc['is_deployed'] && count($days_hist) > 0): ?>
            <div class="service-history">
              <div class="uptime-bar" aria-label="90-day uptime history">
                <?php foreach ($days_hist as $d): ?>
                <?php
                  $ds        = stats_day_status($d);
                  $day_label = isset($d['date']) ? $d['date'] : (isset($d['ts']) ? gmdate('Y-m-d', (int)$d['ts']) : '');
                  $status_labels = [
                    'up'              => 'Operational',
                    'degraded'        => 'Degraded',
                    'outage-minor'    => 'Minor Outage',
                    'outage-major'    => 'Major Outage',
                    'outage-critical' => 'Critical Outage',
                    'unknown'         => 'No data',
                  ];
                  $tip_status    = $status_labels[$ds] ?? ucfirst($ds);
                  $total_checks  = (int)($d['total_checks'] ?? 0);
                  $avg_lat       = isset($d['avg_latency_ms']) && $d['avg_latency_ms'] !== null ? (int)$d['avg_latency_ms'] : null;
                  $uptime_pct    = isset($d['uptime_pct'])    && $d['uptime_pct']    !== null ? (float)$d['uptime_pct']    : null;
                  // Real downtime from actual timestamps
                  $down_secs     = (int)($d['down_secs']     ?? 0);
                  $degraded_secs = (int)($d['degraded_secs'] ?? 0);
                ?>
                <span class="uptime-tick uptime-tick--<?= $e($ds) ?>"
                  data-tip-date="<?= $e($day_label) ?>"
                  data-tip-status="<?= $e($tip_status) ?>"
                  data-tip-status-cls="<?= $e($ds) ?>"
                  data-tip-uptime="<?= $uptime_pct !== null ? $uptime_pct : '' ?>"
                  data-tip-lat="<?= $avg_lat !== null ? $avg_lat : '' ?>"
                  data-tip-down-secs="<?= $down_secs ?>"
                  data-tip-deg-secs="<?= $degraded_secs ?>"
                  data-tip-total="<?= $total_checks ?>"
                  data-slug="<?= $e($svc['slug']) ?>"
                  data-date="<?= $e($day_label) ?>"
                ></span>
                <?php endforeach; ?>
              </div>
              <div class="uptime-meta">
                <?php if ($uptime !== null): ?>
                <span class="uptime-pct"><?= $uptime ?>% uptime</span>
                <?php endif; ?>
                <?php if ($avg_lat !== null): ?>
                <span class="uptime-avg">avg <?= $avg_lat ?>ms</span>
                <?php endif; ?>
                <span class="uptime-window">90 days</span>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="section-divider">
    <svg viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true">
      <path d="M0,0 Q360,60 720,30 Q1080,0 1440,60 L1440,60 L0,60 Z" class="divider-fill-alt"/>
    </svg>
  </div>

  <section class="api-section" id="api">
    <div class="container">
      <div class="section-header reveal">
        <h2 class="section-title">
          Status API
          <span class="heading-accent-wrap">
            <svg class="heading-accent-svg" viewBox="0 0 220 10" preserveAspectRatio="none" aria-hidden="true">
              <path d="M0,7 Q55,1 110,6 Q165,11 220,4" stroke="#4f8ef7" stroke-width="2" fill="none" stroke-linecap="round"/>
            </svg>
          </span>
        </h2>
        <p class="section-subtitle">
          Open, unauthenticated JSON API. No keys. No rate limits. Free to use.
        </p>
      </div>

      <div class="api-grid reveal">
        <?php foreach ($config['api_endpoints'] as $ep): ?>
        <?php
            $is_parameterized = str_contains($ep['path'], '{');
            $playground_url   = '';
            if (!$is_parameterized) {
                $full_url       = $api_base . $ep['path'];
                $playground_url = $playground_base . '?url=' . rawurlencode($full_url);
            }
        ?>
        <div class="api-card">
          <div class="api-card-top">
            <span class="api-method"><?= $e($ep['method']) ?></span>
            <code class="api-path"><?= $e($ep['path']) ?></code>
          </div>
          <p class="api-summary"><?= $e($ep['summary']) ?></p>
          <div class="api-card-actions">
            <a
              href="<?= $is_parameterized ? '#' : $e($api_base . $ep['path']) ?>"
              class="api-action-link api-action-link--view <?= $is_parameterized ? 'disabled' : '' ?>"
              <?= !$is_parameterized ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="icon-sm">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              View response
            </a>
            <?php if (!$is_parameterized): ?>
            <a
              href="<?= $e($playground_url) ?>"
              class="api-action-link api-action-link--try"
              target="_blank"
              rel="noopener noreferrer"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="icon-sm">
                <polygon points="5 3 19 12 5 21 5 3"/>
              </svg>
              Try in API Sandbox
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="api-try-banner reveal">
        <div class="api-try-banner-text">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="api-try-icon">
            <polyline points="16 18 22 12 16 6"/>
            <polyline points="8 6 2 12 8 18"/>
          </svg>
          <div>
            <strong>Try our Status API</strong>
            <span>Explore all endpoints interactively in the API Sandbox.</span>
          </div>
        </div>
        <a
          href="<?= $e($playground_base . '?url=' . rawurlencode($api_base . '/status')) ?>"
          class="btn btn-primary"
          target="_blank"
          rel="noopener noreferrer"
        >
          Open in API Sandbox
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="btn-icon">
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
            <polyline points="15 3 21 3 21 9"/>
            <line x1="10" y1="14" x2="21" y2="3"/>
          </svg>
        </a>
      </div>
    </div>
  </section>

</main>

<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-brand">
      <span class="footer-logo"><?= $e($config['site']['org']) ?></span>
      <span class="footer-copy">
        &copy; <?= date('Y') ?> <?= $e($config['site']['org']) ?>. All rights reserved.
      </span>
      <span class="footer-version">v<?= $e($config['site']['version']) ?></span>
    </div>
    <div class="footer-right">
      <nav class="footer-nav" aria-label="Footer navigation">
        <a href="<?= $e($config['site']['mtex_status']) ?>" class="footer-link" target="_blank" rel="noopener noreferrer">MTEX Status</a>
        <a href="<?= $e($config['site']['github_url']) ?>" class="footer-link" target="_blank" rel="noopener noreferrer">GitHub</a>
        <a href="https://fabianternis.dev" class="footer-link" target="_blank" rel="noopener noreferrer">Fabian Ternis</a>
      </nav>
      <div class="theme-toggle" role="group" aria-label="Color theme">
        <button class="theme-btn" data-theme="system" title="System theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
        </button>
        <button class="theme-btn" data-theme="dark" title="Dark theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
          </svg>
        </button>
        <button class="theme-btn" data-theme="light" title="Light theme" type="button">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
            <line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
          </svg>
        </button>
      </div>
    </div>
  </div>
</footer>

<?php
$cache_age = file_exists($config['cache']['path'])
    ? (time() - (int) filemtime($config['cache']['path']))
    : null;
?>
<script
  src="script.js"
  defer
  data-api-base="<?= $e($api_base) ?>"
  data-sse-url="/events"
  data-playground="<?= $e($playground_base) ?>"
  data-cache-age="<?= $cache_age !== null ? (int)$cache_age : '' ?>"
></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(function(){});
}
</script>
<div id="day-tooltip" class="day-tooltip" aria-hidden="true">
  <div class="day-tooltip-header">
    <span class="day-tooltip-date" id="day-tooltip-date"></span>
    <span class="day-tooltip-badge" id="day-tooltip-badge"></span>
  </div>
  <div class="day-tooltip-rows" id="day-tooltip-rows"></div>
</div>

<!-- Day detail drawer -->
<div id="day-drawer-backdrop" class="day-drawer-backdrop"></div>
<aside id="day-drawer" class="day-drawer" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="day-drawer-title">
  <div class="day-drawer-header">
    <div class="day-drawer-title-group">
      <span class="day-drawer-label" id="day-drawer-svc"></span>
      <h2 class="day-drawer-title" id="day-drawer-title"></h2>
    </div>
    <button class="day-drawer-close" id="day-drawer-close" aria-label="Close" type="button">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="day-drawer-body" id="day-drawer-body">
    <div class="day-drawer-loading" id="day-drawer-loading">
      <span class="day-drawer-spinner"></span>
      Loading…
    </div>
    <div id="day-drawer-content" style="display:none"></div>
    <div id="day-drawer-error" class="day-drawer-error" style="display:none"></div>
  </div>
</aside>
<script>
(function () {
  // ── Tooltip ──────────────────────────────────────────────────
  var tip      = document.getElementById('day-tooltip');
  var tipDate  = document.getElementById('day-tooltip-date');
  var tipBadge = document.getElementById('day-tooltip-badge');
  var tipRows  = document.getElementById('day-tooltip-rows');
  var STATUS_CLS = ['up','degraded','outage-minor','outage-major','outage-critical','unknown'];

  function fmtSecs(s) {
    s = parseInt(s, 10);
    if (!s || s <= 0) return null;
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    if (h > 0 && m > 0) return h + 'h ' + m + 'm';
    if (h > 0) return h + 'h';
    if (m > 0 && sec > 0) return m + 'm ' + sec + 's';
    if (m > 0) return m + 'm';
    return sec + 's';
  }

  function tipRow(label, value) {
    var el = document.createElement('div');
    el.className = 'day-tooltip-row';
    el.innerHTML = '<span class="day-tooltip-row-label">' + label + '</span>'
                 + '<span class="day-tooltip-row-value">' + value + '</span>';
    return el;
  }

  function positionTip(e) {
    var tw = tip.offsetWidth, th = tip.offsetHeight;
    var x = e.clientX - tw / 2;
    var y = e.clientY - th - 14;
    x = Math.max(8, Math.min(x, window.innerWidth - tw - 8));
    if (y < 8) y = e.clientY + 18;
    tip.style.left = x + 'px';
    tip.style.top  = y + 'px';
  }

  document.querySelectorAll('.uptime-tick[data-tip-status]').forEach(function (tick) {
    tick.addEventListener('mouseenter', function (e) {
      var date     = tick.dataset.tipDate      || '';
      var status   = tick.dataset.tipStatus    || '';
      var cls      = tick.dataset.tipStatusCls || '';
      var uptime   = tick.dataset.tipUptime    || '';
      var lat      = tick.dataset.tipLat       || '';
      var downSecs = tick.dataset.tipDownSecs  || '0';
      var degSecs  = tick.dataset.tipDegSecs   || '0';
      var total    = tick.dataset.tipTotal     || '0';

      tipDate.textContent = date;
      STATUS_CLS.forEach(function (c) { tipBadge.classList.remove('day-tooltip-badge--' + c); });
      tipBadge.textContent = status;
      if (cls) tipBadge.classList.add('day-tooltip-badge--' + cls);

      tipRows.innerHTML = '';
      if (uptime !== '') tipRows.appendChild(tipRow('Uptime', parseFloat(uptime).toFixed(2) + '%'));
      var df = fmtSecs(downSecs); if (df) tipRows.appendChild(tipRow('Downtime', df));
      var dg = fmtSecs(degSecs);  if (dg) tipRows.appendChild(tipRow('Degraded', dg));
      if (lat !== '') tipRows.appendChild(tipRow('Avg latency', lat + ' ms'));
      if (parseInt(total, 10) > 0) tipRows.appendChild(tipRow('Checks', total));

      tip.classList.add('day-tooltip--visible');
      positionTip(e);
    });
    tick.addEventListener('mousemove', positionTip);
    tick.addEventListener('mouseleave', function () { tip.classList.remove('day-tooltip--visible'); });

    // ── Click → open drawer ──────────────────────────────────
    tick.addEventListener('click', function () {
      var slug = tick.dataset.slug;
      var date = tick.dataset.date;
      if (!slug || !date) return;
      tip.classList.remove('day-tooltip--visible');
      openDrawer(slug, date, tick);
    });
  });

  // ── Drawer ───────────────────────────────────────────────────
  var drawer   = document.getElementById('day-drawer');
  var backdrop = document.getElementById('day-drawer-backdrop');
  var drawerSvc    = document.getElementById('day-drawer-svc');
  var drawerTitle  = document.getElementById('day-drawer-title');
  var drawerLoad   = document.getElementById('day-drawer-loading');
  var drawerCont   = document.getElementById('day-drawer-content');
  var drawerErr    = document.getElementById('day-drawer-error');
  var closeBtn     = document.getElementById('day-drawer-close');

  function openDrawer(slug, date, tick) {
    // Resolve service name from the parent service-row
    var row = tick.closest('.service-row');
    var svcName = row ? (row.querySelector('.service-name') || {}).textContent || slug : slug;

    drawerSvc.textContent   = svcName;
    drawerTitle.textContent = date;
    drawerLoad.style.display  = '';
    drawerCont.style.display  = 'none';
    drawerErr.style.display   = 'none';
    drawerCont.innerHTML      = '';

    drawer.classList.add('day-drawer--open');
    backdrop.classList.add('day-drawer-backdrop--visible');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    var apiBase = (document.querySelector('script[data-api-base]') || {}).dataset.apiBase || '';
    fetch(apiBase + '/api/day/' + encodeURIComponent(slug) + '/' + encodeURIComponent(date), { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : r.json().then(function (e) { throw new Error(e.error || 'Error ' + r.status); }); })
      .then(function (data) { renderDrawer(data, svcName); })
      .catch(function (err) {
        drawerLoad.style.display = 'none';
        drawerErr.style.display  = '';
        drawerErr.textContent    = err.message || 'Failed to load data.';
      });
  }

  function closeDrawer() {
    drawer.classList.remove('day-drawer--open');
    backdrop.classList.remove('day-drawer-backdrop--visible');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  closeBtn.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

  function fmtTime(ts) {
    var d = new Date(ts * 1000);
    return d.getUTCHours().toString().padStart(2,'0') + ':'
         + d.getUTCMinutes().toString().padStart(2,'0') + ':'
         + d.getUTCSeconds().toString().padStart(2,'0') + ' UTC';
  }

  function fmtDur(secs) {
    if (!secs || secs <= 0) return '—';
    var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60), s = secs % 60;
    if (h > 0 && m > 0) return h + 'h ' + m + 'm';
    if (h > 0) return h + 'h';
    if (m > 0 && s > 0) return m + 'm ' + s + 's';
    if (m > 0) return m + 'm';
    return s + 's';
  }

  var STATUS_LABEL = { up: 'Operational', degraded: 'Degraded', down: 'Outage', unknown: 'Unknown' };

  function renderDrawer(data, svcName) {
    drawerLoad.style.display = 'none';
    drawerCont.style.display = '';

    var html = '';

    // ── Summary cards ──
    html += '<div class="ddr-summary">';
    html += ddrCard('Total checks', data.total_checks);
    if (data.down_secs > 0)     html += ddrCard('Downtime',  fmtDur(data.down_secs),     'down');
    if (data.degraded_secs > 0) html += ddrCard('Degraded',  fmtDur(data.degraded_secs), 'degraded');
    html += '</div>';

    // ── Outage spans ──
    var badSpans = (data.spans || []).filter(function (s) { return s.status === 'down' || s.status === 'degraded'; });
    if (badSpans.length > 0) {
      html += '<div class="ddr-section-title">Incidents</div>';
      html += '<div class="ddr-incidents">';
      badSpans.forEach(function (sp) {
        html += '<div class="ddr-incident ddr-incident--' + sp.status + '">'
              + '<div class="ddr-incident-bar"></div>'
              + '<div class="ddr-incident-info">'
              + '<span class="ddr-incident-status">' + (STATUS_LABEL[sp.status] || sp.status) + '</span>'
              + '<span class="ddr-incident-time">' + fmtTime(sp.from) + ' → ' + fmtTime(sp.to) + '</span>'
              + '</div>'
              + '<span class="ddr-incident-dur">' + fmtDur(sp.secs) + '</span>'
              + '</div>';
      });
      html += '</div>';
    }

    // ── Timeline ──
    html += '<div class="ddr-section-title">Timeline <span class="ddr-check-count">' + data.total_checks + ' checks</span></div>';
    html += '<div class="ddr-timeline">';

    var checks = data.checks || [];
    var prevStatus = null;
    checks.forEach(function (c) {
      var changed = c.status !== prevStatus;
      prevStatus = c.status;
      html += '<div class="ddr-check ddr-check--' + c.status + (changed ? ' ddr-check--changed' : '') + '">'
            + '<span class="ddr-check-dot"></span>'
            + '<span class="ddr-check-time">' + c.time + '</span>'
            + '<span class="ddr-check-status">' + (STATUS_LABEL[c.status] || c.status) + '</span>'
            + (c.latency_ms != null ? '<span class="ddr-check-lat">' + c.latency_ms + ' ms</span>' : '')
            + (c.http_code  != null ? '<span class="ddr-check-code">HTTP ' + c.http_code + '</span>' : '')
            + '</div>';
    });

    html += '</div>';
    drawerCont.innerHTML = html;
  }

  function ddrCard(label, value, cls) {
    return '<div class="ddr-card' + (cls ? ' ddr-card--' + cls : '') + '">'
         + '<span class="ddr-card-value">' + value + '</span>'
         + '<span class="ddr-card-label">' + label + '</span>'
         + '</div>';
  }

})();
</script>
</body>
</html>