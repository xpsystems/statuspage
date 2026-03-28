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
