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
