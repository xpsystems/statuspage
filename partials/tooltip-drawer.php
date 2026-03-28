<div id="day-tooltip" class="day-tooltip" aria-hidden="true">
  <div class="day-tooltip-header">
    <span class="day-tooltip-date" id="day-tooltip-date"></span>
    <span class="day-tooltip-badge" id="day-tooltip-badge"></span>
  </div>
  <div class="day-tooltip-rows" id="day-tooltip-rows"></div>
</div>

<div id="day-drawer-backdrop" class="day-drawer-backdrop"></div>
<aside id="day-drawer" class="day-drawer" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="day-drawer-title">
  <div class="day-drawer-header">
    <div class="day-drawer-title-group">
      <span class="day-drawer-label" id="day-drawer-svc"></span>
      <h2 class="day-drawer-title" id="day-drawer-title"></h2>
    </div>
    <button class="day-drawer-close" id="day-drawer-close" aria-label="Close" type="button">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
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
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
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

    tick.addEventListener('click', function () {
      var slug = tick.dataset.slug;
      var date = tick.dataset.date;
      if (!slug || !date) return;
      tip.classList.remove('day-tooltip--visible');
      openDrawer(slug, date, tick);
    });
  });

  // ── Drawer ───────────────────────────────────────────────────
  var drawer      = document.getElementById('day-drawer');
  var backdrop    = document.getElementById('day-drawer-backdrop');
  var drawerSvc   = document.getElementById('day-drawer-svc');
  var drawerTitle = document.getElementById('day-drawer-title');
  var drawerLoad  = document.getElementById('day-drawer-loading');
  var drawerCont  = document.getElementById('day-drawer-content');
  var drawerErr   = document.getElementById('day-drawer-error');
  var closeBtn    = document.getElementById('day-drawer-close');

  function openDrawer(slug, date, tick) {
    var row     = tick.closest('.service-row');
    var svcName = row ? (row.querySelector('.service-name') || {}).textContent || slug : slug;

    drawerSvc.textContent    = svcName;
    drawerTitle.textContent  = date;
    drawerLoad.style.display = '';
    drawerCont.style.display = 'none';
    drawerErr.style.display  = 'none';
    drawerCont.innerHTML     = '';

    drawer.classList.add('day-drawer--open');
    backdrop.classList.add('day-drawer-backdrop--visible');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    var apiBase = (document.querySelector('script[data-api-base]') || {}).dataset.apiBase || '';
    fetch(apiBase + '/api/day/' + encodeURIComponent(slug) + '/' + encodeURIComponent(date), { cache: 'no-store' })
      .then(function (r) {
        return r.ok ? r.json() : r.json().then(function (e) { throw new Error(e.error || 'Error ' + r.status); });
      })
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
    return d.getUTCHours().toString().padStart(2, '0') + ':'
         + d.getUTCMinutes().toString().padStart(2, '0') + ':'
         + d.getUTCSeconds().toString().padStart(2, '0') + ' UTC';
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

    html += '<div class="ddr-summary">';
    html += ddrCard('Total checks', data.total_checks);
    if (data.down_secs > 0)     html += ddrCard('Downtime', fmtDur(data.down_secs),     'down');
    if (data.degraded_secs > 0) html += ddrCard('Degraded', fmtDur(data.degraded_secs), 'degraded');
    html += '</div>';

    var badSpans = (data.spans || []).filter(function (s) { return s.status === 'down' || s.status === 'degraded'; });
    if (badSpans.length > 0) {
      html += '<div class="ddr-section-title">Incidents</div><div class="ddr-incidents">';
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

    html += '<div class="ddr-section-title">Timeline <span class="ddr-check-count">' + data.total_checks + ' checks</span></div>';
    html += '<div class="ddr-timeline">';
    var checks = data.checks || [], prevStatus = null;
    checks.forEach(function (c) {
      var changed = c.status !== prevStatus;
      prevStatus  = c.status;
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
