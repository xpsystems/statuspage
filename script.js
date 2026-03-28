(function () {
  "use strict";

  const script     = document.querySelector("script[data-api-base]");
  const API_BASE   = script?.dataset.apiBase   || "";
  const SSE_URL    = script?.dataset.sseUrl    || "";
  const CACHE_AGE  = parseInt(script?.dataset.cacheAge || "0", 10);

  // ── Theme ──────────────────────────────────────────────────────────────────

  const THEME_KEY = "xps-theme";

  function getSystemTheme() {
    return window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark";
  }

  function applyTheme(mode) {
    const resolved = mode === "system" ? getSystemTheme() : mode;
    document.documentElement.setAttribute("data-theme", resolved);
    document.querySelectorAll(".theme-btn").forEach(btn => {
      btn.classList.toggle("active", btn.dataset.theme === mode);
    });
  }

  let currentTheme = localStorage.getItem(THEME_KEY) || "system";
  applyTheme(currentTheme);

  window.matchMedia("(prefers-color-scheme: light)").addEventListener("change", () => {
    if (currentTheme === "system") applyTheme("system");
  });

  document.querySelectorAll(".theme-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      currentTheme = btn.dataset.theme;
      localStorage.setItem(THEME_KEY, currentTheme);
      applyTheme(currentTheme);
    });
  });

  // ── Reveal on scroll ───────────────────────────────────────────────────────

  const revealObs = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add("visible");
        revealObs.unobserve(e.target);
      }
    });
  }, { threshold: 0.1, rootMargin: "0px 0px -32px 0px" });

  document.querySelectorAll(".reveal").forEach(el => revealObs.observe(el));

  // ── DOM refs ───────────────────────────────────────────────────────────────

  const refreshBtn    = document.getElementById("refresh-btn");
  const iconRefresh   = document.getElementById("icon-refresh");
  const checkedTimeEl = document.getElementById("checked-time");

  // ── Status rendering ───────────────────────────────────────────────────────

  const STATUS_CLASSES = ["up", "degraded", "down", "unknown", "not_deployed"];
  const STATUS_LABELS  = {
    up:           "Operational",
    degraded:     "Degraded",
    down:         "Outage",
    not_deployed: "Not Deployed",
    unknown:      "Unknown",
  };

  function setServiceRowStatus(slug, data) {
    const row = document.querySelector(`.service-row[data-slug="${CSS.escape(slug)}"]`);
    if (!row) return;

    const prev   = row.dataset.status;
    const status = data.status || "unknown";

    // Animate change
    if (prev && prev !== status) {
      row.classList.add("status-changed");
      setTimeout(() => row.classList.remove("status-changed"), 800);
    }

    const indicator = row.querySelector(".status-indicator");
    const label     = row.querySelector(".service-status-label");
    const latencyEl = row.querySelector(".service-latency");
    const codeEl    = row.querySelector(".service-code");

    if (indicator) {
      STATUS_CLASSES.forEach(s => indicator.classList.remove("status-indicator--" + s));
      indicator.classList.add("status-indicator--" + status);
    }

    if (label) {
      STATUS_CLASSES.forEach(s => label.classList.remove("service-status-label--" + s));
      label.classList.add("service-status-label--" + status);
      label.textContent = STATUS_LABELS[status] || "Unknown";
    }

    if (latencyEl && data.latency_ms != null) {
      latencyEl.textContent = data.latency_ms + "ms";
    }

    if (codeEl && data.http_code != null) {
      codeEl.textContent = "HTTP " + data.http_code;
    }

    row.dataset.status = status;
  }

  function updateHeroBar(overall, checkedAt) {
    const hero = document.querySelector(".hero-bar");
    if (!hero) return;

    const title     = hero.querySelector(".hero-bar-title");
    const allStates = ["operational", "partial_outage", "major_outage", "unknown"];
    allStates.forEach(s => hero.classList.remove("hero-bar--" + s));
    hero.classList.add("hero-bar--" + overall);

    const TITLE_MAP = {
      operational:    "All Systems Operational",
      partial_outage: "Partial System Outage",
      major_outage:   "Major System Outage",
      unknown:        "Checking status…",
    };
    if (title) title.textContent = TITLE_MAP[overall] || overall;

    if (checkedTimeEl && checkedAt) {
      const d = new Date(checkedAt * 1000);
      const display =
        d.getUTCFullYear() + "-" +
        String(d.getUTCMonth() + 1).padStart(2, "0") + "-" +
        String(d.getUTCDate()).padStart(2, "0") + " " +
        String(d.getUTCHours()).padStart(2, "0") + ":" +
        String(d.getUTCMinutes()).padStart(2, "0") + " UTC";
      checkedTimeEl.setAttribute("datetime", d.toISOString());
      checkedTimeEl.textContent = display;
    }
  }

  function applyStatusPayload(json) {
    const services  = Array.isArray(json.services) ? json.services : [];
    const checkedAt = json.checked_at || null;

    services.forEach(svc => setServiceRowStatus(svc.slug, svc));

    const deployed = services.filter(s => s.is_deployed !== false);
    const statuses = deployed.map(s => s.status);
    const allUnknown = statuses.length === 0 || statuses.every(s => s === "unknown");
    const overall  = allUnknown          ? (json.overall || "unknown")
                   : statuses.includes("down")     ? "major_outage"
                   : statuses.includes("degraded") ? "partial_outage"
                   : "operational";

    updateHeroBar(overall, checkedAt);

    // Notify service worker of new data
    if (navigator.serviceWorker?.controller) {
      navigator.serviceWorker.controller.postMessage({
        type: "STATUS_UPDATE",
        payload: json,
      });
    }
  }

  // ── Server-Sent Events ─────────────────────────────────────────────────────

  let sse         = null;
  let sseRetries  = 0;
  let sseTimer    = null;
  const MAX_RETRY = 30000; // 30s max backoff

  function connectSSE() {
    if (!SSE_URL || !window.EventSource) {
      startPolling();
      return;
    }

    sse = new EventSource(SSE_URL);

    sse.addEventListener("status", e => {
      try {
        const data = JSON.parse(e.data);
        applyStatusPayload(data);
        sseRetries = 0;
      } catch (_) {}
    });

    sse.addEventListener("checking", () => {
      if (iconRefresh) iconRefresh.classList.add("spinning");
    });

    sse.addEventListener("heartbeat", () => {});

    sse.onopen = () => {
      sseRetries = 0;
      if (iconRefresh) iconRefresh.classList.remove("spinning");
    };

    sse.onerror = () => {
      sse.close();
      sse = null;
      if (iconRefresh) iconRefresh.classList.remove("spinning");

      sseRetries++;

      if (sseRetries >= 2) {
        startPolling();
        return;
      }

      const delay = Math.min(1000 * Math.pow(2, sseRetries), 8000);
      sseTimer = setTimeout(connectSSE, delay);
    };
  }

  // ── Polling fallback ───────────────────────────────────────────────────────

  let pollTimer = null;
  const POLL_INTERVAL = 30000;

  function startPolling() {
    if (pollTimer) return; // already polling
    fetchStatus();
    pollTimer = setInterval(fetchStatus, POLL_INTERVAL);
  }

  async function fetchStatus() {
    if (!API_BASE) return;
    if (iconRefresh) iconRefresh.classList.add("spinning");
    if (refreshBtn)  refreshBtn.disabled = true;

    try {
      const res = await fetch(API_BASE + "/api/services", {
        cache:  "no-store",
        signal: AbortSignal.timeout(10000),
      });
      if (!res.ok) throw new Error("non-ok");
      applyStatusPayload(await res.json());
    } catch (_) {
    } finally {
      if (iconRefresh) iconRefresh.classList.remove("spinning");
      if (refreshBtn)  refreshBtn.disabled = false;
    }
  }

  // ── Manual refresh ─────────────────────────────────────────────────────────

  if (refreshBtn) {
    refreshBtn.addEventListener("click", () => {
      if (sse) {
        // SSE is active — just fetch once manually
        fetchStatus();
      } else {
        fetchStatus();
      }
    });
  }

  // ── Service Worker messages ────────────────────────────────────────────────

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.addEventListener("message", e => {
      if (e.data?.type === "CACHED_STATUS") {
        applyStatusPayload(e.data.payload);
      }
    });
  }

  // ── Stale cache banner ─────────────────────────────────────────────────────
  // If the server-rendered page is >2 min old, show a subtle "data may be stale" hint

  if (CACHE_AGE > 120) {
    const bar = document.querySelector(".hero-bar-sub");
    if (bar) {
      const stale = document.createElement("span");
      stale.className = "stale-hint";
      stale.textContent = "· data may be stale";
      bar.appendChild(stale);
    }
  }

  // ── Uptime bar tooltips ────────────────────────────────────────────────────

  document.querySelectorAll(".uptime-tick").forEach(tick => {
    const date    = tick.dataset.date;
    const checks  = parseInt(tick.dataset.checks  || "0", 10);
    const uptime  = tick.dataset.uptime;
    const latency = tick.dataset.latency;
    const down    = parseInt(tick.dataset.down     || "0", 10);
    const deg     = parseInt(tick.dataset.degraded || "0", 10);

    if (!date) return;

    let tip = date;
    if (checks === 0) {
      tip += "\nno data";
    } else {
      tip += uptime !== "null" && uptime !== "" ? `\n${uptime}% uptime` : "";
      if (down > 0)    tip += `\n${down} outage check${down > 1 ? "s" : ""}`;
      if (deg > 0)     tip += `\n${deg} degraded`;
      if (latency && latency !== "null") tip += `\navg ${latency}ms`;
      tip += `\n${checks} checks`;
    }

    tick.dataset.tip = tip;
  });

  // ── Start ──────────────────────────────────────────────────────────────────

  connectSSE();

})();
