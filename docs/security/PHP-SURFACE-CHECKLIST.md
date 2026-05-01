# PHP Surface Security Checklist

Per-surface wordpress-pro pattern checklist for upcoming Outpost work. Each surface lists the WordPress security primitives it must use, the failure modes it must defend, and the test coverage it owes.

Pairs with `SECURITY.md` (policy + scope) and Phase G's broader pen-test pass. Treat this as a contract that future sessions inherit.

## Status at v0.1.4

PHP surface is tight: admin notices + activation hooks + PWA shell render. No user input reaches PHP yet, no DB queries, no REST endpoints. All current primitives in use:

- ✅ `wp_nonce_url()` for activate/install action buttons in admin notices
- ✅ `current_user_can( 'install_plugins' )` gates admin notice rendering
- ✅ `esc_html()`, `esc_url()`, `esc_attr()` on every dynamic output
- ✅ `__()`/`esc_html__()` for all strings, locked to `'outpost'` text domain by PHPCS
- ✅ `template_redirect` priority 1 + `Outpost_PWA_Shell::halt()` keeps WP from concatenating theme template onto our output
- ✅ Encrypted IndexedDB token storage (client-side; not a PHP surface but worth noting)

The forward-looking checklist below is what to verify before each new surface ships.

---

## B2 — Server-side mf2 preview (`/wp-json/outpost/v1/preview`)

**Threat:** SSRF via the `url` parameter — attacker reaches loopback, private network, or internal hosts; oversized responses; redirects to internal hosts.

**Required primitives:**

- [ ] `register_rest_route()` with explicit `permission_callback` (not `__return_true`)
- [ ] `permission_callback` checks for an authenticated user OR a valid IndieAuth bearer token
- [ ] Nonce verification on the request (`X-WP-Nonce` for cookie auth; bearer token for app auth)
- [ ] `esc_url_raw()` + scheme validation — reject anything not `http://` or `https://`
- [ ] Reject loopback IPs (`127.0.0.0/8`, `::1`), private network ranges (`10/8`, `172.16/12`, `192.168/16`, link-local), and `.localhost`/`.local` hostnames
- [ ] Use `wp_safe_remote_get()` (not `wp_remote_get()`) — automatically blocks loopback + private ranges
- [ ] Cap response size at 5 MB via `WP_HTTP::request()` size argument or post-fetch length check
- [ ] Validate response `Content-Type` is `text/html` or `application/xhtml+xml` — reject other types
- [ ] Strip `<script>`, `<iframe>`, `<object>`, `<embed>`, event handlers, `javascript:` href, `data:` href before returning parsed mf2
- [ ] Rate-limit per user (transient-backed counter, e.g. 30 requests / minute)
- [ ] Return `403` on permission failure with `error` JSON; `503` when Outpost gate is unsatisfied; `400` on invalid URL

**Test coverage:**

- [ ] Integration test: SSRF vectors blocked (loopback, private, link-local, `.local`)
- [ ] Integration test: oversized response rejected
- [ ] Integration test: non-HTML content-type rejected
- [ ] Integration test: rate-limit triggers on burst
- [ ] Unit test: scheme validation
- [ ] Unit test: scripts/iframes stripped from returned HTML

---

## Phase C — Composer modes (composer route, frontend)

The `/post/` route currently renders the static shell. As Phase C lands the JS-rendered composer modes, the PHP side stays mostly the same — render shell, send PWA bundle, halt. **Most security work is on the PWA client + the Micropub plugin, not Outpost's PHP.**

The exceptions:

**Photo upload pipeline (Phase C/D — likely B2 or later):**

- [ ] `media_handle_upload()` not raw `move_uploaded_file()`
- [ ] MIME validation via `wp_check_filetype_and_ext()` — don't trust the request `Content-Type`
- [ ] EXIF stripping on upload (privacy: prevents location leak from camera roll)
- [ ] Image size cap (e.g. 10 MB; configurable via filter)
- [ ] Format allowlist: JPEG, PNG, WebP, GIF, AVIF (exclude SVG until separate sanitizer is wired)
- [ ] Required alt text per CLAUDE.md "Standards" section — structural, not configurable

