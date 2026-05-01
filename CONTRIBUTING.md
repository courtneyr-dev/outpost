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

Run `composer test && npm test && npm run test:e2e` before opening a PR.

## Forbidden vocabulary

Per [`CLAUDE.md`](CLAUDE.md), don't use *delve*, *leverage*, *synergy*, *robust*, *seamless*, *ecosystem* (non-tech), *stakeholder*, *bandwidth* (non-tech), *pivot*, *agentic AI*, *AI agents* in commits, comments, copy, or docs. Plain language only.

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
