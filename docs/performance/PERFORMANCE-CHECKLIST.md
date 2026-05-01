# Performance Checklist

Per-surface wordpress-performance pattern checklist for upcoming Outpost work. Pairs with `PHP-SURFACE-CHECKLIST.md` (security) and `STAGING-DEPLOY.md` (deploy ritual).

## Status at v0.1.6

The `/post/` shell load path is tight. Total transfer for a cold cached load:

- HTML shell: ~3 KB (gzipped, sent inline by PHP)
- CSS: 2.26 KB / 0.60 KB gzipped (one external request)
- JS module: 27.88 KB / 10.00 KB gzipped (one external request, started early via `<link rel="modulepreload">`)
- **Total cold load: ~13.6 KB gzipped**

### What was applied at v0.1.6

- ✅ **`<link rel="modulepreload">`** for the entry JS — browser starts fetching the module while parsing HTML and downloading CSS in parallel, instead of waiting for the `<script type="module">` tag at the bottom of body.
- ✅ **Inline critical layout CSS** in `<style>` — reserves `min-height: 100dvh` on `body` and `#outpost-root` immediately, before the bundled CSS arrives. Eliminates CLS when JS mounts and adds the `.outpost-app` class.
- ✅ **`<script type="module">`** is inherently deferred — modules execute after HTML parsing.
- ✅ **Cache-busted asset filenames** via Vite content hashes — long-cache + cache-bust pattern works automatically. No manual cache-buster needed for asset URLs (only for the staging-verify HTML cache; see hosting gotcha #5).
- ✅ **HTTP/2** is on by default on managed-WP hosts — small-bundle + parallel-fetch combo benefits from HTTP/2's multiplexing.
- ✅ **Per-request manifest cache** in `Outpost_PWA_Assets::manifest()` — disk read at most once per render path, not per asset URL lookup.

### Core Web Vitals targets (Outpost composer route)

| Metric | Target | Current expectation | Notes |
|--------|--------|---------------------|-------|
| LCP | < 2.5s | ~600ms on broadband, ~1.5s on 3G | Login screen card title is the LCP element. modulepreload + parallel CSS/JS + gzipped <14KB total = fast. |
| INP | < 200ms | ~10ms typical | No heavy click handlers; Preact's reconciliation on a tiny tree is sub-frame. |
| CLS | < 0.1 | 0 (post-fix) | Inline critical layout reserves space pre-mount. |

Future surfaces will need their own measurements; the checklist below tracks where to look.

---

## B2 — Server-side mf2 preview

**Latency surface:** the `/wp-json/outpost/v1/preview` endpoint fetches the user's reply target URL server-side, parses mf2, returns to the PWA. Round-trip cost matters for composer responsiveness.

**Required primitives:**

- [ ] Per-URL transient cache with short TTL (e.g. `5 * MINUTE_IN_SECONDS`) keyed on the URL hash. Reply-target lookups are usually idempotent in the short term.
- [ ] HTTP timeout cap on `wp_safe_remote_get()` — default is 5s; consider 3s for snappier error fallback.
- [ ] Response size cap (already in security checklist) doubles as a perf guard against pathological responses.
- [ ] Stream parsing where possible — don't buffer the full HTML body into mf2 parsing if the consumer only needs the first few properties.
- [ ] Rate limiting (security concern + perf guard against accidentally hammering the same URL).

**Observability:**

- [ ] Log preview latencies (transient cache hit/miss, fetch time, parse time) to error_log when `WP_DEBUG_LOG`. Phase G adds proper telemetry; B2 just needs raw numbers.

---

## Phase C — Composer modes

The composer adds Preact components per mode (Note, Reply, Photo, Listen group, Article). Bundle size will grow.

**Required primitives:**

- [ ] **Code split per mode** via Vite dynamic `import()`. The Note mode loads on first paint; Reply / Photo / Article / Listen group lazy-load when their tab is selected. Initial bundle stays close to today's 27.88 KB.
- [ ] Preserve the modulepreload pattern for the entry; let dynamic imports use the browser's natural ESM fetch.
- [ ] **Form input idleness** — debounce the textarea's `onInput` if any per-keystroke work lands (mf2 preview parsing, character count, etc.). 200ms is the standard.
- [ ] **No layout shift on tab change** — reserve the largest mode's height in the tab container, or use `min-height` so switching tabs doesn't reflow the surrounding chrome.
- [ ] **Image upload preview**: scale to viewport size client-side before upload (Canvas round-trip) so we never `<img>`-render the full 12 MP camera-roll capture. Pairs with the EXIF strip.

**Bundle budget:**

- [ ] Stay under **40 KB gzipped** for the entry bundle through Phase C. Hard-block a release that breaks this without measurement-justified rationale (per Code Output Verification Checklist #3).
- [ ] Lazy-loaded modes don't count against the entry budget but should each stay under 20 KB gzipped.

---

## Phase D — PWA polish

**Service worker fetch handler:**

- [ ] **Network-first for HTML, cache-first for static assets** — the manifest/SW responses live from PHP and shouldn't be aggressively cached; the JS/CSS bundles ARE cache-bustable so cache-first is safe.
- [ ] **Offline queue for failed Micropub posts** — when `post_note` rejects with a network error, queue in IndexedDB and retry on next online event.
- [ ] **Stale-while-revalidate for the user's me URL discovery** — `discover_endpoints()` results rarely change; serve cached, refresh in background.

**Workbox vs hand-rolled:**

- Workbox has a footprint cost (~10 KB gzipped). Hand-rolled is fine for B0a's no-op SW + Phase D's specific strategies. **Decision deferred to Phase D** — measure first.

---

## Phase F — Companion adapters

**Adapter capability detection:**

- [ ] **Cache `Outpost_Companion_Detector::status()` results** within a request via static property (already done in detector). Per-request cost is one filesystem stat + one option read per chain entry; negligible.
- [ ] If admin notices ever surface adapter capability strings, build the capability list once per request, not per call.

---

## Phase H — Settings page

**Settings page render time:**

- [ ] Settings API fields are cheap; the heavy lift is usually external API calls for "test connection" buttons. Use AJAX for those, not synchronous form processing.
- [ ] **Bookmarklet generator** — generate the JS as a static asset at build time when possible; fall back to PHP rendering only for site-specific URL substitution. The static portion gets browser-cached forever.

---

## Cross-cutting patterns

**Transient caching at the right layer:**

- Per-user data → transient with user ID in key
- Site-wide data → transient with no key suffix
- Per-request data → static class property (no cache layer needed)
- Cross-request, persistent → `wp_cache_set` with persistent group; falls back to no-op if no object cache configured

**Don't trust the network:**

- All external HTTP has a timeout (default 5s; consider tighter)
- All external HTTP has a response size cap
- All external HTTP responses get content-type validation

**Bundle hygiene:**

- Run `npm run build` and check `build/pwa/.vite/manifest.json` size before every release
- If JS gzipped exceeds 40 KB without a code-split or a documented reason, push back

**Database queries (when they land):**

- Always pass `'no_found_rows' => true` unless paginating
- Use `'fields' => 'ids'` when only IDs are needed
- Use `'update_post_meta_cache' => false` and `'update_post_term_cache' => false` when those caches aren't read
- Custom-table queries via `$wpdb->prepare` only

---

## Validation commands

Performance is hard to validate from CI without real-browser measurements, but bundle size is checkable:

```bash
cd ~/projects/outpost
npm run build
ls -lh build/pwa/assets/index-*.js
# Watch for the gzipped size in vite's output
```

When B2 / Phase C ship, plan a one-time browser session with Chrome DevTools' Performance panel against staging:

- Record a load of `/post/?_cb=<timestamp>` on Slow 3G + 4× CPU throttle
- Note LCP, FCP, CLS, TTI
- Save the trace to `docs/performance/traces/<version>-<network>-<cpu>.json`

---

*Last reviewed: 2026-05-01 (v0.1.6). Update on every observable-perf-change session close.*
