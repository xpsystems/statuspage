<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$e   = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$uri = '/' . trim($uri, '/');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Returns only services that are marked as deployed (is_deployed === true).
 * Services without the key, or with is_deployed set to false, are excluded.
 */
function deployed_services(array $config): array
{
    return array_values(
        array_filter($config['services'], fn($s) => !empty($s['is_deployed']))
    );
}

function get_cached_status(array $config): array
{
    $cache_file = $config['cache']['path'];
    $cache_dir  = $config['cache']['dir'];

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    if (file_exists($cache_file)) {
        $age = time() - (int) filemtime($cache_file);
        if ($age < $config['cache']['ttl']) {
            $data = json_decode((string) file_get_contents($cache_file), true);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    return refresh_status($config);
}

function refresh_status(array $config): array
{
    $results = [];
    $timeout = $config['ping']['timeout'];
    $ua      = $config['ping']['useragent'];

    // Only ping services that are deployed.
    foreach (deployed_services($config) as $service) {
        $results[$service['slug']] = ping_url(
            $service['ping_url'],
            $timeout,
            $ua
        );
    }

    $payload = [
        'checked_at' => time(),
        'services'   => $results,
    ];

    file_put_contents(
        $config['cache']['path'],
        json_encode($payload, JSON_PRETTY_PRINT)
    );

    return $payload;
}

function ping_url(string $url, int $timeout, string $ua): array
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
    curl_close($ch);

    $latency = (int) round((microtime(true) - $start) * 1000);

    if ($err !== 0 || $code === 0) {
        return ['status' => 'down', 'code' => null, 'latency_ms' => $latency];
    }

    if ($code >= 500) {
        return ['status' => 'degraded', 'code' => $code, 'latency_ms' => $latency];
    }

    return ['status' => 'up', 'code' => $code, 'latency_ms' => $latency];
}

function build_full_status(array $config): array
{
    $cached     = get_cached_status($config);
    $raw        = $cached['services'] ?? [];
    $checked_at = $cached['checked_at'] ?? time();

    $services      = [];
    $not_deployed  = [];

    // Deployed services — carry ping results and participate in overall status.
    foreach (deployed_services($config) as $svc) {
        $result     = $raw[$svc['slug']] ?? ['status' => 'unknown', 'code' => null, 'latency_ms' => null];
        $services[] = [
            'slug'        => $svc['slug'],
            'name'        => $svc['name'],
            'group'       => $svc['group'],
            'url'         => $svc['url'],
            'is_deployed' => true,
            'status'      => $result['status'],
            'http_code'   => $result['code'],
            'latency_ms'  => $result['latency_ms'],
        ];
    }

    // Non-deployed services — shown in the UI as "Not deployed"; never affect overall status.
    foreach ($config['services'] as $svc) {
        if (!empty($svc['is_deployed'])) {
            continue;
        }
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

    // Overall status is derived only from deployed services.
    $statuses       = array_column($services, 'status');
    $down_count     = count(array_filter($statuses, fn($s) => $s === 'down'));
    $degraded_count = count(array_filter($statuses, fn($s) => $s === 'degraded'));

    if ($down_count > 0) {
        $overall = 'major_outage';
    } elseif ($degraded_count > 0) {
        $overall = 'partial_outage';
    } else {
        $overall = 'operational';
    }

    return [
        'overall'    => $overall,
        'checked_at' => $checked_at,
        'services'   => array_merge($services, $not_deployed),
    ];
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
<link rel="stylesheet" href="/style.css">
</head>
<body>

<header class="nav-header">
  <div class="nav-inner">
    <a href="<?= $e($config['site']['org_url']) ?>" class="nav-logo" target="_blank" rel="noopener noreferrer">
      <?= $e($config['site']['org']) ?>
    </a>
    <div class="nav-right">
      <!-- Theme toggle: system / dark / light -->
      <!--div class="theme-toggle" role="group" aria-label="Color theme">
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
      </div-->

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
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="15" y1="9" x2="9" y2="15"/>
          <line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
        <?php endif; ?>
      </div>
      <div class="hero-bar-text">
        <h1 class="hero-bar-title">
          <?php if ($overall === 'operational'): ?>
            All Systems Operational
          <?php elseif ($overall === 'partial_outage'): ?>
            Partial System Outage
          <?php else: ?>
            Major System Outage
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
          <div
            class="service-row<?= !$svc['is_deployed'] ? ' service-row--not-deployed' : '' ?>"
            data-slug="<?= $e($svc['slug']) ?>"
            data-status="<?= $e($svc['status']) ?>"
          >
            <div class="service-row-left">
              <span class="status-indicator status-indicator--<?= $e($svc['status']) ?>" aria-hidden="true"></span>
              <a
                href="<?= $e($svc['url']) ?>"
                class="service-name"
                target="_blank"
                rel="noopener noreferrer"
              ><?= $e($svc['name']) ?></a>
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
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="section-divider">
    <svg viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true">
      <path d="M0,0 Q360,60 720,30 Q1080,0 1440,60 L1440,60 L0,60 Z" fill="#0d0d14"/>
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
    <div class="footer-left">
      <span class="footer-logo"><?= $e($config['site']['org']) ?></span>
      <span class="footer-copy">
        &copy; <?= date('Y') ?> <?= $e($config['site']['org']) ?>. All rights reserved.
      </span>
    </div>
    <div class="footer-right">
      <a href="<?= $e($config['site']['mtex_status']) ?>" class="footer-link" target="_blank" rel="noopener noreferrer">
        MTEX Status
      </a>
      <a href="<?= $e($config['site']['github_url']) ?>" class="footer-link" target="_blank" rel="noopener noreferrer">
        GitHub
      </a>
      <a href="https://fabianternis.dev" class="footer-link" target="_blank" rel="noopener noreferrer">
        Fabian Ternis
      </a>
      <!-- Theme toggle: system / dark / light -->
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
      <span class="footer-version">v<?= $e($config['site']['version']) ?></span>
    </div>
  </div>
</footer>

<script
  src="/script.js"
  defer
  data-api-base="<?= $e($api_base) ?>"
  data-playground="<?= $e($playground_base) ?>"
></script>
</body>
</html>