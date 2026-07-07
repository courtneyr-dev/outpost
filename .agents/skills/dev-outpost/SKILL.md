---
name: dev-outpost
description: Development workflow for the Outpost WordPress plugin (mobile PWA composer for IndieWeb POSSE). Use when building, testing, linting, or preparing a release for Outpost, or when deploying to staging/live and verifying the deploy landed.
---

# Outpost development workflow

Outpost is a WordPress plugin: PHP backend (`includes/`) + a PWA frontend (`pwa/src/`, Vite + TypeScript + Preact). Repo: `courtneyr-dev/outpost`.

## Setup

```bash
composer install
npm install
```

Requires PHP >=8.2, Node >=20.10.0, npm >=10.0.0 (`engines` in `package.json`).

## Build

```bash
npm run dev             # Vite dev server for the PWA frontend
npm run build           # Runs bin/check-tokens-parity.mjs, then production build to build/pwa/
```

`build/` is gitignored — the committed state on a deploy target comes from whatever was built and rsynced there, not from CI.

## Test

CI (`.github/workflows/ci.yml`) runs five jobs on every push/PR to `main`. Match these versions locally before trusting a "fixed" claim:

- **Section 5 audit** — `bash bin/lint/section-5-audit.sh` (no PHP/Node setup needed; five checks: case-study handle leakage, embedded-credential heuristics, hardcoded silo URLs, untranslated `capabilities()` strings, personal data in fixtures).
- **PHPCS + PHPStan** — PHP 8.2, `composer lint` (WordPress-Extra + PHPCompatibilityWP ruleset, `phpcs.xml`) and `composer analyze` (PHPStan level 6, `phpstan-wordpress` extension, `phpstan.neon`, `--memory-limit=2G`).
- **PHPUnit** — matrix across PHP 8.2, 8.3, 8.4. CI runs `composer test:unit` only (the integration suite is a separate job, not part of this matrix).
- **TypeScript + Vitest + build smoke** — Node 20. `npx tsc --noEmit`, `npm test` (vitest), `npm run build` (smoke only — doesn't compare output hashes; the committed `build/pwa/` some deploy targets reference is a separate, deliberate artifact).
- **Integration suite** — Node 20 + PHP 8.2. Spins up a WireMock sidecar (`tests/mock-server/docker-compose.yml`) plus `@wordpress/env` (`.wp-env.json`, WordPress 6.7 core + IndieAuth + Micropub companion plugins), then `npm run test:integration` (which runs `wp-env run tests-cli -- composer test:integration` inside the container). On failure the job dumps `wp-env` and WireMock container logs.

Local equivalents:

```bash
composer test              # full PHPUnit suite
composer test:unit          # unit only (matches the CI PHP-matrix job)
composer test:integration   # integration only — needs bootstrap.php with wp-env, see below
composer lint               # PHPCS
composer analyze            # PHPStan
composer check               # lint + section5 + analyze + test
npm test                    # vitest
npm run typecheck           # tsc --noEmit
npm run test:e2e            # Playwright
npm run wp-env:start        # bring up the local WordPress + companion plugins
npm run test:integration    # wp-env run tests-cli ... composer test:integration
npm run wp-env:stop
```

**PHPUnit is pinned to `^9.6` deliberately** (`composer.json` require-dev, confirmed in AGENTS.md Session A1 decision #8) — `10up/wp_mock` 1.x has a Mockery compatibility window that caps out before PHPUnit 10. Do not bump PHPUnit past 9.x without first resolving WP_Mock's compat; a well-meaning dependency update is the most common way this regresses.

**Test invariants to preserve** (see AGENTS.md "Testing" section and Session A2 decision #8):
- WP_Mock has a static-state leak via `Filter::$filtersWithAnyArgs` that `flush()` doesn't clear — `setUp()` resets it via `ReflectionClass`. Any new test file using `WP_Mock::onFilter()->withAnyArgs()` needs the same reset.
- Injectable environment pattern (`IndieAuthEnvironment { fetch, crypto, random }`, `TokenStoreEnvironment { indexedDB, crypto }`) — tests pass deterministic stubs as an optional second argument rather than touching globals. Follow this shape for new environment-dependent code.
- Token-store threat model is documented honestly: it defeats DevTools inspection (non-extractable `CryptoKey`) but not same-origin XSS. Don't overstate it.
- No OR-assertions in defense-in-depth tests; auth-gate tests assert absence of side effects (no transients, no fired hooks), not just the returned status code. See AGENTS.md "Testing".

## Lint

```bash
composer lint       # PHPCS — WordPress-Extra + PHPCompatibilityWP, testVersion 8.2-, text domain locked to "outpost"
composer lint:fix   # PHPCBF autofix
composer analyze    # PHPStan level 6
npm run lint        # ESLint on pwa/src
npm run format:check
```

## Branch / PR conventions

- Branch names follow the existing pattern seen in `git branch -a`: `fix/<slug>`, `phase-g/<slug>`, `docs/<slug>`, `chore/<slug>`.
- Commits use Emoji-Log going forward (see AGENTS.md "Commit convention"): exactly one of `📦 NEW:` `👌 IMPROVE:` `🐛 FIX:` `📖 DOC:` `🚀 RELEASE:` `🤖 TEST:` `‼️ BREAKING:`, imperative mood.
- PRs target `main`. CI must be green (all five jobs) before merge — CI green is the source of truth over local runs.

## Release steps

**There are TWO deploy repos, one per site — this repo (`outpost`) is not deployed directly.** Both are separate git repos that carry Outpost as a submodule:

- **staging** → bump the `outpost` submodule pin in `staging-courtneyr-dev/plugins/outpost`, push to that repo's `main`. A GitHub Action rsyncs to the staging target.
- **live** → bump the `outpost` submodule pin in `courtneyr-dev-site/wp-content/plugins/outpost`, push to that repo's `main`. A GitHub Action rsyncs to the live target.

Bumping only one repo's pin updates only that site. A deploy repo's Action also ships every other submodule's currently-pinned commit, not just the one you bumped — check sibling submodule pins (e.g. the theme) against their own repo's `main` before pushing, or you can silently regress an unrelated submodule.

**Never cut a release, tag, or deploy without Courtney's explicit go**, even when `OUTPOST_VERSION` + `CHANGELOG.md` are ready. Bump the plugin header `Version` and the `OUTPOST_VERSION` constant together — they live two lines apart in `outpost.php` — in the same commit as any change that needs a version-gated flush (rewrite rules, cache-busted assets).

## Deploy verification

**Green deploy ≠ deployed.** A green run of a deploy repo's GitHub Action does not confirm the plugin updated on the target. After every deploy, verify on the actual target:

```bash
wp @staging plugin list   # or: wp plugin list (run against the target site directly)
wp @live plugin list
```

Confirm the `outpost` row shows the version you just shipped. This repo has hit the failure mode where deploy runs reported green for an extended period while the target stayed on a stale version.

## Gotchas

- **Green deploy ≠ deployed** — see above; always verify with `wp plugin list` on the target, not the Action's status.
- **TWO deploy repos** — pushing one site's deploy repo does not update the other.
- **A deploy ships every submodule pin**, not just the one you bumped — check sibling pins before pushing.
- **PHPUnit pinned `^9.6`** for WP_Mock 1.x Mockery compat — don't bump without resolving that first.
- **Banned vendors by policy**: no Automattic products, no Awesome Motive products, no Jetpack. The ActivityPub companion is optional and feature-flagged, kept behind detection rather than a hard dependency, due to ownership concerns.
- **CSS custom-property fallbacks use `var(--outpost-*, revert)`** — never `currentColor` or `inherit` as the fallback. Both of those force a color, which violates the "plugin owns layout, theme owns paint" Hard Contract; `revert` lets the cascade resolve from the theme layer below.
- **Same-origin PWA bearer fetches need `credentials: 'omit'`.** On `/post/`, a logged-into-wp-admin browser also sends the `wordpress_logged_in_*` cookie alongside the `Authorization: Bearer` header; WordPress's cookie-auth path then demands a nonce bearer auth doesn't carry, and the request 403s. Endpoints that intentionally accept cookie fallback (`composer-config.ts`, `preview.ts`) keep `credentials: 'include'`.
- **REST cookie auth needs `_wpnonce`; OAuth callbacks can't carry one.** `/start` and `/disconnect` endpoints append `_wpnonce` via `wp_nonce_url()` and use a capability-based `permission_callback`. The OAuth `/callback` endpoint can't — its `permission_callback` is `__return_true` and a state value looked up in a transient (keyed on the hashed state, not the user) is the actual authentication.
- **`php://input` has no test seam.** `Outpost_Shortcut_Controller::read_json_payload()` reads the raw JSON body via `file_get_contents('php://input')` with no injectable seam, and `php://input` is empty under wp-env's CLI-mode PHPUnit runs. This blocks integration-testing the Shortcut controller's auth gate, parse-error path, and dispatch logic until a production-side seam is added.
