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
