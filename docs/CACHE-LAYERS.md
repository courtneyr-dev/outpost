# Cache layers between the composer and WordPress

A WordPress site that runs Outpost can stack up to five cache layers between the browser and the database. This doc names each layer, the symptom each one produces when it holds a stale copy, and the order to flush them in. The layers are generic: your host, CDN, and performance plugins decide which ones your site has and where their controls live.

## The five layers

```
Browser
  ↑
  ↓
[1] Service worker cache   (in the browser, keyed by OUTPOST_VERSION)
  ↑
  ↓
[2] CDN edge cache         (caches HTML, CSS, JS, images)
  ↑
  ↓
[3] Page cache             (origin server; caches anonymous HTML)
  ↑
  ↓
[4] Asset optimization     (a plugin that minifies or combines CSS and JS)
  ↑
  ↓
[5] Object cache           (Redis or Memcached; DB queries and transients)
  ↑
  ↓
WordPress core / Outpost code
```

Outpost's own service worker is the only layer Outpost controls. The other four come from your host, your CDN, or plugins you install. A site without a CDN or an object cache has fewer layers; the order of the ones it has stays the same.

## Symptom to layer

| Symptom | Most likely layer | Fix |
|---|---|---|
| Buttons or colors don't change after a token change | Asset optimization | Clear that plugin's CSS cache |
| A 429 rate-limit response doesn't clear after 60 seconds | Object cache (a rate-limit transient outlived its TTL) | Flush the object cache |
| A 401 persists after signing in again through IndieAuth | CDN edge (it cached the 401) | Load with `?_cb=<timestamp>`, or purge the CDN |
| The composer shell shows an old version after an update | Page cache or CDN edge | Purge the page cache, then the CDN |
| Stylesheet 404s after an update (the Vite manifest points at a deleted hash) | Service worker | Unregister the service worker, or hard-refresh twice |
| A new plugin version isn't visible to signed-out visitors | Page cache | Purge the page cache |

## Flush order: innermost first

Each outer cache refills from the layer below it on the next request. Flush an outer layer first and it can re-cache a copy the inner layer still holds stale.

1. **Object cache** (DB queries and transients)
2. **Asset optimization** (CSS and JS bundles)
3. **Page cache** (rendered HTML)
4. **CDN edge**

The service worker updates itself: a new `OUTPOST_VERSION` serves a new worker, which deletes the old cache on activate.

## What Outpost does so you flush less often

- **`no-store` on per-person REST responses.** `/wp-json/outpost/v1/composer-config` (`includes/class-composer-config-endpoint.php`) sends `Cache-Control: private, no-store, max-age=0`, and `/wp-json/outpost/v1/appearance/tokens` (`includes/class-appearance-rest-controller.php`) sends `Cache-Control: no-store, private, max-age=0`. A CDN that honors the header won't serve one person's response to another. The preview endpoint (`includes/class-preview-endpoint.php`) sets no `Cache-Control` header of its own, and the service worker passes every `/wp-json/outpost/` request straight to the network without caching it.
- **`nocache_headers()` on the composer shell.** `Outpost_PWA_Shell::send_html_header()` calls WordPress's `nocache_headers()` on every shell HTML response, so a page cache doesn't hand one person's install prompt to another.
- **`no-cache, must-revalidate` on the manifest and service worker.** `/post/manifest.json` and `/post/sw` revalidate on every request, so an update reaches installed copies promptly.
- **Rate-limit transients keyed by user.** The preview, media-lookup, and composer-config limits store a per-user transient (an IP-keyed one for signed-out composer-config and media-lookup requests) that expires after `MINUTE_IN_SECONDS`. Flushing one person's limit doesn't touch anyone else's, and waiting a minute clears it.
- **`?ver=OUTPOST_VERSION` on the token stylesheet.** `styles/outpost-tokens.css` has a stable filename, so each plugin version adds a new `ver` query and gets a new URL. Vite content-hashes the bundle's own CSS and JS filenames, so those need no query.
- **Service worker cache versioning.** The cache name is `outpost-<OUTPOST_VERSION>`. A plugin update ships a new worker, which creates a new cache and deletes the old one.
- **No inline scripts.** The shell's Content-Security-Policy is `script-src 'self'`. Service worker registration lives in the bundled JS (`pwa/src/index.tsx`), so cached HTML can't fall out of step with a per-request nonce.

## What to flush after an update

| What changed | Layers to flush |
|---|---|
| Token palette, radius, or shadow values | Asset optimization, then CDN edge |
| CSP, shell HTML, or the install prompt | Page cache, then CDN edge |
| Settings stored in `wp_options` | Object cache |
| Rate limits hit during testing | Object cache, or wait 60 seconds |
| Bundled JS only, no CSS | None: Vite's new hashes and the new service worker cache take care of it |
| Any plugin version bump, when you want a clean baseline | All four, in the order above |

## Where the controls usually live

- **Object cache:** the object-cache plugin's settings page, or `wp cache flush` with WP-CLI.
- **Asset optimization:** the plugin's settings page; most have a "clear cache" control.
- **Page cache:** your host's control panel or the page-cache plugin's settings.
- **CDN edge:** your CDN's dashboard (a "purge cache" control). If your host runs the CDN for you, the purge control is in the host's panel.

## See also

- `bin/check-tokens-parity.mjs`: the check that keeps the server-rendered tokens and the bundled copy in step.
- `includes/class-pwa-shell.php`, `send_html_header()`: where the shell sends `nocache_headers()` and the CSP.
- `includes/class-composer-config-endpoint.php`, `is_rate_limited()`: where the per-user rate-limit transient lives.
