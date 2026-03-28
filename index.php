<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/helpers.php';

$e   = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$uri = '/' . trim($uri, '/');

// ── SSE route ─────────────────────────────────────────────────────────────────
if ($uri === '/events') {
    require __DIR__ . '/events.php';
    exit;
}

// ── API routes ────────────────────────────────────────────────────────────────
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/api.php';
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
<link rel="stylesheet" href="style-services.css">
<link rel="stylesheet" href="style-drawer.css">
<script>
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

<?php require __DIR__ . '/partials/nav.php'; ?>

<main>

<?php require __DIR__ . '/partials/hero.php'; ?>

<?php require __DIR__ . '/partials/services.php'; ?>

  <div class="section-divider">
    <svg viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true">
      <path d="M0,0 Q360,60 720,30 Q1080,0 1440,60 L1440,60 L0,60 Z" class="divider-fill-alt"/>
    </svg>
  </div>

<?php require __DIR__ . '/partials/api-section.php'; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

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

<?php require __DIR__ . '/partials/tooltip-drawer.php'; ?>

</body>
</html>
