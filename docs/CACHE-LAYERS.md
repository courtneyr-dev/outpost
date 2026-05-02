# Cache layers on managed-WP hosts

Outpost has been developed against GoDaddy Managed WordPress (Cloudflare-fronted), where multiple cache layers stack between the browser and the database. This doc captures the layers, the symptoms each one produces when stale, and the order to flush them in.

The exact admin URLs are site-specific; the pattern is portable to any managed-WP host (WP Engine, Kinsta, Pressable all have analogous layers).

## The four layers

```
Browser
  ↑
  ↓
[1] Service Worker cache  (in browser, version-keyed by OUTPOST_VERSION)
  ↑
  ↓
[2] Cloudflare edge       (CDN — caches HTML, CSS, JS, images)
  ↑
  ↓
[3] GoDaddy WP page cache (origin server — caches anonymous HTML)
  ↑
  ↓
[4] Perfmatters / Autoptimize / WP Rocket  (CSS+JS minify+combine)
  ↑
  ↓
[5] Object cache          (Redis/Memcached — DB queries + transients)
  ↑
  ↓
WordPress core / Outpost code
```

Outpost's own service worker (D0) is the only layer Outpost controls. The other four are site-config concerns.

## Symptom → layer table

| Symptom | Most likely culprit | Fix |
|---|---|---|
| Buttons / colors don't update after a token change | Perfmatters / Autoptimize | Flush their CSS cache |
| 429 rate-limit doesn't clear after waiting 60+ seconds | Object cache (transient stuck) | Flush object cache |
| 401 unauthorized persists after IndieAuth re-auth | Cloudflare edge cache (cached the 401) | Cache-bust with `?_cb=<ts>` or purge edge |
| Inline-script CSP errors after deploy | GoDaddy WP cache (HTML stale, nonce mismatch) | Flush WP cache + Cloudflare |
| Stylesheet 404s after deploy (manifest points at deleted hash) | Service worker | Unregister SW or hard-refresh twice |
| New plugin version not visible to non-admin viewers | GoDaddy WP cache | Flush WP cache |

## Flush order (innermost → outermost)

Order matters. Each outer cache repopulates from the layer below it on the next request. Flushing the outer first lets it re-cache the still-stale inner.

1. **Object cache** (DB queries + transients)
2. **Perfmatters / Autoptimize** (CSS/JS bundles)
3. **GoDaddy WP page cache** (rendered HTML)
4. **Cloudflare edge** (CDN)

## Defensive patterns Outpost uses

These reduce how often cache flushes are needed in normal operation.

- **Per-user data**: `Cache-Control: private, no-store, max-age=0` on every REST response that's user-scoped (composer-config, preview). Defends against Cloudflare caching one user's response and serving it to another.
- **Rate-limit transients keyed by user ID**: per-user flushes work without affecting others, and the transient TTL (`MINUTE_IN_SECONDS`) is short enough that the natural expiry recovers from the user's perspective.
- **Asset versioning** via `?ver=OUTPOST_VERSION`: appended to the token CSS link so each plugin version creates a fresh URL — bypasses Perfmatters/Cloudflare's content-hash regeneration lag.
- **Service worker cache versioning**: SW cache name = `outpost-${OUTPOST_VERSION}`. Old caches deleted on activate. Plugin update → new SW → new cache → old evicted.
- **No inline-script nonces**: SW registration lives in the bundled JS (`pwa/src/index.tsx`), not in an inline `<script nonce="…">` in the shell. Cached HTML can't fall out of sync with per-request CSP.
- **Cache-bust query during testing**: `?_cb=$(date +%s)` in smoke-test commands forces real PHP execution past Cloudflare's cache.

## When to flush after a deploy

| What changed | Layers to flush |
|---|---|
| Token palette / radius / shadow values | Perfmatters + Cloudflare |
| CSP / shell HTML / install-prompt | GoDaddy WP cache + Cloudflare |
| Settings (wp_options) | Object cache |
| Rate limits hit during testing | Object cache |
| Bundled JS only (no CSS) | None — Vite hashes invalidate the SW cache automatically |
| Plugin version bump (any change) | All four, in order, if you want a guaranteed-clean baseline |

## Common managed-WP cache locations

For development against `courtneyr.dev` / staging at `qkf.b0d.myftpupload.com`:

- **Object cache**: `/wp-admin/options-general.php?page=objectcache`
- **Perfmatters**: `/wp-admin/options-general.php?page=perfmatters` → CSS Optimization or Tools tab → Clear cache
- **GoDaddy WP page cache**: `/wp-admin/admin.php?page=wp-dashboard&section=tools`
- **Cloudflare edge** (managed by GoDaddy): GoDaddy host control panel → Site → Security & Performance → Cache → Purge

For other managed-WP hosts, the same conceptual layers exist; check the host's admin or the relevant performance plugin.

## See also

- `bin/check-tokens-parity.mjs` — CI guard against drift between server-rendered tokens and bundled mirror
- `class-pwa-shell.php` `send_html_header()` — where Outpost sets `Cache-Control: no-store` on shell HTML responses
- `class-composer-config-endpoint.php` `is_rate_limited()` — where the per-user transient lives
