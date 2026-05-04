# Contributing to Outpost

Thanks for your interest. Outpost is a single-maintainer project today, but contributions are welcome — especially around companion plugin adapters, Bridgy network mappings, theme integration tokens, and accessibility fixes.

## Before you start

1. Read [`CLAUDE.md`](CLAUDE.md). It documents the hard contract (plugin owns layout, theme owns paint), the companion strategy, naming conventions, and the forbidden vocabulary list.
2. Read the relevant phase in the build plan. Outpost is being built across ~40 small sessions, each scoped to a specific deliverable. PRs that span phases will be asked to split.
3. Check open issues to avoid duplicating work.

## Development setup

```bash
git clone https://github.com/courtneyr-dev/outpost.git
cd outpost
composer install
npm install
```

## Branching

- `main` — stable, mirrors the most recent published release.
- `feature/*` — short-lived branches for individual sessions or features.
- `fix/*` — short-lived branches for bug fixes.

## Code style

- WordPress PHP + JS Coding Standards (tabs, Yoda conditions, PHPDoc on every public method).
- TypeScript strict mode; no `any` (use `unknown` + type guards).
- ESLint + Prettier auto-format on commit (set up via `npm run format`).
- PHPCS via `composer lint`. Fix with `composer lint:fix`.
- PHPStan must pass at the configured level (`composer analyze`).

## Tests

Every non-trivial change needs tests:

- **Unit tests:** WP_Mock for PHP, Vitest for TypeScript.
- **Integration tests:** WP_UnitTestCase for PHP (touch the database).
- **End-to-end tests:** Playwright for the PWA happy paths.
- **Live tests:** PHP-native HTTP against real third-party APIs (e.g. Spotify oEmbed). Tagged `@group live`, excluded from the default suite, run quarterly via `composer test:live`.

Run `composer test && npm test && npm run test:e2e` before opening a PR. CI does NOT run live tests; failures there indicate the upstream API contract drifted and the relevant `Source_*` adapter or extractor needs updating.

### Source_* fixture pattern (Phase F)

Every `Source_*` adapter ships offline fixtures plus optional live tests. The pattern is locked at:

```
tests/fixtures/sources/{source_id}/{scenario}.{ext}
tests/fixtures/sources/{source_id}/README.md
```

Each source's `README.md` documents capture date, source URL, sanitization applied, and last-verified-live date. Copy `tests/fixtures/sources/_TEMPLATE_README.md` when adding a new source's directory.

Tests load fixtures via `Outpost\Tests\Helpers\SourceFixtureLoader`:

```php
use Outpost\Tests\Helpers\SourceFixtureLoader;
$decoded = SourceFixtureLoader::load_oembed_fixture( 'spotify', 'oembed-track-success' );
```

Tests that exercise HTTP-fetching code paths use `Outpost\Tests\Helpers\MockHttpClient` to register URL → fixture mappings; unmatched URLs throw so tests cannot silently pass against unexpected requests.

### Fixture sanitization checklist

Before committing any fixture under `tests/fixtures/sources/{id}/`:

- [ ] No personal handles, account names, or user IDs
- [ ] No API keys, tokens, or session identifiers
- [ ] No tracking parameters in URLs unless the test scenario explicitly exercises tracking-parameter handling
- [ ] Public durable content (a major artist's hit) OR entirely synthetic (made-up titles using example-data conventions)
- [ ] `composer lint:section5` clean against fixture content

### When to add a new fixture

Pair fixtures with tests. Adding a fixture without a test that consumes it leaves an orphan that rots. Reusing an existing fixture for a scenario it wasn't captured for couples the test to unrelated fixture details. **New scenario → new fixture.**

### Live test cadence

Run `composer test:live` quarterly to catch upstream contract drift. A live test failure means the third-party API changed something Outpost depends on; the relevant `Source_*` adapter (or its extractor) needs updating. Live tests accept an `OUTPOST_TEST_*_URL` env var per source so the maintainer can swap the durable target URL when needed without editing test source.

## Forbidden vocabulary

Per [`CLAUDE.md`](CLAUDE.md), don't use *delve*, *leverage*, *synergy*, *robust*, *seamless*, *ecosystem* (non-tech), *stakeholder*, *bandwidth* (non-tech), *pivot*, *agentic AI*, *AI agents* in commits, comments, copy, or docs. Plain language only.

## §5 audit lint (required CI check)

Outpost is published on WordPress.org and serves any IndieWeb user — never just the author. The `bin/lint/section-5-audit.sh` script runs five checks in CI to enforce this:

- **B1** — case-study handle leakage (specific tokens from research docs)
- **B2** — embedded credential heuristics (API key shapes, AWS keys, GitHub PATs)
- **B3** — hardcoded fediverse instance URLs outside the canonical allowlist
- **B4** — untranslated strings in companion `capabilities()` output
- **B5** — personal data in test fixtures (handles outside the cryptography stock-name allowlist)

### Run locally

```bash
composer lint:section5
# or directly:
bash bin/lint/section-5-audit.sh
# single check:
bash bin/lint/section-5-audit.sh --check B3
```

Runs in under a second. Exits non-zero on any violation, with `file:line:violation` output.

### Configuration files

The lint reads its forbidden patterns and allowlists from sibling config files:

| File | Purpose |
|---|---|
| `bin/lint/case-study-tokens.txt` | B1 forbidden tokens (one ERE regex per line) |
| `bin/lint/credential-patterns.txt` | B2 credential regexes |
| `bin/lint/instance-allowlist.txt` | B3 allowed canonical hostnames |
| `bin/lint/fixture-handle-allowlist.txt` | B5 allowed test-fixture handle names |

Adding a new entry to any list requires a session-log entry in `CLAUDE.md` documenting why.

### Suppression markers

- **B1 research-doc citations.** Lines that contain `concepts/posse-outbound-may-2026.md` or `concepts/capture-inbound-may-2026.md` are exempt — research-doc citations may name handles by reference.
- **B2 fixture credentials.** Tag a line with `/* outpost-lint:fixture-credential */` to exempt it. Use only for test fixtures that intentionally embed fake-but-real-shaped values (AES-GCM key bytes for token-store tests, etc.).

### Adding new fixture-handle names

If your test needs a name not on the cryptography allowlist (alice, bob, charlie, dave, eve, mallory, trent, walter, peggy, victor), prefer one that is. If a domain-appropriate name is genuinely needed, add it to `bin/lint/fixture-handle-allowlist.txt` with a comment explaining why. PRs that add real-looking handles outside the allowlist fail the lint.

### Adding allowed instance hostnames

`bin/lint/instance-allowlist.txt` lists the canonical hostnames that may be hardcoded — RFC 2606 reserved domains, Mastodon's flagship reference, the Bluesky bootstrap PDS, and Bridgy bridge endpoints. Adding a new hostname requires session-log justification (typically a spec / canonical reference being cited).

## Commit messages

- Imperative mood ("Add Mastodon to Bridgy host map", not "Added").
- One concern per commit.
- Reference issues with `Refs #123` or `Fixes #123`.

## Pull requests

- One concern per PR, matching one session's scope when possible.
- Include before/after notes on user-visible changes.
- Mark security-sensitive changes clearly.
- The maintainer will review and may request changes; please don't take it personally.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating you agree to abide by it.

## License

By contributing you agree your contributions are licensed under GPLv2 or later, matching the project license.
