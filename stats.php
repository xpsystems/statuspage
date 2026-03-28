<?php
declare(strict_types=1);

/**
 * stats.php — xpsystems statuspage
 *
 * Pure calculation functions for uptime/outage stats.
 * Work on any array of check rows — DB result or JSON history.
 *
 * Expected row shape (from db_history_for_slug / history_for_slug):
 *   ['ts' => int, 'status' => string, 'latency_ms' => int|null, ...]
 */

/**
 * Aggregate an array of check rows into per-day buckets.
 *
 * @param  array  $rows   Check rows with 'ts', 'status', 'latency_ms'
 * @param  int    $days   How many calendar days to cover (default 90)
 * @return array  One entry per day, oldest first:
 *   date, total_checks, up_checks, down_checks, degraded_checks,
 *   uptime_pct, outage_pct, avg_latency_ms, had_outage, had_degraded
 */
function stats_days(array $rows, int $days = 90): array
{
    // Group rows by UTC date
    $by_day = [];
    foreach ($rows as $row) {
        $date = gmdate('Y-m-d', (int) $row['ts']);
        $by_day[$date][] = $row;
    }

    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date   = gmdate('Y-m-d', time() - $i * 86400);
        $checks = $by_day[$date] ?? [];

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

        $latencies = array_filter(
            array_map(fn($r) => $r['status'] !== 'down' ? ($r['latency_ms'] ?? null) : null, $checks),
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
 * Overall uptime stats across all rows.
 *
 * @param  array $rows  Check rows with 'ts', 'status', 'latency_ms'
 * @return array
 *   uptime_pct, outage_pct, avg_latency_ms, total_checks, days_with_outage
 */
function stats_summary(array $rows): array
{
    if (empty($rows)) {
        return [
            'uptime_pct'       => null,
            'outage_pct'       => null,
            'avg_latency_ms'   => null,
            'total_checks'     => 0,
            'days_with_outage' => 0,
        ];
    }

    $total    = count($rows);
    $up       = count(array_filter($rows, fn($r) => $r['status'] === 'up'));
    $down     = count(array_filter($rows, fn($r) => $r['status'] === 'down'));

    $latencies = array_filter(
        array_map(fn($r) => $r['status'] !== 'down' ? ($r['latency_ms'] ?? null) : null, $rows),
        fn($v) => $v !== null
    );

    $outage_days = count(array_unique(
        array_map(
            fn($r) => gmdate('Y-m-d', (int) $r['ts']),
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

/**
 * Classify a day entry (from stats_days) into a CSS status class.
 * Used for coloring the 90-day uptime bar.
 */
function stats_day_status(array $day): string
{
    if ($day['total_checks'] === 0)  return 'unknown';
    if ($day['had_outage'])          return 'down';
    if ($day['had_degraded'])        return 'degraded';
    return 'up';
}

/**
 * Compute a summary from already-aggregated day entries (output of stats_days).
 * Avoids re-fetching raw rows when you already have the day buckets.
 */
function stats_summary_from_days(array $days): array
{
    $total    = array_sum(array_column($days, 'total_checks'));
    $up       = array_sum(array_column($days, 'up_checks'));
    $down     = array_sum(array_column($days, 'down_checks'));

    if ($total === 0) {
        return [
            'uptime_pct'       => null,
            'outage_pct'       => null,
            'avg_latency_ms'   => null,
            'total_checks'     => 0,
            'days_with_outage' => 0,
        ];
    }

    // Weighted average latency across days (weight = non-down checks per day)
    $lat_sum    = 0;
    $lat_weight = 0;
    foreach ($days as $d) {
        $non_down = $d['total_checks'] - $d['down_checks'];
        if ($non_down > 0 && $d['avg_latency_ms'] !== null) {
            $lat_sum    += $d['avg_latency_ms'] * $non_down;
            $lat_weight += $non_down;
        }
    }

    return [
        'uptime_pct'       => round($up   / $total * 100, 3),
        'outage_pct'       => round($down / $total * 100, 3),
        'avg_latency_ms'   => $lat_weight > 0 ? (int) round($lat_sum / $lat_weight) : null,
        'total_checks'     => $total,
        'days_with_outage' => count(array_filter($days, fn($d) => $d['had_outage'])),
    ];
}
