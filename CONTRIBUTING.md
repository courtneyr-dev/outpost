# Contributing to Outpost

This guide covers what you need to contribute code, tests, or docs to Outpost: the tools to install, how to run the plugin and its tests on your machine, what CI checks, and what a pull request needs. Outpost has one maintainer today, and contributions are welcome, especially companion plugin adapters, Bridgy network mappings, theme integration tokens, and accessibility fixes.

AI agents working in this repository also read [`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md), which record the project's locked design decisions. You don't need to read them to contribute; this guide covers the rules a pull request is held to.

## Before you start

1. Check [open issues](https://github.com/courtneyr-dev/outpost/issues) so you don't duplicate work. For anything larger than a fix, open an issue first and describe the change.
2. Keep each pull request to one concern. A PR that mixes a feature, a refactor, and a dependency bump will be asked to split.
3. Read the hard contract below. It decides where code belongs.

### The hard contract: Outpost owns layout, the style source owns paint

Outpost owns the PWA shell, routes, service worker, composer interaction, the Micropub client, IndieAuth sign-in, accessibility, and structural CSS (layout, safe-area insets, touch-target sizes, focus states). Each person picks a style source in the composer's Settings sheet: **Outpost Default** (the token files) or **Use Site Theme** (tokens derived from the active WordPress theme). The style source supplies every color, font, radius, and shadow through `--outpost-*` custom properties. Component CSS never hardcodes a color, and the B6 check below fails CI if it does. The full decision is in [`docs/decisions/session-pwa-ui-appearance.md`](docs/decisions/session-pwa-ui-appearance.md).

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP | 8.2 or later (`composer.json`) | CI tests 8.2, 8.3, and 8.4 |
| Composer | 2 | PHP dependencies, PHPCS, PHPStan, PHPUnit |
| Node.js | 20.10 or later (`package.json` `engines`) | CI uses Node 24 |
| npm | 10 or later | JS dependencies and build scripts |
| Docker | Docker Desktop or Docker Engine | The local WordPress site and the integration suite (`@wordpress/env`) |

## Fork, clone, and install

1. Fork [courtneyr-dev/outpost](https://github.com/courtneyr-dev/outpost) on GitHub.
2. Clone your fork and install dependencies:

```bash
git clone https://github.com/<your-username>/outpost.git
cd outpost
composer install && npm install
```

3. Create a branch for your change: `feature/<short-name>` for features, `fix/<short-name>` for bug fixes. `main` holds the latest release plus merged work.

## Run Outpost locally

`.wp-env.json` defines a local WordPress 6.7 site with Outpost (mapped from your working tree), IndieAuth, and Micropub active.

```bash
npm run wp-env:start    # site at http://localhost:8888 (sign in as admin / password)
npm run wp-env:stop     # stop the containers
npm run wp-env:clean    # reset the databases and containers
```

Open `http://localhost:8888/post/` for the composer. PHP changes show up on the next request. For composer (TypeScript) changes, run `npm run dev` for the Vite dev server or `npm run build` to rebuild `build/pwa/`. For the block-editor sidebar in `src/`, run `npm run build:wp`.

`build/pwa/` and the block-editor files in `build/` are committed, so a clone or ZIP of the repository runs without a build step. If your change touches `pwa/src/` or `src/`, run `npm run build:all` and commit the rebuilt output with it.

## Tests and checks

Every non-trivial change needs tests: write a failing test first, then the fix. Cover failure paths, not only the happy path.

| Command | What it runs |
|---|---|
| `composer test:unit` | PHPUnit unit suite (WP_Mock); what CI runs |
| `composer test` | Every suite in `phpunit.xml.dist` under the unit bootstrap |
| `npm run test:integration` | PHPUnit integration suite inside the wp-env `tests-cli` container (needs `npm run wp-env:start` first) |
| `npm test` | Vitest, for the composer TypeScript |
| `npm run typecheck` | TypeScript strict mode (`tsc --noEmit`) |
| `npm run lint` | ESLint on `pwa/src` |
| `npm run lint:wp` | ESLint on the block-editor sidebar in `src/` |
| `npm run format` | Prettier on `pwa/src`; run it yourself, no hook runs it on commit |
| `composer lint` | PHPCS (WordPress-Extra and PHPCompatibilityWP); `composer lint:fix` fixes what it can |
| `composer analyze` | PHPStan at the configured level, with phpstan-wordpress |
| `composer lint:section5` | The §5 audit, checks B1–B6 (below) |
| `composer check` | `lint`, `lint:section5`, `analyze`, and `test`, in that order |
| `npm run check:dist` | Confirms every runtime directory ships in the packaged ZIP |

Before you open a PR, run at least `composer check`, `npm test`, `npm run typecheck`, `npm run lint`, and `npm run lint:wp`. If you touched anything the integration suite covers, run `npm run test:integration` too.

### Test types

- **Unit tests:** WP_Mock for PHP (`tests/unit/`, `tests/auth/`, `tests/admin/`, `tests/theming/`), Vitest for TypeScript (next to the source in `pwa/src/`).
- **Integration tests:** `tests/integration/`. They extend `PHPUnit\Framework\TestCase` and run against real WordPress in wp-env, with a WireMock sidecar standing in for third-party APIs. See [`docs/INTEGRATION-TESTING.md`](docs/INTEGRATION-TESTING.md) and [`docs/dev/testing-with-mock-server.md`](docs/dev/testing-with-mock-server.md).
- **Live tests:** PHP-native HTTP against real third-party APIs (for example, Spotify oEmbed). Tagged `@group live`, excluded from the default suite, and run with `composer test:live`. CI doesn't run them.
- **Manual UX tests:** [`docs/UX-TESTS.md`](docs/UX-TESTS.md) lists composer cases to run on a test site you control.

There's no end-to-end suite yet. `tests/e2e/` holds only a `.gitkeep`, and the repository has no Playwright configuration, so the `test:e2e` script in `package.json` has nothing to run.

### Source_* fixture pattern

Every `Source_*` adapter ships offline fixtures plus optional live tests. The layout is fixed:

```
tests/fixtures/sources/{source_id}/{scenario}.{ext}
tests/fixtures/sources/{source_id}/README.md
```

Each source's `README.md` records the capture date, source URL, sanitization applied, and last-verified-live date. Copy `tests/fixtures/sources/_TEMPLATE_README.md` when you add a new source's directory.

Tests load fixtures through `Outpost\Tests\Helpers\SourceFixtureLoader`:

```php
use Outpost\Tests\Helpers\SourceFixtureLoader;
$decoded = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-track-success' );
```

Tests that exercise HTTP-fetching code paths use `Outpost\Tests\Helpers\MockHttpClient` to register URL-to-fixture mappings. Unmatched URLs throw, so a test can't pass against a request it didn't expect.

### Fixture sanitization checklist

Before you commit any fixture under `tests/fixtures/sources/{id}/`:

- [ ] No personal handles, account names, or user IDs
- [ ] No API keys, tokens, or session identifiers
- [ ] No tracking parameters in URLs, unless the test exercises tracking-parameter handling
- [ ] Public durable content (a major artist's hit) or entirely synthetic content (made-up titles using example-data conventions)
- [ ] `composer lint:section5` passes against the fixture

### When to add a new fixture

Pair each fixture with a test. A fixture no test reads goes stale unnoticed, and reusing a fixture for a scenario it wasn't captured for ties the test to unrelated details. **New scenario, new fixture.**

### Live test cadence

Run `composer test:live` every quarter to catch upstream API changes. A failing live test means the third-party API changed something Outpost depends on, and the matching `Source_*` adapter or its extractor needs an update. Each live test reads an `OUTPOST_TEST_*_URL` environment variable (for example, `OUTPOST_TEST_SPOTIFY_URL`), so you can swap the target URL without editing the test.

## The §5 audit (required CI check)

Outpost is published for any IndieWeb user, never only its author. `bin/lint/section-5-audit.sh` runs six checks in CI to keep it that way:

- **B1:** case-study handle leakage (specific tokens from research docs)
- **B2:** embedded credential heuristics (API key shapes, AWS keys, GitHub PATs)
- **B3:** hardcoded fediverse instance URLs outside the allowlist
- **B4:** untranslated strings in companion `capabilities()` output
- **B5:** personal data in test fixtures (handles outside the stock-name allowlist)
- **B6:** hex color literals in component CSS (`pwa/src/styles/structure.css`)

### Why B6 matters

The token files (`pwa/src/styles/tokens.css` and `styles/outpost-tokens.css`) are the single source of truth for paint values. When component CSS uses `var(--outpost-foo, #abc123)`, the fallback is still a hex value in a non-token file, and it overrides the selected style source whenever the token isn't set (a service worker cache miss, a stale build, or a theme that leaves the token undefined on purpose). B6 catches both forms:

```css
/* Fails B6: direct hex in component CSS */
.outpost-button { color: #241c4a; }

/* Fails B6: fallback hex inside var() is still hex */
.outpost-button { color: var(--outpost-button-fg, #241c4a); }

/* Passes: token reference, no fallback */
.outpost-button { color: var(--outpost-button-fg); }
```

If a token is missing from `tokens.css`, add it there instead of inlining a fallback hex in the component. B6 scans only `pwa/src/styles/structure.css`; the token files are exempt because they are the source of truth.

### Run it locally

```bash
composer lint:section5
# or directly:
bash bin/lint/section-5-audit.sh
# one check:
bash bin/lint/section-5-audit.sh --check B3
```

It runs in under a second and exits non-zero on any violation, printing `file:line:violation`.

### Configuration files

| File | Purpose |
|---|---|
| `bin/lint/case-study-tokens.txt` | B1 forbidden tokens (one ERE regex per line) |
| `bin/lint/credential-patterns.txt` | B2 credential regexes |
| `bin/lint/instance-allowlist.txt` | B3 allowed canonical hostnames |
| `bin/lint/fixture-handle-allowlist.txt` | B5 allowed test-fixture handle names |

To add an entry to any list, add a comment beside it in the file and explain the reason in your PR description.

### Suppression markers

- **B1 research-doc citations.** Lines that contain `concepts/posse-outbound-may-2026.md` or `concepts/capture-inbound-may-2026.md` are exempt, so research-doc citations may name handles by reference.
- **B2 fixture credentials.** Tag a line with `/* outpost-lint:fixture-credential */` to exempt it. Use it only for test fixtures that embed fake values in a real credential's shape (AES-GCM key bytes for token-store tests, for example).

### Fixture handle names

If your test needs a name, pick one from the cryptography allowlist: alice, bob, charlie, dave, eve, mallory, trent, walter, peggy, victor. If the test needs a domain-specific name, add it to `bin/lint/fixture-handle-allowlist.txt` with a comment explaining why. PRs that add real-looking handles outside the allowlist fail the check.

### Allowed instance hostnames

`bin/lint/instance-allowlist.txt` lists the hostnames code may hardcode: RFC 2606 reserved domains, Mastodon's flagship instance, the Bluesky bootstrap PDS, and Bridgy endpoints. A new hostname needs a cited spec or canonical reference in the PR.

## Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and every pull request against it. Every job must pass before merge ([`docs/dev/ci-gating-policy.md`](docs/dev/ci-gating-policy.md)):

| Job | What it runs |
|---|---|
| Section 5 Audit | `bin/lint/section-5-audit.sh` (B1–B6) |
| PHPCS + PHPStan | `composer lint`, `composer analyze` on PHP 8.2 |
| PHPUnit (PHP 8.2), (PHP 8.3), (PHP 8.4) | `composer test:unit` on each version |
| TypeScript + Vitest + build smoke | `tsc --noEmit`, `npm test`, `npm run lint`, `npm run lint:wp`, `npm run check:dist`, a `build:wp` run that must leave `build/pwa/` untouched, and `npm run build` |
| Integration suite (wp-env + WireMock) | Starts WireMock and wp-env, then `npm run test:integration` |

`.github/workflows/docs.yml` builds and checks the docs site on pull requests that touch it.

## Coding standards

- WordPress PHP and JS coding standards: tabs, Yoda conditions, PHPDoc on every public method. `composer lint` enforces the PHP side.
- Prefixes: `outpost_` for functions, options, and hooks; `Outpost_` for classes; `OUTPOST_` for constants.
- Text domain: `outpost-mobile-publishing` for every translatable string, for example `__( 'Posted', 'outpost-mobile-publishing' )`. PHPCS (`phpcs.xml.dist`) rejects any other domain.
- Sanitize input, validate data, escape output. Use `$wpdb->prepare()` for every query, and nonces plus `current_user_can()` on every admin and REST action.
- TypeScript strict mode. No `any`; use `unknown` plus type guards.
- Kebab-case file names (`class-*.php`, `*-mode.tsx`). Companion adapter classes match `Outpost_*_Adapter`.
- Any change to rewrite rules or query vars bumps the version in the same commit (see [`docs/RELEASING.md`](docs/RELEASING.md)).
- Plain language in code comments, UI copy, and docs. The project avoids *delve*, *leverage*, *synergy*, *robust*, *seamless*, *ecosystem* (outside its technical sense), *stakeholder*, *bandwidth* (outside its technical sense), *pivot*, *agentic AI*, and *AI agents*.

## Manual-share platforms: registering your own

Outpost's manual-share companion registers chips for platforms Outpost can't post to directly: Instagram, Facebook, X, LinkedIn, Threads, TikTok, Pinterest, Reddit, and Flickr. Site owners can register more (VSCO, Glass, 500px, regional networks) without forking Outpost, through the `outpost_manual_share_platforms` filter.

```php
add_filter(
    'outpost_manual_share_platforms',
    function ( array $platforms ): array {
        $platforms[] = array(
            'id'             => 'glass-photo',
            'label'          => __( 'Glass', 'my-plugin' ),
            'icon'           => 'glass',
            'accepts_modes'  => array( 'photo', 'gallery' ),
            'accepts_media'  => array( 'image' ),
            'caption_via'    => 'clipboard',
            'ios_strategy'   => 'navigator_share_files',
            'android_action' => 'android.intent.action.SEND',
            'android_pkg'    => 'com.glass.photo',
            'android_mime'   => 'image/*',
            'android_extras' => array( 'EXTRA_STREAM' => '@image_uri' ),
            'after_share'    => 'prompt_for_silo_url',
            'caveats'        => array(
                __( 'Caption is copied to clipboard; paste in app.', 'my-plugin' ),
            ),
        );
        return $platforms;
    }
);
```

Required keys: `id` (unique kebab-case slug), `label` (translated string), `icon` (icon ID), `accepts_modes` (non-empty array of composer modes the platform accepts), `caption_via` (`'intent'`, `'clipboard'`, or `'web_intent'`), and `after_share` (`'mark_done'`, `'prompt_for_silo_url'`, or `'silent'`).

Optional keys default to safe values: `accepts_media`, `ios_strategy`, `ios_url`, `android_action`, `android_pkg`, `android_mime`, `android_extras`, `web_intent_url`, `caveats`, and `prefers_bridgy`.

The placeholders `@image_uri`, `@caption`, `@caption_encoded`, and `@source_url` resolve when the share fires. Set `prefers_bridgy => true` to hide your platform's chip when Bridgy Publish is configured.

A malformed config throws `Outpost_Manual_Share_Invalid_Config_Exception` when the filter resolves, not when the chip renders. The error names the missing or invalid key, so a misconfiguration shows up at boot.

To remove a default platform, filter the array and drop the entry whose `id` matches:

```php
add_filter(
    'outpost_manual_share_platforms',
    fn ( array $platforms ): array => array_values(
        array_filter( $platforms, fn ( array $p ) => 'tiktok' !== $p['id'] )
    )
);
```

## Field Notes treatments: opting out

The composer's Field Notes treatment layer gives the composer its look: display-serif headings with a drawn underline, display-italic tab labels, boxed input fields, native radios tinted with the indicator color, a flat primary button with a display-serif label, an SVG microphone icon, and a halftone surface. It applies only under one of three mode classes on the root, `.outpost-mode-day`, `.outpost-mode-night`, or `.outpost-mode-system`, which `Outpost_Mode_Controller` sets from each person's preference. The classes never appear outside the composer.

If you place your own block, widget, or callout inside the composer and want it to keep its own look, add a `--plain` modifier class to that element. The opt-out is public API that site owners and theme authors can rely on across versions.

| Element | Class that opts it out |
|---|---|
| Tab | `outpost-tab--plain` |
| Heading (h1, h2) | `outpost-heading--plain` |
| Text input | `outpost-input--plain` |
| Textarea | `outpost-textarea--plain` |
| Radio button | `outpost-radio--plain` |
| Primary button | `outpost-button--plain` |
| Voice mic icon | `outpost-voice-button--plain` |

```html
<!-- A third-party block's heading inside the composer keeps its own typography -->
<h2 class="my-block-title outpost-heading--plain">Custom block heading</h2>

<!-- An error callout's button stays a regular button -->
<button class="outpost-button outpost-button--plain my-error-retry">Retry</button>
```

The treatment rules use `:not(.outpost-foo--plain)`, so the class opts out only the element that carries it, not its siblings. Paint tokens (background, text color, border) still apply, so the element keeps the active style source's colors; only the treatment (display typeface, italics, the heading underline, the boxed field, the tinted radio, the SVG icon) drops away.

## Commits

- Imperative mood: "Add Mastodon to Bridgy host map", not "Added".
- One concern per commit.
- Reference issues with `Refs #123` or `Fixes #123`.
- The project prefixes commit subjects with one Emoji-Log tag: `📦 NEW:`, `👌 IMPROVE:`, `🐛 FIX:`, `📖 DOC:`, `🚀 RELEASE:`, `🤖 TEST:`, or `‼️ BREAKING:`. The maintainer can add it when merging if you leave it off.

## Pull requests

- Fill in [`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md), including its Documentation checklist.
- Describe user-visible changes with before and after notes or screenshots.
- Mark security-sensitive changes in the PR description. Report vulnerabilities privately instead of in a PR; see [`SECURITY.md`](SECURITY.md).
- Every CI job must pass. The maintainer reviews every PR and may ask for changes.

## Documentation

- **User docs** live in `docs/src/content/docs/` and build with Astro Starlight to <https://courtneyr-dev.github.io/outpost/>. [`docs/MAINTAINING.md`](docs/MAINTAINING.md) covers editing, previewing (`cd docs && npm ci && npm run dev`), and the checks CI runs.
- **Developer notes** (adapter specs, decisions, test guides, checklists) live in the rest of `docs/`. They aren't published.
- If your change alters what a person sees or does, update the matching user docs page in the same PR.

## Releasing

Only the maintainer tags and publishes releases. [`docs/RELEASING.md`](docs/RELEASING.md) lists every step: the version bump in each file, both changelogs, the checks, the build, the tag, the ZIP, and the GitHub release.

## Code of conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to abide by it.

## License

By contributing, you agree to license your contributions under GPLv2 or later, the project's license.
