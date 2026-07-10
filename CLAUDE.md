# Outpost

## Project

- **Slug:** outpost
- **Text Domain:** outpost
- **Prefix:** `outpost_` (functions, options); `Outpost_` (classes); `OUTPOST_` (constants)
- **Min WP:** 6.5 | **Tested up to:** 6.9 | **Min PHP:** 8.2
- **Repo:** https://github.com/courtneyr-dev/outpost
- **Tagline:** *Post from your outpost. Reach your people everywhere.*

## What It Does

Mobile-first PWA composer at `/post` (configurable). Five modes: Note, Reply/Like/Repost/Bookmark/RSVP/Follow, Listen/Watch/Read/Checkin/Play, Photo, Article. Optimised for IndieWeb post shapes and life-tracking entries. POSSE-first: every configured syndication destination is on by default per post. iOS-primary, Android-compatible. No Jetpack, no app store, no third-party auth.

Uses Micropub as the API and IndieAuth for browser-side auth. PWA frontend uses Vite + TypeScript + Preact, output to `/build/pwa/`.

## Hard Contract — plugin owns layout, theme owns paint

If anything else in this file contradicts this rule, the rule wins.

### Plugin always owns

- The PWA shell, routes, service worker, manifest, offline behavior.
- Composer interaction: tab structure, syndication chips, voice-input slot, file upload UI.
- The Micropub client implementation.
- IndieAuth flow and encrypted IndexedDB token storage.
- Structural CSS: layout grids, iOS safe-area insets, touch-target sizes, focus states.
- Capability detection for companion plugins.
- CSS custom property *names* and structural defaults.

### Theme always owns

- Every color value.
- Every font choice.
- Spacing scale, border radius, shadow, surface treatment.
- Button fills, accent colors, link colors.
- Whether the PWA inherits the site's block theme typography or sets its own.

### The handshake

Plugin structural CSS only references colors and fonts through `var(--outpost-*, neutral-fallback)`:

```css
.outpost-composer {
	background: var(--outpost-surface-bg, transparent);
	color: var(--outpost-surface-fg, inherit);
}
.outpost-syndication-chip[aria-pressed="true"] {
	background: var(--outpost-chip-active-bg, currentColor);
	color: var(--outpost-chip-active-fg, inherit);
}
```

If a theme sets the variable, the plugin uses it. Otherwise the composer inherits the theme's normal styling. **No part of Outpost ever forces a color the theme didn't ask for.**

One forced default: `padding-bottom: env(safe-area-inset-bottom)` on the iOS bottom toolbar. Structural, not paint.

## Companion Strategy

Detection runs at runtime (not install-time) so the UI updates as users activate plugins. Adapter classes live at `includes/companions/`. Composer code never imports companion-specific code directly.

