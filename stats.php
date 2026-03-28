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
 * Calculate real downtime seconds from a set of check rows for a given status.
 * Rows must have a 'ts' or 'checked_at' key (unix timestamp), sorted ASC.
 */
function stats_calc_downtime_secs(array $rows, string $target_status): int
{
    if (empty($rows)) return 0;

    // Normalise timestamp key
    $ts_key = isset($rows[0]['ts']) ? 'ts' : 'checked_at';

    // Estimate median check interval
    $gaps = [];
    $n    = count($rows);
    for ($i = 1; $i < $n; $i++) {
        $gap = (int)$rows[$i][$ts_key] - (int)$rows[$i - 1][$ts_key];
        if ($gap > 0) $gaps[] = $gap;
    }
    sort($gaps);
    $interval = count($gaps) > 0 ? $gaps[(int)(count($gaps) / 2)] : 60;

    $secs = 0;
    $i    = 0;
    while ($i < $n) {
        if ($rows[$i]['status'] !== $target_status) { $i++; continue; }
        $run_start = (int)$rows[$i][$ts_key];
        $run_end   = $run_start;
        while ($i < $n && $rows[$i]['status'] === $target_status) {
            $run_end = (int)$rows[$i][$ts_key];
            $i++;
        }
        $secs += ($run_end - $run_start) + $interval;
    }
    return $secs;
}

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
                'down_secs'       => 0,
                'degraded_secs'   => 0,
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
            'down_secs'       => stats_calc_downtime_secs($checks, 'down'),
            'degraded_secs'   => stats_calc_downtime_secs($checks, 'degraded'),
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
 * Supports multiple downtime severity stages.
 *
 * Stages:
 *   unknown        — no data for this day
 *   up             — 100% operational
 *   degraded       — degraded checks, no outage
 *   outage-minor   — >0% and ≤10% of checks were down
 *   outage-major   — >10% and ≤50% of checks were down
 *   outage-critical — >50% of checks were down
 */
function stats_day_status(array $day): string
{
    if ($day['total_checks'] === 0) return 'unknown';

    $outage_pct = $day['outage_pct'] ?? 0;

    if ($outage_pct > 50)  return 'outage-critical';
    if ($outage_pct > 10)  return 'outage-major';
    if ($outage_pct > 0)   return 'outage-minor';
    if ($day['had_degraded']) return 'degraded';
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
