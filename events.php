<?php
declare(strict_types=1);

/**
 * events.php — Server-Sent Events stream
 * Routed via /events (htaccess) or included directly from index.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// ── SSE headers ───────────────────────────────────────────────────────────────

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

ignore_user_abort(true);
set_time_limit(0);

while (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

// ── Helpers (prefixed to avoid collisions when included from index.php) ───────

if (!function_exists('sse_send')) {
    function sse_send(string $event, mixed $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    function sse_comment(string $text): void
    {
        echo ": {$text}\n\n";
        flush();
    }

    function sse_build_payload(array $config): array
    {
        $raw        = [];
        $checked_at = time();

        if (file_exists($config['cache']['path'])) {
            $data = json_decode((string) file_get_contents($config['cache']['path']), true);
            if (is_array($data)) {
                $raw        = $data['services'] ?? [];
                $checked_at = $data['checked_at'] ?? time();
            }
        }

        $services     = [];
        $not_deployed = [];

        foreach ($config['services'] as $svc) {
            if (!empty($svc['is_deployed'])) {
                $r          = $raw[$svc['slug']] ?? ['status' => 'unknown', 'code' => null, 'latency_ms' => null];
                $services[] = [
                    'slug'        => $svc['slug'],
                    'name'        => $svc['name'],
                    'group'       => $svc['group'],
                    'is_deployed' => true,
                    'status'      => $r['status'],
                    'http_code'   => $r['code'] ?? null,
                    'latency_ms'  => $r['latency_ms'],
                ];
            } else {
                $not_deployed[] = [
                    'slug'        => $svc['slug'],
                    'name'        => $svc['name'],
                    'group'       => $svc['group'],
                    'is_deployed' => false,
                    'status'      => 'not_deployed',
                    'http_code'   => null,
                    'latency_ms'  => null,
                ];
            }
        }

        $statuses = array_column($services, 'status');
        $overall  = match(true) {
            in_array('down',     $statuses, true) => 'major_outage',
            in_array('degraded', $statuses, true) => 'partial_outage',
            default => empty($services) ? 'unknown' : 'operational',
        };

        return [
            'overall'    => $overall,
            'checked_at' => $checked_at,
            'services'   => [...$services, ...$not_deployed],
        ];
    }

    function sse_ping_url(string $url, int $timeout, string $ua): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'unknown', 'code' => null, 'latency_ms' => null];
        }
        $start = microtime(true);
        $ch    = curl_init();
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
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_errno($ch);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($err !== 0 || $code === 0) return ['status' => 'down',     'code' => null,  'latency_ms' => $latency];
        if ($code >= 500)              return ['status' => 'degraded', 'code' => $code, 'latency_ms' => $latency];
        return                                ['status' => 'up',       'code' => $code, 'latency_ms' => $latency];
    }

    function sse_run_check(array $config): void
    {
        $timeout    = $config['ping']['timeout'];
        $ua         = $config['ping']['useragent'];
        $checked_at = time();
        $results    = [];

        foreach ($config['services'] as $svc) {
            if (empty($svc['is_deployed'])) continue;
            $results[$svc['slug']] = sse_ping_url($svc['ping_url'], $timeout, $ua);
        }

        $statuses = array_column($results, 'status');
        $overall  = match(true) {
            in_array('down',     $statuses, true) => 'major_outage',
            in_array('degraded', $statuses, true) => 'partial_outage',
            default                               => 'operational',
        };

        $payload = ['checked_at' => $checked_at, 'overall' => $overall, 'services' => $results];

        $dir = $config['cache']['dir'];
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($config['cache']['path'], json_encode($payload, JSON_PRETTY_PRINT));

        $db_driver = $config['db']['driver'] ?? 'none';
        if ($db_driver !== 'none') {
            try {
                $pdo = db_connect($config);
                db_insert_check($pdo, $checked_at, $overall, $results);
                db_prune($pdo, (int) ($config['db']['keep_days'] ?? 30));
                return;
            } catch (\Throwable $ex) {
                error_log('[xps-events] DB error: ' . $ex->getMessage());
            }
        }

        $path     = $config['history']['path'];
        $max      = $config['history']['max_entries'];
        $existing = [];
        if (file_exists($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) $existing = $raw;
        }
        $existing[] = ['ts' => $checked_at, 'services' => $results];
        if (count($existing) > $max) $existing = array_slice($existing, -$max);
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT));
    }
}

// ── Stream loop ───────────────────────────────────────────────────────────────

$sse_poll     = 30;
$sse_max      = 180;
$sse_hb       = 25;
$started_at   = time();
$last_check   = 0;
$last_hb      = 0;

sse_send('status', sse_build_payload($config));

while (true) {
    $now = time();

    if ($now - $started_at >= $sse_max) { sse_comment('reconnect'); break; }
    if (connection_aborted()) break;

    if ($now - $last_hb >= $sse_hb) {
        sse_send('heartbeat', ['ts' => $now]);
        $last_hb = $now;
    }

    if ($now - $last_check >= $sse_poll) {
        sse_send('checking', ['checking' => true]);
        sse_run_check($config);
        $last_check = time();
        sse_send('status', sse_build_payload($config));
    }

    sleep(1);
}