The dependency chain is **upstream-first**: `[indieauth/indieauth.php, micropub/micropub.php]`. Notice rendering and REST 503 reasons short-circuit on the first unsatisfied entry — installing a downstream dependency before the upstream one is met produces a broken state (Micropub plugin's own preflight fails without IndieAuth).

| Companion | Outpost behavior |
|-----------|------------------|
| `indieauth/indieauth.php` (Pfefferle, Shanske) | **Required (plugin install).** The Micropub plugin hard-requires IndieAuth at its own preflight, so Outpost surfaces IndieAuth status as the most-upstream notice. App passwords are *not* an alternative to installing the IndieAuth plugin — they remain a runtime fallback only when the IndieAuth endpoint is unreachable for an already-installed IndieAuth plugin. Detected by `outpost_indieauth_status()`. |
| `micropub/micropub.php` (David Shanske) | **Required (plugin install).** Hybrid gate: plugin loads, admin notice when any chain entry is absent or inactive, PWA route renders friendly install page, REST routes return 503 with the chain entry name as the reason. Detected by `outpost_micropub_status()`. |
| Post Kinds for IndieWeb | Surface Listen/Watch/Read/Checkin/Play tabs. Surface Follow sub-mode. |
| Post Formats for Block Themes | Format selector in More pull-out. Auto-detect from content. |
| Link Extension for XFN | Relationship picker on reply targets. |
| Syndication Links | Read configured destinations into chip UI. |
| Yoast SEO | Focus keyphrase + meta description fields in More pull-out. |
| ActivityPub / Bridgy | Surface as syndication destinations automatically. |

## Bridgy Auto-Suggest

When the source URL host of a Reply/Like/Repost/Bookmark matches a Bridgy-supported network, the corresponding Bridgy publish URL is added as a syndication chip and enabled by default. Mapping lives at `includes/class-bridgy-detector.php` and is filterable via `outpost_bridgy_host_map`. Per-post and global opt-out toggles.

## Build Commands

```bash
composer install && npm install
npm run dev             # Vite dev server for PWA frontend
npm run build           # Production PWA build to /build/pwa/
composer test           # PHPUnit
composer lint           # PHPCS (WordPress-Extra)
composer analyze        # PHPStan
npm run lint            # ESLint
npm run test:e2e        # Playwright
```

## File Layout

```
outpost.php                 Bootstrap (header, requirements, Micropub gate)
includes/                   PHP classes
includes/companions/        Adapter classes for each companion plugin
admin/                      Settings page, bookmarklet generator, onboarding
pwa/src/                    PWA frontend source (TypeScript + Preact + Vite)
pwa/src/modes/              One file per composer mode
pwa/src/components/         Shared composer UI components
pwa/src/lib/                Micropub client, IndieAuth, token store, offline queue
pwa/src/styles/tokens.css   --outpost-* token defaults
styles/outpost-tokens.css   Server-rendered token defaults
build/                      Compiled PWA assets (gitignored)
assets/icons/               PWA icons at all required sizes
tests/unit/                 WP_Mock unit tests
tests/integration/          WP_UnitTestCase integration tests
tests/e2e/                  Playwright e2e
docs/                       User and integrator docs
docs/security/              CSP, threat-model docs
languages/                  POT and MO files
bin/                        Helper scripts
```

## Standards

- WordPress PHP + JS Coding Standards (tabs, Yoda conditions).
- Security Trinity: sanitize input → validate data → escape output.
- All strings wrapped in `__()` / `_e()` with text domain `outpost`.
- All blocks (when added) use `apiVersion: 3`.
- Escape at render: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- DB queries via `$wpdb->prepare()` only.
- Nonces + `current_user_can()` on every admin and REST action.
- TypeScript strict mode, no `any` (use `unknown` + type guards).
- Token in encrypted IndexedDB, never localStorage or cookies.
- Service worker scope is `/post/` only, never the whole site.

## Testing

Every feature and bugfix lands with tests — failing test first, then the fix. Cover edge cases and failure paths, not just the happy path. Unit (WP_Mock) + integration (wp-env + WireMock) + Playwright e2e where there's a UI.

- **No OR-assertions in defense-in-depth contexts.** An assertion like `assertTrue($a || $b)` weakens both branches — a regression in one is masked by the other passing. Where the architecture is "layer A blocks AND layer B blocks," assert both independently, each with its own failure message. See CI/lint discipline in prior sessions for the concrete before/after (PR #70/#71 review).
- **Auth-gate tests need explicit absence-of-side-effects assertions.** "401 returned" is trivially true for any error path. Assert the protected work didn't fire: no transients written, no hooks fired (`did_action()` returns 0), no state mutated. Without this, a refactor that moves the auth check after the side effect still passes.
- **No self-grading tests.** A test that asserts against its own implementation's output (rather than an independent expectation) isn't a check.
- **CI green is the source of truth, not local runs.** The integration suite was red for a long stretch here while people trusted local PHPUnit — treat a red CI run as authoritative even when local tests pass, and don't report a fix as verified until the CI job that covers it is green.

## Security by default

Sanitize input, validate data, escape output (Security Trinity above). Secrets live in env/config, never in code or commits. Public-facing features get a security review pass before shipping.

Real incidents from this repo's history, so the pattern doesn't regress:
- **Bearer-token-in-URL leak** — the token used to ride in the query string; moved to the JSON body (Micropub-spec compliant) so it stops leaking through access logs, browser history, and CDN cache keys.
- **Filter privilege-escalation** — a hostile `outpost_companion_adapters` filter could once instantiate arbitrary classes; now restricted to `Outpost_*_Adapter`-pattern class names plus a `is_subclass_of` check.
- **Token-store XSS size limit** — the encrypted IndexedDB token store defeats casual DevTools inspection (non-extractable `CryptoKey`) but does **not** defeat same-origin XSS; that's documented honestly rather than oversold, and is why CSP work matters.
- **Cookie + bearer credential collision** — a same-origin PWA calling WordPress REST with `Authorization: Bearer` also carries the `wordpress_logged_in_*` cookie if the user is logged into wp-admin in the same browser. WordPress's cookie-auth path then demands a nonce bearer auth doesn't have, and the request 403s. Fix: `credentials: 'omit'` on every bearer-authenticated fetch. Endpoints that intentionally support cookie fallback keep `credentials: 'include'`.

## Accessibility

WCAG 2.2 AA is the floor, built in from the first component rather than audited in later. Keyboard-only pass (everything reachable, no traps, visible focus) + screen reader spot-check before shipping UI. This repo has already shipped fixes in this vein — drawer focus trap (Tab/Shift-Tab now stays inside the modal) and persistent live-region containers for iOS VoiceOver (conditionally-mounted `role="alert"` / `aria-live` regions get missed by VoiceOver; containers now stay mounted with `hidden` toggling). Extend that pattern rather than re-deriving it per component.

## Release gate — prepare ≠ ship

Never cut a release, tag, or deploy without Courtney's explicit go, even when all machinery is ready.

**Green deploy ≠ deployed.** A green deploy workflow run does not mean the plugin actually updated on the target site. Verify with `wp plugin list` (or `wp @staging` / `wp @live` per the site's wp-cli alias) on the target after every deploy. This repo has hit exactly this failure mode: deploy runs reported green for weeks while the target stayed on a stale version.

**There are TWO deploy repos, one per site** — pushing one is not enough:
- staging → submodule bump in `staging-courtneyr-dev/plugins/outpost`, push to main triggers the GH Action rsync
- live → submodule bump in `courtneyr-dev-site/wp-content/plugins/outpost`, push to main triggers the GH Action rsync

A deploy ships **every** submodule pin in the target repo, not just the one you bumped — bumping Outpost's pin can silently regress an unrelated submodule (e.g. the theme) back to whatever commit that repo has pinned. Check sibling submodule pins against their own repo's main before pushing either deploy repo.

## Commit convention — Emoji-Log

Every commit going forward uses exactly one of these seven prefixes, imperative mood, no others:

| Prefix | Use for |
|---|---|
| `📦 NEW:` | Something entirely new |
| `👌 IMPROVE:` | Enhancement / refactor |
| `🐛 FIX:` | Bug fix |
| `📖 DOC:` | Documentation |
| `🚀 RELEASE:` | New version |
| `🤖 TEST:` | Testing |
| `‼️ BREAKING:` | Breaks previous versions |

## WordPress.org Compliance

Outpost is GPLv2-or-later, free, fully functional. Per [WordPress.org Plugin Guideline §5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#5-trialware-is-not-permitted), every feature in the WordPress.org version must work without payment. There are no:

- License keys
- Trial periods or time limits
- Usage quotas
- Features gated by payment
- Intrusive nag screens

**Adapter behavior corollary (Phase F):** when Outpost integrates with paid companion plugins (Yoast SEO Premium, paid ActivityPub variants), the *integration* may detect the paid plugin's presence and surface its features (focus keyphrase, custom federation routing). But Outpost's *own* functionality (note posting, reply, photo, etc.) MUST work whether or not the user has the paid companion. Detection enables; absence does not disable Outpost.

**Reject contributions that propose:**
- A "Pro" tier of Outpost itself
- Feature flags gated by license keys
- Time-limited trials
- Per-post or per-action quotas
- Adapter logic that disables Outpost features when a free companion is present but a paid one isn't

Informational comparison tables and feature-detection patterns are fine. The Hard Contract above (plugin owns layout, theme owns paint) takes precedence — Outpost's UI must not become an upsell surface for any third-party plugin or service either.

## Security Hot Spots

- **Server-side mf2 preview** (`/wp-json/outpost/v1/preview`) is the SSRF surface. Use `wp_safe_remote_get`, cap response size at 5MB, validate content-type, strip scripts before returning. See Section 8.4 of the original prompt and `docs/security/csp.md`.
- **Required alt text on photos.** Structural, not configurable. Decorative toggle submits `alt=""`.
- **EXIF stripping on upload.** Privacy: prevents location leak from camera roll.
- **Bookmarklet `url` parameter validation** at the composer endpoint: reject non-http(s), reject internal IPs, length-cap at 2048 chars.

## Prior Art

Outpost evolves from prior IndieWeb work for WordPress. Credit these in `readme.txt`, `README.md`, and the bookmarklet settings section.

- **IndieWeb Press This** (Pfefferle, Shanske, Barrett) — bookmarklet pattern for Reply/Like/Repost/RSVP/Follow from any page. Outpost extends and modernizes.
- **Micropub plugin** (David Shanske) — required dependency, our server endpoint.
- **IndieAuth plugin** (Pfefferle, Shanske) — auth.
- **IndieBlocks** (Jan Boddez) — companion for theme blocks.
- **Bridgy** (Ryan Barrett) — round-trip syndication service.

## Forbidden Vocabulary

In commits, code comments, copy, admin labels, and docs: avoid *delve, leverage, synergy, robust, seamless, ecosystem (non-tech sense), stakeholder, bandwidth (non-tech sense), pivot, agentic AI, AI agents*. Use plain language: "uses" not "leverages," "thorough" not "robust," "smooth" not "seamless," "people involved" not "stakeholders."

## Session A1 — Locked Decisions (shipped)

Companion detector foundation. `Outpost_Companion_Detector` is `final`, static-only; `dependency_chain()` is the single source of truth for the IndieAuth → Micropub ordering; IndieAuth is hard-required at the plugin-install level (app passwords are a runtime fallback only). `Outpost_Companion_Base` is abstract; PHPUnit is pinned to `^9.6` for WP_Mock compat; Yoast's file path is `wordpress-seo/wp-seo.php`, not slug-derived. Full decisions: [docs/decisions/session-a1.md](docs/decisions/session-a1.md).

## Session A2 — Locked Decisions (shipped)

PWA routes + shell. Five `/post/*` rewrite rules with `Outpost_Route_Handler::rules()` as the single source of truth; the service worker is extension-less `/post/sw` scoped to `/post/`; `template_redirect` dispatch at priority 1; render methods call `halt()`. **#16: bump `OUTPOST_VERSION` in the same commit as any rewrite-table or deployable behavior change** (pairs with the #11 version-pinned flush guard). #8: WP_Mock 1.x leaks `Filter::$filtersWithAnyArgs` static state — reset it via reflection in `setUp()`. Managed-WP gotchas: nginx short-circuits `.js` URLs, edge caches promote redirects (verify with a cache-buster). Full decisions: [docs/decisions/session-a2.md](docs/decisions/session-a2.md).

## Session A3 — Design Constraints (deferred — Phase B took priority)

Deferred design constraints for structural CSS + tokens: `styles/outpost-tokens.css` is server-rendered at a stable URL (never bundled/hashed); the iOS safe-area padding is the only forced paint default Outpost ships; the service-worker fetch handler waits for Phase D. Full decisions: [docs/decisions/session-a3.md](docs/decisions/session-a3.md).

## Session B0a — Locked Decisions (shipped)

Build pipeline + IndieAuth client + token store. Vite + Preact in manifest mode (`pwa/src/` → `build/pwa/`; PHP reads the manifest — never hard-code a hash); TypeScript strict ceiling incl. `exactOptionalPropertyTypes`; injectable-environment pattern (`IndieAuthEnvironment` / `TokenStoreEnvironment` as optional second argument) for every client library; the token store defeats DevTools inspection but not same-origin XSS; `nocache_headers()` on every PWA HTML response. Full decisions: [docs/decisions/session-b0a.md](docs/decisions/session-b0a.md).

## Session B0b — Locked Decisions (shipped)

Login screen + PHP manifest reader. `Outpost_PWA_Assets` is `final`, static-only, with `override_paths_for_tests()` as its only test seam; `build/pwa/` is committed to git because the deploy rsync reflects the working tree (`npm run build` is part of the deploy ritual); auth-flow orchestration in `pwa/src/lib/auth-flow.ts` keeps state in sessionStorage and discriminates failures via `AuthFlowError` codes. Full decisions: [docs/decisions/session-b0b.md](docs/decisions/session-b0b.md).

## Session B1 — Locked Decisions (shipped)

Micropub client + note form. Per-post endpoint discovery; form-encoded `h=entry` bodies; 201 and 202 both count as success; `MicropubError` codes mirror the `AuthFlowError` pattern; `MicropubEnvironment { fetch }` stays minimal; paint fallbacks use per-property `var(--outpost-*, …)` values — `revert` is only for same-value collapse cases. Full decisions: [docs/decisions/session-b1.md](docs/decisions/session-b1.md).

## Session C0 — Locked Decisions (shipped)

WAI-ARIA tab framework with Note as the first real mode. Roving tabindex, automatic activation, wrap-around arrow keys; all panels render eagerly and toggle via `hidden` so form state survives tab switches; mode files live at `pwa/src/components/modes/*-mode.tsx`; 40 KB gzipped bundle ceiling through Phase C; conditional-spread pattern for optional props under `exactOptionalPropertyTypes`. Full decisions: [docs/decisions/session-c0.md](docs/decisions/session-c0.md).

## Session B2 + C1 — Locked Decisions (shipped)

Server-side mf2 preview + Reply mode. `/wp-json/outpost/v1/preview` ships SSRF defenses (`wp_safe_remote_get`, 5 MB cap, HTML-only content types, 30/min per-user rate limit, script stripping, `show_in_index => false`); permission rides the IndieAuth plugin's REST middleware + `edit_posts`. `post_h_entry()` is the general-purpose Micropub poster (`post_note` wraps it); `PreviewError` codes mirror the response shape. Full decisions: [docs/decisions/session-b2-c1.md](docs/decisions/session-b2-c1.md).

## Session C1b — Locked Decisions (shipped)

Like/Repost/Bookmark join Reply under one tab via a radio picker driven by the `VARIANTS` config table (RSVP/Follow deferred to C1c). Content is required only for Reply; `:has(input:checked)` styles the selected radio label; dynamic `HEntryProperties` shapes need the computed-key + conditional-spread pattern under `exactOptionalPropertyTypes`. Full decisions: [docs/decisions/session-c1b.md](docs/decisions/session-c1b.md).

## Session F1 — Locked Decisions (shipped)

Outbound syndicate-to chips, ActivityPub first. The Shanske Micropub plugin's `micropub_syndicate-to` filter is the single integration point (Outpost never hosts its own `?q=syndicate-to`); chips dedupe by `uid` and existing entries win. Phase F naming convention locked: kebab-case `class-*-adapter.php` files, `Outpost_*_Adapter` class names, tests in `tests/unit/`. Full decisions: [docs/decisions/session-f1.md](docs/decisions/session-f1.md).

## Session F2 — Locked Decisions (shipped)

`capabilities()` reclaimed for the 10-key chip shape; the old slug accessor is renamed `feature_slugs()`. The registry gains `chips_for_mode()` with fail-OPEN mode validation; new `/wp-json/outpost/v1/syndicate-targets?mode=` endpoint; `outpost_companion_capabilities` is the per-companion override filter. WP_Mock gotcha: `onFilter()` needs `withAnyArgs()`, not `with()`. Full decisions: [docs/decisions/session-f2.md](docs/decisions/session-f2.md).

## Session F3 — Locked Decisions (shipped)

Photo alt-text passthrough. The upstream Micropub plugin never writes `_wp_attachment_image_alt`, so `Outpost_Micropub_Bridges::apply_photo_alt_text()` (hooked on `after_micropub`) writes it from both photo shapes (structured `{value, alt}` wins over `mp-photo-alt`); `_wp_attachment_image_alt` is the canonical alt storage; empty alts are persisted, not skipped. Full decisions: [docs/decisions/session-f3.md](docs/decisions/session-f3.md).

## Session F4 — Locked Decisions (shipped)

§5 audit lint `bin/lint/section-5-audit.sh` (sub-checks B1–B5: case-study tokens, credentials, instance hosts, i18n, fixture handles) is a required CI gate; the token/credential/instance/handle lists live in sibling config files under `bin/lint/`; suppressions are per-line and narrow; Phase F adapters carry an 85% per-class coverage floor. Run locally via `composer lint:section5`. Full decisions: [docs/decisions/session-f4.md](docs/decisions/session-f4.md).

## Session F5 — Locked Decisions (shipped)

Inbound source-adapter foundation. `Outpost_Source_Base` mirrors `Outpost_Companion_Base` inverted; the URL matcher is bespoke string comparison (exact host / `*.suffix` wildcard / host+path-prefix) — not regex — for DoS resistance; extractors declare fetch URLs and parsing but never fetch; `Source_Unknown` is the always-last `*` fallback; the B2 preview endpoint gains additive `source_id` dispatch; `outpost_source_capabilities` is the override filter. Full decisions: [docs/decisions/session-f5.md](docs/decisions/session-f5.md).

## Session F6 — Locked Decisions (shipped)

Share-target dispatcher. `Outpost_Source_Detector::dispatch()` is stateless — a 303 redirect with query params is the entire handoff; URL extraction priority is url field → text-as-URL → text-contains-URL → title; `/post/shortcut` accepts JSON for the iOS Shortcut bridge; registration order is match priority (exact hosts register before wildcards); no platform branching in dispatch. Full decisions: [docs/decisions/session-f6.md](docs/decisions/session-f6.md).

## Session F7 — Locked Decisions (shipped)

Source_Spotify, the first concrete inbound source — capabilities()-only, enforced by a reflection test; extraction via Spotify's anonymous oEmbed endpoint (never the OAuth Web API); `u-listen-of` is always set from the shared URL even when oEmbed fails, so every degraded path still yields a working composer. Full decisions: [docs/decisions/session-f7.md](docs/decisions/session-f7.md).

## Session F8 — Locked Decisions (shipped)

Offline fixture pattern locked at `tests/fixtures/sources/{source_id}/{scenario}.{ext}` with per-source READMEs; `SourceFixtureLoader` + `MockHttpClient` live under `Outpost\Tests\Helpers\`; live tests need a class-level `@group live` annotation (in its own docblock) plus `WP_TESTS_LIVE=1`, and skip — not fail — on network failure; adapters stay transparent: no sanitizing, decoding, or truncating in the adapter layer. Full decisions: [docs/decisions/session-f8.md](docs/decisions/session-f8.md).

## Session F9 — Locked Decisions (shipped)

ManualShare umbrella companion: ten declarative platform configs (no per-platform classes) validated strictly at construction by `Platform_Config`; chips surface via `platform_chips()` while its `capabilities()` returns null; `outpost_manual_share_platforms` is the site extension filter; REST routes live under `/wp-json/outpost/v1/manual-share*`. WP_Mock gotcha: `onFilter()->reply(false)` doesn't work — omit the mock to get the default. Full decisions: [docs/decisions/session-f9.md](docs/decisions/session-f9.md).

## Session F10 — Locked Decisions (shipped)

Android intent firing: config-driven payload builder with zero platform-name branches; fallback chain navigator.share → intent:// URL → two-tap, with the clipboard always written first; the audit log lives in `outpost_manual_share_log` post-meta and the PWA patches the outcome via telemetry; per-post `current_user_can('edit_post')` is the second auth layer. WP_Mock gotcha: don't re-register `userFunction` mid-test — register one closure that dispatches on a class property. Full decisions: [docs/decisions/session-f10.md](docs/decisions/session-f10.md).

## Session F11 — Locked Decisions (shipped)

iOS strategy chains: per-platform arrays walked in order (`navigator_share_files` → `app_url_scheme` / `web_intent` → `manual`); `aborted` (user cancel) stops the chain and never falls through; app URL schemes use a 1500 ms visibility-change heuristic; `navigator.share({ files })` on iOS works only inside an installed PWA; the manual fallback modal is real UX, not an error state. Full decisions: [docs/decisions/session-f11.md](docs/decisions/session-f11.md).

## Session F12 — Locked Decisions (shipped)

Silo URL capture closes the manual-share loop: non-blocking prompt (30-day retention, 30-second grace), liberal http(s)-only URL validation with no silo scraping, soft platform-mismatch warning, canonical storage in `outpost_syndication_links` post-meta, and hidden-by-default `u-syndication` markup that mf2 parsers still see. Full decisions: [docs/decisions/session-f12.md](docs/decisions/session-f12.md).

## Session F13 — Locked Decisions (shipped)

Audit-log surfacing: five status states derived on read by `Status_Computer` (never stored); per-entry `reminder_dismissed_until` where a far-future sentinel means abandoned; snooze accepts P1D/P3D/P7D/forever with a per-user rate limit; badges pair glyph + text + aria-label so color is never the only conveyance. Closes the F9–F13 ManualShare arc. Full decisions: [docs/decisions/session-f13.md](docs/decisions/session-f13.md).

## Session F14 — Locked Decisions (shipped)

Bridgy Publish adapter with five silos behind a default-off settings opt-in; `bridgy_url` values must point at brid.gy; F9's reddit/flickr manual chips defer per-platform when the matching Bridgy silo is enabled; webmention response handling ships but the sender stays deferred. Registry-touching tests now need a `get_option` mock. Full decisions: [docs/decisions/session-f14.md](docs/decisions/session-f14.md).

## Session F15 — Locked Decisions (shipped)

Source_YouTube. `matches_url()` overrides are the established escape hatch for path-constrained hosts (F5's pattern syntax can't express `/watch`); YouTube Music routes to Watch mode, never Listen; §5 B5 gotcha — fixtures must use `/channel/UC…` identifiers, never `@handle` URLs. Full decisions: [docs/decisions/session-f15.md](docs/decisions/session-f15.md).

## Session F16 — Locked Decisions (shipped)

Concrete `Extractor_Og_Tags` (regex, not DOMDocument; decodes HTML entities at parse time, unlike the pass-through JSON extractors; walk regex alternation groups by truthiness, never `??`) plus eight OG-tag inbound sources; Amazon affiliate parameters are stripped before recording; Source_Unknown is now end-to-end functional, lifting the F5 #6 mitigation. Full decisions: [docs/decisions/session-f16.md](docs/decisions/session-f16.md).

## Phase F parallel work — FX-iOS-Shortcut (shipped)

iOS Shortcut bridge (parallel session): a per-user long-lived token authenticates ONLY `/wp-json/outpost/v1/shortcut` — presenting it on any other route returns 401, and that scope enforcement is the security boundary; the `rest_authentication_errors` filter must match `REQUEST_URI`, not the unresolved REST route; settings page at Settings → Outpost iOS Shortcut Bridge; tokens never appear in fixtures. Full decisions: [docs/decisions/session-fx-ios-shortcut.md](docs/decisions/session-fx-ios-shortcut.md).

## Phase F parallel work — FY-Theming (shipped)

Theme inheritance + Field Notes treatments (three stacked PRs). Token priority chain override → theme → default with per-token WCAG contrast enforcement; per-user mode and overrides live in user-meta; the B6 lint bans hex literals in `structure.css` — the tokens.css files are the single source of truth, with no fallback hexes in components; `--plain` modifier classes are public opt-out API; stacked-PR lesson: retarget downstream PRs to main before merging/deleting the upstream branch. Full decisions: [docs/decisions/session-fy-theming.md](docs/decisions/session-fy-theming.md).

## Build Order (40 sessions)

Phase A: Foundation (slug, scaffold, companion detector, routes).
Phase B: Auth and Micropub (IndieAuth flow, Micropub client, server-side mf2 preview).
Phase C: Composer modes (Note → Reply → Photo → Listen group → Article → More pull-out).
Phase D: PWA polish (manifest, service worker, offline queue, voice, iOS safe-area).
Phase E: Share-sheet and bookmarklets (Web Share Target, iOS Shortcut, multi-action bookmarklet generator, Bridgy auto-suggest).
Phase F: Companions (Post Kinds, Post Formats, XFN, Yoast, ActivityPub/Bridgy adapters).
Phase G: Security (token hardening, SW review, CSP, file-upload, rate-limit, URL validation, pen test).
Phase H: Settings and onboarding.
Phase I: Distribution (readme.txt, screenshots, banner/icon, submission).
Phase J: Documentation and launch.

Each session is its own conversation, scoped to ~2-3 KB of context. Do not skip ahead.
