---
name: code-review
description: >-
  Review a diff, PR, or file in the Outpost plugin before it merges. Covers the
  WordPress security trinity, the PWA/PHP auth boundary, and the deploy-artifact,
  syndication-target, CSS, and dependency-pin rules that have actually broken
  this repo — plus the CI gates that must be green. Use for "review this",
  "is this safe to ship", "check this diff", or before any release or tag.
---

# Outpost code review

Outpost is a WordPress plugin: PHP backend in `includes/`, a Vite + TypeScript +
Preact PWA in `pwa/src/`. Review both halves, and the seam between them, where
most real bugs live.

**Review order:** run the gates a tool can settle, then read the diff against the
invariants below, then reason about anything left. Never approve on reading
alone — a finding is a claim until something runs.

## 1. Run the gates first

These five jobs gate every merge (`.github/workflows/ci.yml`). Match the versions;
don't trust a local "looks fine" over them.

```bash
bash bin/lint/section-5-audit.sh   # secrets, PII, hardcoded silo URLs, untranslated caps()
composer lint && composer analyze  # PHPCS WordPress-Extra + PHPCompat 8.2-; PHPStan level 6
composer test:unit                 # PHPUnit — CI runs the 8.2/8.3/8.4 matrix
npm run typecheck && npm test && npm run build   # tsc, Vitest, build smoke
npm run test:integration           # wp-env + WireMock; auth-gate + SSRF coverage
```

A red gate is the review. Read its output before writing prose.

## 2. Invariants that have broken here

Check every diff against these. Each one is here because it shipped a bug.

- **Bearer token source.** `Outpost_Bearer_Auth` reads the token from the
  `Authorization` header or the POST body `access_token` only — never the query
  string (logs, referrers, and CDNs leak query strings). GoDaddy strips the
  Authorization header, so the body path is the working one. A new authed
  endpoint that reads a GET query token will fail on header-stripping hosts.
- **`credentials: 'omit'` on same-origin bearer fetches.** On `/post/`, a browser
  also logged into wp-admin otherwise sends the `wordpress_logged_in_*` cookie
  next to the Bearer header; WordPress's cookie path then demands a nonce the
  bearer request can't carry and returns 403. Endpoints send the token in the
  body with `credentials: 'omit'`. Only an endpoint that deliberately accepts a
  cookie fallback keeps `include` — flag any new one that does.
- **Syndication targets must be advertised.** Anything that fills
  `mp-syndicate-to` may send only uids the Micropub endpoint returned from
  `?q=syndicate-to`. Sending a hard-coded `brid.gy/publish/*` uid the server
  never advertised is a `400 Unknown mp-syndicate-to targets`. Resolve suggested
  targets against the discovered list; hide the chip when nothing matches.
- **`build/pwa/` is the deploy artifact.** Staging, live, and the wp.org ZIP ship
  the committed bundle — nothing rebuilds on the target. Any `pwa/src` change must
  rebuild (`npm run build`) and commit `build/pwa/` in the same change, or a fresh
  version ships stale JS. `npm run check:dist` must stay clean.
- **CSS fallbacks use `var(--outpost-*, revert)`** — never `currentColor` or
  `inherit`. Those force a color and break the "plugin owns layout, theme owns
  paint" contract; `revert` lets the theme layer resolve it.
- **PHPUnit stays `^9.6`.** WP_Mock 1.x caps out below PHPUnit 10 (Mockery compat).
  A dependency PR that bumps it past 9.x is a regression, not an update.
- **Banned vendors.** No Automattic, Awesome Motive, or Jetpack dependencies. The
  ActivityPub companion is optional and detection-gated, never a hard dependency.
- **One text domain.** Every user-facing string is translated against the plugin's
  single text domain (see `phpcs.xml`); the Section 5 audit flags untranslated
  `capabilities()` strings.

## 3. Security review — audit the boundaries, not just the sinks

- **Every REST route needs a real `permission_callback`.** The one `__return_true`
  is the OAuth `/callback`, authenticated by a single-use `state` transient keyed
  on its hash. A new `__return_true` anywhere else is a finding.
- **Trinity + nonces + capabilities.** Sanitize on input, escape on output in the
  matching context, `$wpdb->prepare()` for every query. Admin POST paths need both
  a nonce and a `current_user_can()` check — assert the absence of side effects
  (no transient written, no hook fired) on the deny path, not just the status code.
- **SSRF.** The preview fetcher must refuse link-local, CGNAT, and internal IPv6
  targets and re-validate on every redirect. New `wp_remote_*` calls on a
  user-supplied URL get the same treatment.
- **Untrusted HTML** is sanitized with a `wp_kses` allowlist, never a regex
  denylist. `<svg/onload>`, unquoted `href=javascript:`, and `formaction=` must
  not survive.
- **`uninstall.php`** removes every option, per-user credential, post meta,
  transient, and scheduled event the plugin writes — and nothing else.
- **No secrets or PII in the diff.** No tokens, app passwords, real handles, or
  personal data in code, fixtures, or case studies. Credentials live in the OS
  keychain and `~/.ssh/config`, never in a repo file. A Section 5 hit is a stop.

## 4. Prove it, don't reason about it

If the reason to dismiss a finding is "bounded", "can't reach", or "probably
fine", that's an inference about runtime from source — test it. Reproduce the
path on a clean `wp-env` (`npm run wp-env:start`) and fire the actual case.
Runtime behavior outranks a source read. Don't dismiss finding B because it
resembles disproven finding A — test B.

New behavior and bug fixes are test-first: write the failing test, watch it fail
for the right reason, then the minimal code to pass. A test that passed the
moment it was written proves nothing.

## 5. Report

Rank findings most-severe first. For each: the file and line, one sentence on the
defect, and a concrete failure scenario (inputs → wrong result). Separate
**critical** (auth bypass, injection, secret/PII leak, data loss on uninstall)
from **major** (a broken invariant above) from **minor** (style, naming, a
missing test). Mark each claim `observed`, `reproduced`, or `unverified` — never
mix verified and unverified without the label.

**Release gate.** Never cut, tag, or deploy without the maintainer's explicit go.
A version-gated change (rewrite rules, cache-busted assets) bumps the plugin
header `Version` and `OUTPOST_VERSION` together, in the same commit that rebuilds
`build/pwa/`.
