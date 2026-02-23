(function () {
  const script = document.querySelector("script[data-api-base]");
  const API_BASE = script ? script.dataset.apiBase : "";
  const PLAYGROUND = script ? script.dataset.playground : "";

  const revealEls = document.querySelectorAll(".reveal");
  const observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("visible");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.1, rootMargin: "0px 0px -32px 0px" }
  );
  revealEls.forEach(function (el) {
    observer.observe(el);
  });

  const refreshBtn = document.getElementById("refresh-btn");
  const iconRefresh = document.getElementById("icon-refresh");
  const checkedTimeEl = document.getElementById("checked-time");

  function setServiceRowStatus(slug, data) {
    const row = document.querySelector(
      `.service-row[data-slug="${CSS.escape(slug)}"]`
    );
    if (!row) return;

    const indicator = row.querySelector(".status-indicator");
    const label = row.querySelector(".service-status-label");
    const latencyEl = row.querySelector(".service-latency");
    const codeEl = row.querySelector(".service-code");

    const status = data.status || "unknown";
    const statusClasses = ["up", "degraded", "down", "unknown"];

    if (indicator) {
      statusClasses.forEach(function (s) {
        indicator.classList.remove("status-indicator--" + s);
      });
      indicator.classList.add("status-indicator--" + status);
    }

    if (label) {
      statusClasses.forEach(function (s) {
        label.classList.remove("service-status-label--" + s);
      });
      label.classList.add("service-status-label--" + status);
      const labels = {
        up: "Operational",
        degraded: "Degraded",
        down: "Outage",
        unknown: "Unknown",
      };
      label.textContent = labels[status] || "Unknown";
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

    const title = hero.querySelector(".hero-bar-title");
    const allStates = ["operational", "partial_outage", "major_outage"];
    allStates.forEach(function (s) {
      hero.classList.remove("hero-bar--" + s);
    });
    hero.classList.add("hero-bar--" + overall);

    const titleMap = {
      operational: "All Systems Operational",
      partial_outage: "Partial System Outage",
      major_outage: "Major System Outage",
    };
    if (title) {
      title.textContent = titleMap[overall] || overall;
    }

    if (checkedTimeEl && checkedAt) {
      const d = new Date(checkedAt * 1000);
      const iso = d.toISOString();
      const display =
        d.getUTCFullYear() +
        "-" +
        String(d.getUTCMonth() + 1).padStart(2, "0") +
        "-" +
        String(d.getUTCDate()).padStart(2, "0") +
        " " +
        String(d.getUTCHours()).padStart(2, "0") +
        ":" +
        String(d.getUTCMinutes()).padStart(2, "0") +
        " UTC";
      checkedTimeEl.setAttribute("datetime", iso);
      checkedTimeEl.textContent = display;
    }
  }

  async function fetchAndApplyStatus() {
    if (!API_BASE) return;

    if (iconRefresh) {
      iconRefresh.classList.add("spinning");
    }
    if (refreshBtn) {
      refreshBtn.disabled = true;
    }

    try {
      const res = await fetch(API_BASE + "/services", {
        method: "GET",
        cache: "no-store",
        signal: AbortSignal.timeout(10000),
      });

      if (!res.ok) throw new Error("non-ok response");

      const json = await res.json();
      const services = Array.isArray(json.services) ? json.services : [];
      const checkedAt = json.checked_at || null;

      services.forEach(function (svc) {
        setServiceRowStatus(svc.slug, svc);
      });

      const statuses = services.map(function (s) {
        return s.status;
      });
      const downCount = statuses.filter(function (s) {
        return s === "down";
      }).length;
      const degradedCount = statuses.filter(function (s) {
        return s === "degraded";
      }).length;

      let overall = "operational";
      if (downCount > 0) overall = "major_outage";
      else if (degradedCount > 0) overall = "partial_outage";

      updateHeroBar(overall, checkedAt);
    } catch {
    } finally {
      if (iconRefresh) {
        iconRefresh.classList.remove("spinning");
      }
      if (refreshBtn) {
        refreshBtn.disabled = false;
      }
    }
  }

  if (refreshBtn) {
    refreshBtn.addEventListener("click", function () {
      fetchAndApplyStatus();
    });
  }

  fetchAndApplyStatus();

  if (PLAYGROUND && API_BASE) {
    document.querySelectorAll(".api-action-link--try").forEach(function (el) {
      if (el.tagName !== "A") return;
      const href = el.getAttribute("href");
      if (!href || href === "#") return;
    });
  }
})();