/**
 * sw.js — xpsystems statuspage Service Worker
 *
 * Strategy:
 *   - Shell (HTML/CSS/JS): Cache-first, update in background
 *   - API responses:       Network-first, fall back to cache
 *   - Fonts:               Cache-first (long TTL)
 *
 * The SW also receives STATUS_UPDATE messages from the page
 * and caches the latest status for offline display.
 */

"use strict";

const CACHE_NAME    = "xps-status-v2";
const OFFLINE_CACHE = "xps-status-offline-v2";

const SHELL_ASSETS = [
  "/",
  "/style.css",
  "/script.js",
];

const API_CACHE_URLS = [
  "/api/status",
  "/api/services",
];

// ── Install ────────────────────────────────────────────────────────────────

self.addEventListener("install", event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
  );
});

// ── Activate ───────────────────────────────────────────────────────────────

self.addEventListener("activate", event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_NAME && k !== OFFLINE_CACHE)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch ──────────────────────────────────────────────────────────────────

self.addEventListener("fetch", event => {
  const url = new URL(event.request.url);

  // Skip non-GET, cross-origin, SSE stream
  if (event.request.method !== "GET") return;
  if (url.origin !== self.location.origin) return;
  if (url.pathname === "/events.php") return;

  // API: network-first, cache fallback
  if (url.pathname.startsWith("/api/")) {
    event.respondWith(networkFirstWithCache(event.request));
    return;
  }

  // Shell assets: cache-first, revalidate in background
  event.respondWith(cacheFirstWithRevalidate(event.request));
});

async function networkFirstWithCache(request) {
  try {
    const res = await fetch(request.clone());
    if (res.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, res.clone());
    }
    return res;
  } catch (_) {
    const cached = await caches.match(request);
    return cached || new Response(
      JSON.stringify({ error: "offline", cached: false }),
      { status: 503, headers: { "Content-Type": "application/json" } }
    );
  }
}

async function cacheFirstWithRevalidate(request) {
  const cached = await caches.match(request);

  // Revalidate in background
  const fetchPromise = fetch(request.clone()).then(res => {
    if (res.ok) {
      caches.open(CACHE_NAME).then(cache => cache.put(request, res.clone()));
    }
    return res;
  }).catch(() => null);

  return cached || fetchPromise;
}

// ── Messages from page ─────────────────────────────────────────────────────

self.addEventListener("message", event => {
  if (event.data?.type === "STATUS_UPDATE") {
    // Cache the latest status payload for offline use
    caches.open(OFFLINE_CACHE).then(cache => {
      const body    = JSON.stringify(event.data.payload);
      const headers = new Headers({ "Content-Type": "application/json" });
      cache.put("/api/services", new Response(body, { headers }));
    });
  }

  if (event.data?.type === "GET_CACHED_STATUS") {
    caches.open(OFFLINE_CACHE).then(async cache => {
      const res = await cache.match("/api/services");
      if (res) {
        const payload = await res.json();
        event.source.postMessage({ type: "CACHED_STATUS", payload });
      }
    });
  }
});
