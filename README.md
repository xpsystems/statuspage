# Statuspage for xpsystems

A lightweight, self-hosted status page with a built-in JSON API.  
Live at **[status.xpsystems.eu](https://status.xpsystems.eu)**

---

## Installation

```bash
git init && git remote add origin https://github.com/xpsystems/statuspage.git && git pull origin main
```

---

## Deployed Nodes

| Node | URL |
|------|-----|
| node-1 | [status.xpsystems.eu](https://status.xpsystems.eu) · [status.xpsystems.de](https://status.xpsystems.de) |
| node-2 | [status.xpsys.de](https://status.xpsys.de) |

---

## Service Configuration (`config.php`)

Each service entry in the `services` array supports the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | `string` | ✅ | Unique identifier used in API routes (`/api/service/{slug}`) |
| `name` | `string` | ✅ | Human-readable display name |
| `group` | `string` | ✅ | Group label (must match an entry in `groups`) |
| `url` | `string` | ✅ | Public URL linked in the UI |
| `ping_url` | `string` | ✅ | URL that is actually pinged for health checks |
| `is_deployed` | `bool` | ✅ | Controls whether the service is actively monitored |

### `is_deployed` flag

Setting `is_deployed` to `false` (or omitting it) has the following effects:

- The service is **not pinged** — no HTTP request is made.
- It **does not contribute** to the overall status (`operational` / `partial_outage` / `major_outage`).
- It is still **shown in the UI** with a neutral *"Not Deployed"* badge so you can track planned services.
- The `/api/status` summary exposes a `not_deployed` counter for transparency.

**Example — adding a planned service:**

```php
[
    'slug'        => 'my-new-service',
    'name'        => 'my-new-service.eu',
    'group'       => 'Hosting',
    'url'         => 'https://my-new-service.eu',
    'ping_url'    => 'https://my-new-service.eu',
    'is_deployed' => false,   // ← flip to true once live
],
```

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/ping` | Health check — returns `pong` |
| `GET` | `/api/status` | Overall status + summary counts |
| `GET` | `/api/services` | All services and their current status |
| `GET` | `/api/service/{slug}` | Single service status by slug |

All endpoints are open, unauthenticated, and CORS-enabled.

---

## Caching

Status checks are cached in `cache/status.json`.  
The default TTL is **90 seconds** (configurable via `cache.ttl` in `config.php`).  
Only deployed services are included in the cache.