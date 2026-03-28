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