**Bookmarklet `url` parameter at the composer endpoint (Phase E):**

- [ ] `esc_url_raw()` on the `?url=` query string
- [ ] Reject schemes other than `http://` and `https://`
- [ ] Length cap at 2048 chars
- [ ] Reject internal IPs (same logic as B2's SSRF defense)
- [ ] HTML-escape on every render path that surfaces the URL (prevents XSS via crafted bookmarklet `?url=` containing entities)

---

## Phase F — Companion adapters

Adapters consume `Outpost_Companion_Detector::status()` to gate capability registration. The detector itself is `final`, static-only, no per-instance state.

- [ ] No adapter writes to the database from its detection path
- [ ] Adapter capability declarations get `wp_kses_post()` if they ever surface to the admin UI
- [ ] Each adapter's smoke test confirms `is_*_active()` returns the right state across all three companion states (`active`, `inactive`, `absent`)

---

## Phase G — Security hardening

Phase G is where the real CSP and pen-test work lands. By that point, B2, Phase C, Phase E should already be using the patterns above; G is verification + ratchet.

- [ ] CSP header on `/post/*`: `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' <known-companion-endpoints>; img-src 'self' data: https:; object-src 'none'; base-uri 'self'`
- [ ] Frame-Options: DENY (or via CSP `frame-ancestors 'none'`)
- [ ] Service worker fetch handler review — confirm scope stays `/post/*`, no fetches to `/wp-admin/*`
- [ ] Token rotation strategy when scope changes
- [ ] Pen test against the live staging site by an external reviewer

---

## Phase H — Settings page

When `admin/` lands a settings page:

- [ ] Settings API (`register_setting`, `add_settings_section`, `add_settings_field`) — not a hand-rolled `options.php` form
- [ ] Per-setting `sanitize_callback` on `register_setting`
- [ ] Capability check on the page render and on every form submission (`manage_options` for global settings; `edit_posts` for per-user settings)
- [ ] `wp_nonce_field()` in the form output; `wp_verify_nonce()` on submission (Settings API does this automatically when registered properly)
- [ ] Output escape on every settings-page field render (`esc_attr()` on `value=""`, `esc_html()` on labels)

---

## Cross-cutting patterns

These apply everywhere PHP runs:

**Sanitize-store, escape-render:**
- `sanitize_text_field( wp_unslash( $_POST['x'] ?? '' ) )` on intake
- `esc_html( $stored_value )` on render
- Never trust `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER` raw

**Prepared queries:**
- `$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}foo WHERE id = %d", absint( $id ) )`
- Never interpolate variables into SQL strings
- `$wpdb->prefix` (not hardcoded `wp_`)

**Capability checks before sensitive operations:**
- `current_user_can( 'capability' )` at the start of every admin handler
- `wp_die( esc_html__( 'You do not have permission.', 'outpost' ) )` on failure

**Rate limiting (when relevant):**
- Transient-backed counter keyed on `get_current_user_id()` for authenticated; IP for anonymous
- `set_transient( "outpost_rl_{$user}_{$endpoint}", $count, MINUTE_IN_SECONDS )`
- Header `Retry-After` on 429

**Logging:**
- Don't log secrets (no tokens in error logs, no `print_r($_POST)` ever)
- WordPress's `error_log` for failures that need investigation
- `_doing_it_wrong()` for developer-facing API misuse

---

## Validation commands

Before any session that touches PHP closes:

```bash
composer lint        # PHPCS (WordPress-Extra)
composer analyze     # PHPStan level 6
composer test        # PHPUnit
```

All three must pass before push.

If a session adds a new surface from the list above, also:

- [ ] Add a corresponding entry to `SECURITY.md` "High-priority surfaces" (already exists; verify it's there)
- [ ] Tick the relevant boxes in this checklist
- [ ] Add at least one integration test exercising the new surface's auth/permission path

---

*Last reviewed: 2026-05-01 (v0.1.4). Update on every PHP-surface-expanding session close.*
